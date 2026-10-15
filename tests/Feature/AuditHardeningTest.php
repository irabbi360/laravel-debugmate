<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Irabbi360\LaravelDebugMate\Http\Middleware\TrackAnalytics;
use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ContextCollector;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Support\Sanitizer;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;
use RuntimeException;

class AuditHardeningTest extends TestCase
{
    protected int $analyticsReports = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('debugmate.track_analytics', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyticsReports = 0;
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportAnalytics')->andReturnUsing(function () {
            $this->analyticsReports++;

            return true;
        });
        $this->app->instance(ApiClient::class, $apiClient);

        Route::get('/page', fn () => 'ok');
        Route::post('/page', fn () => 'ok');
    }

    protected function tearDown(): void
    {
        TrackAnalytics::resolveConsentUsing(null);

        // Count Mockery expectations (e.g. ->once()) as assertions.
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();

        parent::tearDown();
    }

    // --- 6. analytics abuse limits ----------------------------------------

    public function test_only_html_get_page_loads_are_tracked(): void
    {
        $this->get('/page')->assertOk();
        $tracked = $this->analyticsReports;
        $this->assertGreaterThan(0, $tracked);

        $this->postJson('/page')->assertOk();
        $this->getJson('/page')->assertOk();
        $this->get('/page', ['Sec-Purpose' => 'prefetch'])->assertOk();

        $this->assertSame($tracked, $this->analyticsReports);
    }

    public function test_new_sessions_are_rate_limited_per_ip(): void
    {
        config(['debugmate.analytics.max_new_sessions_per_minute' => 2]);

        foreach (range(1, 5) as $i) {
            $this->get('/page')->assertOk();
        }

        $sessions = array_filter(
            array_keys((fn () => $this->storage)->call(Cache::store()->getStore())),
            fn ($key) => str_starts_with($key, 'debugmate_session_')
        );

        $this->assertCount(2, $sessions);
    }

    public function test_sample_rate_applies_to_new_sessions(): void
    {
        config(['debugmate.analytics.sample_rate' => 0.0]);

        $this->get('/page')->assertOk()->assertCookieMissing('debugmate_sid');
        $this->assertSame(0, $this->analyticsReports);
    }

    // --- 7. consent and personal data ---------------------------------------

    public function test_consent_cookie_gates_tracking(): void
    {
        config(['debugmate.analytics.consent_cookie' => 'analytics_consent']);

        $this->get('/page')->assertOk()->assertCookieMissing('debugmate_sid');
        $this->assertSame(0, $this->analyticsReports);

        $this->withUnencryptedCookie('analytics_consent', '1')->get('/page')->assertCookie('debugmate_sid', null, false);
        $this->assertGreaterThan(0, $this->analyticsReports);
    }

    public function test_consent_resolver_overrides_the_cookie(): void
    {
        TrackAnalytics::resolveConsentUsing(fn (Request $request) => false);

        $this->get('/page')->assertOk()->assertCookieMissing('debugmate_sid');
        $this->assertSame(0, $this->analyticsReports);
    }

    public function test_user_name_and_email_are_not_sent_by_default(): void
    {
        $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']));

        $user = $this->app->make(ContextCollector::class)->collect(request())['context']['user'];

        $this->assertSame(7, $user['id']);
        $this->assertArrayNotHasKey('email', $user);
        $this->assertArrayNotHasKey('name', $user);
    }

    public function test_client_ips_are_anonymized_including_proxy_headers(): void
    {
        $s = Sanitizer::instance();

        $this->assertSame('203.0.113.0', $s->sanitizeIp('203.0.113.77'));
        $this->assertSame([
            'x-forwarded-for' => '203.0.113.0, 10.0.0.0',
            'forwarded' => 'for=198.51.100.0;proto=https',
            'accept' => 'text/html',
        ], $s->sanitizeIpHeaders([
            'x-forwarded-for' => '203.0.113.77, 10.0.0.5',
            'forwarded' => 'for=198.51.100.23;proto=https',
            'accept' => 'text/html',
        ]));

        config(['debugmate.anonymize_ips' => false]);
        $this->assertSame('203.0.113.77', $s->sanitizeIp('203.0.113.77'));
    }

    // --- 8. availability ------------------------------------------------------

    public function test_circuit_breaker_opens_after_a_connection_failure(): void
    {
        $client = new ApiClient('http://127.0.0.1:1', 'dm_test_key');

        $this->assertFalse($client->reportError(['message' => 'x']));
        $this->assertGreaterThan(microtime(true), (float) Cache::get('debugmate:api_circuit_open_until'));

        // While open, sends fail fast without touching the network.
        $started = microtime(true);
        $this->assertFalse($client->reportError(['message' => 'y']));
        $this->assertLessThan(0.05, microtime(true) - $started);
    }

    public function test_missing_api_url_refuses_to_send(): void
    {
        $this->assertFalse((new ApiClient('', 'dm_test_key'))->reportError(['message' => 'x']));
    }

    // --- 10. SQL literals -----------------------------------------------------

    public function test_string_literals_in_raw_sql_are_masked(): void
    {
        $sql = Sanitizer::instance()->sanitizeQuery("select * from users where email = 'a@b.c' and name = 'O''Brien' and id = 5");

        $this->assertSame("select * from users where email = '***' and name = '***' and id = 5", $sql);
    }

    // --- 11. failed_jobs retention -------------------------------------------

    public function test_report_jobs_retry_but_do_not_fail_into_failed_jobs(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')->andReturn(false);

        // Attempts remain: throw so the queue retries.
        config(['debugmate.job_retries' => 3]);
        try {
            (new ReportErrorJob(['message' => 'x']))->handle($apiClient);
            $this->fail('Expected a retryable exception');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        // Last attempt: give up quietly (no failed_jobs row with the payload).
        config(['debugmate.job_retries' => 1]);
        (new ReportErrorJob(['message' => 'x']))->handle($apiClient);
        $this->assertTrue(true);
    }

    // --- 12. streamFromFile ---------------------------------------------------

    public function test_stream_from_file_only_reads_storage_logs(): void
    {
        config(['debugmate.log_channels' => []]);

        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportLog')->once()->andReturn(true);
        $streamer = new LogStreamer($apiClient, array_merge(config('debugmate'), ['async_reporting' => false]));

        // Outside storage/logs: refused (no report).
        $streamer->streamFromFile(base_path('composer.json'));

        File::ensureDirectoryExists(storage_path('logs'));
        $log = storage_path('logs/debugmate-test.log');
        File::put($log, "[2026-10-15 10:00:00] local.ERROR: inside logs\n");

        try {
            $streamer->streamFromFile($log);
        } finally {
            File::delete($log);
        }
    }
}

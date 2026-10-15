<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;
use Irabbi360\LaravelDebugMate\Support\SafeTrace;
use Irabbi360\LaravelDebugMate\Support\Sanitizer;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Irabbi360\LaravelDebugMate\Tracing\Span;
use Mockery;
use RuntimeException;

class SecretLeakageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_token_like_path_segments_are_masked_but_ids_and_slugs_are_kept(): void
    {
        $s = Sanitizer::instance();

        // Laravel password reset token (64 hex chars)
        $this->assertSame(
            'https://app.test/reset-password/***',
            $s->sanitizeUrl('https://app.test/reset-password/'.str_repeat('a1b2', 16))
        );
        // Slack / Discord webhooks, Telegram bot token
        $this->assertSame(
            'https://hooks.slack.com/services/T0000000/B0000000/***',
            $s->sanitizeUrl('https://hooks.slack.com/services/T0000000/B0000000/XxYyZz1234567890AbCdEfGh')
        );
        $this->assertSame(
            'https://api.telegram.org/***/sendMessage',
            $s->sanitizeUrl('https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage')
        );

        // Ordinary identifiers survive
        foreach ([
            'https://app.test/posts/how-to-install-laravel-12-on-ubuntu',
            'https://app.test/users/9b2f3c1e-8f0a-4c6e-9d2b-1a2b3c4d5e6f',
            'https://app.test/orders/01HZX3K9Q7ZB6Y2W8V4T5R1N0M',
            'https://app.test/invoices/1234567',
        ] as $url) {
            $this->assertSame($url, $s->sanitizeUrl($url));
        }
    }

    public function test_request_urls_use_placeholders_for_sensitive_route_parameters(): void
    {
        Route::get('/reset-password/{token}', fn () => Sanitizer::instance()->sanitizeRequestUrl(request()));
        Route::get('/email/verify/{id}/{hash}', fn () => Sanitizer::instance()->sanitizeRequestUrl(request(), false));

        $this->get('/reset-password/short-but-secret?email=a%40b.c')
            ->assertOk()
            ->assertSee('/reset-password/{token}?email=a%40b.c', false)
            ->assertDontSee('short-but-secret', false);

        $this->get('/email/verify/42/deadbeef')
            ->assertOk()
            ->assertSee('/email/verify/42/{hash}', false)
            ->assertDontSee('deadbeef', false);
    }

    public function test_safe_trace_and_spans_never_contain_argument_values(): void
    {
        $exception = (fn (string $plainPassword) => new RuntimeException('boom'))('hunter2-secret-password');

        $this->assertStringNotContainsString('hunter2', SafeTrace::asString($exception));

        $span = new Span(str_repeat('a', 32), 'job');
        $span->recordException(new RuntimeException('Client error: `GET https://api.x.test/v1?api_key=sk_live_abcdefghijklmnop` resulted in 401'));
        $attributes = $span->toArray()['attributes'];

        $this->assertStringNotContainsString('sk_live_abcdefghijklmnop', $attributes['exception.message']);
        $this->assertStringNotContainsString("('", $attributes['exception.stacktrace']);
    }

    public function test_messages_are_scrubbed(): void
    {
        $s = Sanitizer::instance();

        $message = $s->sanitizeMessage(
            'Client error: `POST https://api.x.test/charge?api_key=abc123&page=2` resulted in 401; '
            .'password=hunter2 Authorization: Bearer eyJabc.def.ghi'
        );

        $this->assertStringNotContainsString('abc123', $message);
        $this->assertStringNotContainsString('hunter2', $message);
        $this->assertStringNotContainsString('eyJabc', $message);
        $this->assertStringContainsString('page=2', $message);
        $this->assertSame('Invalid token provided', $s->sanitizeMessage('Invalid token provided'));
    }

    public function test_database_driver_messages_do_not_leak_values(): void
    {
        $s = Sanitizer::instance();

        $mysql = $s->sanitizeDatabaseMessage("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'victim@example.com' for key 'users_email_unique'");
        $pgsql = $s->sanitizeDatabaseMessage('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique" DETAIL: Key (email)=(victim@example.com) already exists.');

        $this->assertStringNotContainsString('victim@example.com', $mysql);
        $this->assertStringNotContainsString('victim@example.com', $pgsql);
        $this->assertStringContainsString('Duplicate entry', $mysql);
        $this->assertStringContainsString('Key (email)=(***)', $pgsql);
    }

    public function test_error_reports_scrub_query_exception_driver_messages(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')->once()->andReturnUsing(function (array $payload): bool {
            $this->assertStringNotContainsString('victim@example.com', $payload['message']);
            $this->assertStringContainsString('insert into users (email) values (?)', $payload['message']);

            return true;
        });

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(ErrorTracker::class);

        $this->app->make(ErrorTracker::class)->reportError(new QueryException(
            'mysql',
            'insert into users (email) values (?)',
            ['victim@example.com'],
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'victim@example.com' for key 'users_email_unique'")
        ));
    }

    public function test_api_client_never_follows_redirects(): void
    {
        $client = new ApiClient('https://debugmate.test', 'dm_test_key');

        $guzzle = (fn () => $this->client)->call($client);

        $this->assertFalse($guzzle->getConfig('allow_redirects'));
    }

    public function test_analytics_never_stores_or_sends_the_project_key(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportAnalytics')->andReturnUsing(function (array $payload): bool {
            $this->assertArrayNotHasKey('project_key', $payload);
            $this->assertStringNotContainsString('dm_test_key', (string) json_encode($payload));

            return true;
        });

        $analytics = new RequestAnalytics($apiClient, array_merge(config('debugmate'), ['async_reporting' => false]));
        $sessionId = $analytics->startSession(['user_agent' => 'Mozilla/5.0', 'ip_address' => '203.0.113.0']);
        $analytics->trackPageView(['url' => 'https://app.test/']);

        $this->assertArrayNotHasKey('project_key', Cache::get("debugmate_session_{$sessionId}"));

        // Sessions cached by older versions are scrubbed when resumed.
        Cache::put('debugmate_session_legacy', ['session_id' => 'legacy', 'project_key' => 'dm_test_key']);
        $legacy = new RequestAnalytics($apiClient, config('debugmate'));
        $this->assertTrue($legacy->resumeSession('legacy'));
        $legacy->trackPageView(['url' => 'https://app.test/']);
    }
}

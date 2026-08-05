<?php

namespace Irabbi360\LaravelDebugMate\Console;

use Illuminate\Console\Command;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class VerifyCommand extends Command
{
    protected $signature = 'debugmate:verify';

    protected $description = 'Verify DebugMate SDK configuration and API connectivity';

    public function handle(ApiClient $apiClient): int
    {
        if (! config('debugmate.enabled')) {
            $this->components->warn('DebugMate is disabled. Set DEBUGMATE_ENABLED=true to enable tracking.');

            return self::FAILURE;
        }

        $projectKey = config('debugmate.debugmate_key');

        if (empty($projectKey)) {
            $this->components->error('DEBUGMATE_PROJECT_KEY is not configured.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('API URL', (string) config('debugmate.api_url'));
        $this->components->twoColumnDetail('Project key', $this->maskKey($projectKey));

        $this->components->info('Verifying API connection...');

        if (! $apiClient->verifyConnection()) {
            $this->components->error('Connection failed. Check DEBUGMATE_API_URL and DEBUGMATE_PROJECT_KEY.');

            return self::FAILURE;
        }

        $this->components->info('Connection verified.');

        $project = $apiClient->getProjectInfo();

        if (is_array($project)) {
            $name = $project['name'] ?? $project['data']['name'] ?? null;

            if ($name) {
                $this->components->twoColumnDetail('Project', (string) $name);
            }
        }

        return self::SUCCESS;
    }

    protected function maskKey(string $key): string
    {
        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 4).str_repeat('*', max(strlen($key) - 8, 4)).substr($key, -4);
    }
}

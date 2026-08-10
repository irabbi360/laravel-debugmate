<?php

namespace Irabbi360\LaravelDebugMate\Console;

use Illuminate\Console\Command;
use Irabbi360\LaravelDebugMate\Services\MonitorRegistrar;

class RegisterMonitorsCommand extends Command
{
    protected $signature = 'debugmate:register-monitors {--sync : Send immediately instead of queueing}';

    protected $description = 'Register uptime / SSL / domain monitor targets with DebugMate';

    public function handle(MonitorRegistrar $registrar): int
    {
        if (! config('debugmate.enabled')) {
            $this->components->warn('DebugMate is disabled.');

            return self::FAILURE;
        }

        if (! config('debugmate.track_monitors')) {
            $this->components->warn('Monitor tracking is disabled. Set DEBUGMATE_TRACK_MONITORS=true.');

            return self::FAILURE;
        }

        $monitors = $registrar->buildMonitors();

        if ($monitors === []) {
            $this->components->error('No monitor URLs found. Set DEBUGMATE_MONITOR_URLS or APP_URL.');

            return self::FAILURE;
        }

        foreach ($monitors as $monitor) {
            $this->components->twoColumnDetail($monitor['url'], 'uptime/ssl/domain');
        }

        $async = ! $this->option('sync') && (bool) config('debugmate.async_reporting', false);

        if (! $registrar->sync(async: $async)) {
            $this->components->error('Failed to register monitors.');

            return self::FAILURE;
        }

        $this->components->info($async ? 'Monitor registration queued.' : 'Monitors registered.');

        return self::SUCCESS;
    }
}

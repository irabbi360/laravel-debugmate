<?php

namespace Irabbi360\LaravelDebugMate\View;

use Illuminate\Contracts\View\Engine;
use Irabbi360\LaravelDebugMate\Collectors\ViewCollector;

class TracingViewEngine implements Engine
{
    public function __construct(
        protected Engine $engine,
        protected ViewCollector $collector,
    ) {}

    public function get($path, array $data = [])
    {
        try {
            return $this->engine->get($path, $data);
        } finally {
            $this->collector->endCurrentViewSpan();
        }
    }
}

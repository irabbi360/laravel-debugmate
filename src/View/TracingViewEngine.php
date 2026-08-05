<?php

namespace Irabbi360\LaravelDebugMate\View;

use Illuminate\Contracts\View\Engine;
use Irabbi360\LaravelDebugMate\Collectors\ViewCollector;

/**
 * Decorator around a view engine that ends view.render spans after render.
 *
 * Proxies CompilerEngine methods (getCompiler, forgetCompiledOrNotExpired, …)
 * so Laravel exception rendering / Vite error pages keep working.
 */
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

    /**
     * Required by Laravel BladeMapper when mapping exception frames to Blade sources.
     */
    public function getCompiler()
    {
        if (! method_exists($this->engine, 'getCompiler')) {
            throw new \BadMethodCallException(
                sprintf('%s::getCompiler() is not supported by underlying engine %s', static::class, $this->engine::class)
            );
        }

        return $this->engine->getCompiler();
    }

    /**
     * Used by Laravel to clear compiled view cache after exceptions.
     */
    public function forgetCompiledOrNotExpired(): void
    {
        if (method_exists($this->engine, 'forgetCompiledOrNotExpired')) {
            $this->engine->forgetCompiledOrNotExpired();
        }
    }

    /**
     * Forward any other CompilerEngine / PhpEngine methods to the wrapped engine.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->engine->{$method}(...$parameters);
    }
}

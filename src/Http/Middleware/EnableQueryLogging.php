<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnableQueryLogging
{
    /**
     * Enable query logging at the start of each request
     * This captures ALL queries executed during the request
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Enable query logging for this request
        DB::enableQueryLog();

        return $next($request);
    }
}


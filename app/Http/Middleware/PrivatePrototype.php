<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivatePrototype
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('id');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }
}

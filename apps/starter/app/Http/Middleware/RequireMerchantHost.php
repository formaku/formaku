<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMerchantHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = config('merchant.host');
        if ($host !== null) {
            abort_unless(strtolower($request->headers->get('host', '')) === $host, 421);
        }

        return $next($request);
    }
}

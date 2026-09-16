<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The engine calling the portal, which is the one direction that did not
 * exist before.
 *
 * A different secret from ENGINE_ADMIN_TOKEN on purpose. A secret that
 * authenticates one direction should not authenticate the other, or
 * compromising the engine would hand over the portal with it.
 */
class EnsureEngineToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('app.portal_internal_token');

        if (!$expected) {
            abort(503, 'PORTAL_INTERNAL_TOKEN is not set on the portal.');
        }

        if (!hash_equals($expected, (string) $request->header('X-Portal-Token'))) {
            abort(401, 'Invalid portal token.');
        }

        return $next($request);
    }
}

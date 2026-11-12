<?php

namespace Headquery\Statamic\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The schema endpoint answers only with a valid dedicated bearer token.
 * Without a configured token it behaves as if it did not exist (404).
 */
class AuthorizeSchemaRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('headquery.schema.token', '');

        if ($token === '') {
            abort(404);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($token, $given)) {
            abort(401);
        }

        return $next($request);
    }
}

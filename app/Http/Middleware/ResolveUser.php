<?php

namespace App\Http\Middleware;

use App\Services\UserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Auth is out of scope: the authenticated user id is supplied by the X-User-Id header. */
class ResolveUser
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-User-Id');

        if (! is_string($id) || ! ctype_digit($id) || $this->users->find((int) $id) === null) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Missing or unknown user.']], 401);
        }

        $request->attributes->set('user_id', (int) $id);

        return $next($request);
    }
}

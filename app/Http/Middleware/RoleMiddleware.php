<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $allowedRoles = collect($roles)
            ->flatMap(static function (string $segment): array {
                $parts = preg_split('/\s*[,|]\s*/', $segment);

                return $parts === false ? [] : $parts;
            })
            ->map(static fn (string $r): string => trim($r))
            ->filter()
            ->values()
            ->all();

        if (! $request->user()->hasAnyRole($allowedRoles)) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden. Insufficient permissions.'
            ], 403);
        }

        return $next($request);
    }
}

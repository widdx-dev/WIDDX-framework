<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Redirect guests to /login — or 401 JSON for API/JSON clients.
 *
 * Usage:
 *   get('/dashboard', [DashboardController::class, 'index'])->middleware(\Widdx\AuthMiddleware::class);
 *   Router::use(\Widdx\AuthMiddleware::class); // protect everything
 */
class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::guest()) {
            if ($request->expectsJson()) {
                return Response::json(['error' => 'Unauthenticated.'], 401);
            }
            return redirect('/login');
        }
        return $next($request);
    }
}

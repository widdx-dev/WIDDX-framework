<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Require a signed-in user to have a verified email address.
 *
 * Guests are redirected to /login (or receive 401 JSON); authenticated users
 * whose model has an email_verified_at column but no verification timestamp
 * are redirected to /email-verification (or receive 403 JSON).
 *
 * If the model has no email verification column, verification is not enabled
 * for that app and authenticated users pass through.
 *
 * Usage:
 *   get('/billing', $handler)
 *       ->middleware(\Widdx\AuthMiddleware::class)
 *       ->middleware(\Widdx\VerifiedMiddleware::class);
 *
 * Do not attach this middleware to /email-verification or /verify-email routes,
 * or users would be unable to complete the verification flow.
 */
class VerifiedMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();
        if ($user === null) {
            if ($request->expectsJson()) {
                return Response::json(['error' => 'Unauthenticated.'], 401);
            }
            return redirect('/login');
        }

        if (!Auth::verified($user)) {
            if ($request->expectsJson()) {
                return Response::json(['error' => 'Email verification required.'], 403);
            }
            return redirect('/email-verification');
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

use Widdx\Request;
use Widdx\Response;

/**
 * Example middleware.
 * Usage: get('/admin', ...)->middleware(AuthMiddleware::class);
 */
class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        // Example: dummy check — replace with your own logic
        // if (!isset($_SESSION['user'])) { return redirect('/'); }
        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Project auth middleware — REAL behavior since v0.3: it simply extends
 * the framework's \Widdx\AuthMiddleware, so guests get 302 to /login (or
 * 401 JSON for API callers), never a silent pass-through.
 *
 * Usage: get('/admin', ...)->middleware(App\Middleware\AuthMiddleware::class);
 * Need custom rules? Override handle() and call parent::handle() first.
 */
class AuthMiddleware extends \Widdx\AuthMiddleware
{
}

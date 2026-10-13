<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Auth contract — any auth driver must implement this.
 *
 * Example drivers: SessionAuth, TokenAuth, JwtAuth
 */
interface AuthInterface
{
    /** Attempt login with credentials */
    public function attempt(array $credentials): bool;

    /** Log in a user */
    public function login(mixed $user): void;

    /** Log out */
    public function logout(): void;

    /** Is anyone logged in? */
    public function check(): bool;

    /** Is guest? */
    public function guest(): bool;

    /** Current user ID */
    public function id(): mixed;

    /** Current user */
    public function user(): mixed;
}

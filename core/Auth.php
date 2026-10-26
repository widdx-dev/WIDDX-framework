<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Simple authentication (all methods are static).
 *
 * Convention: App\Models\User model with email + password (hashed) columns.
 * Override model via config('auth.model').
 *
 * AuthInterface documents the driver contract for custom back-ends
 * (e.g. JWT, Token) implemented as instance methods.
 *
 * Example:
 *   Auth::attempt(['email' => request('email'), 'password' => request('password')]);
 *   Auth::user();   // current user or null
 *   Auth::check();  // logged in?
 *   Auth::logout();
 */
class Auth
{
    /** Optional model override (tests / multi-account apps). */
    protected static ?string $modelOverride = null;

    public static function useModel(?string $class): void
    {
        static::$modelOverride = $class;
    }

    /** Resolve the user model class */
    public static function userClass(): string
    {
        return static::$modelOverride ?? (string) config('auth.model', 'App\\Models\\User');
    }

    /**
     * Bcrypt of a throwaway string. attempt() verifies unknown users and
     * users with missing/unrecognized stored hashes against it so their login
     * attempts have the same expensive path as an incorrect password —
     * otherwise attackers can enumerate accounts by stopwatch.
     */
    protected const TIMING_HASH = '$2y$12$rsA5reQ0bKC/0AehgyLIz.Uc2OyfENehP52tb2J61IUPgmq2i48KW';

    /**
     * Attempt login with credentials (e.g. ['email' => ..., 'password' => ...]).
     * Returns true on success (user stored in session).
     */
    public static function attempt(array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        unset($credentials['password']);

        $class = static::userClass();
        if (!class_exists($class)) {
            return false;
        }

        $query = $class::query();
        foreach ($credentials as $key => $value) {
            $query->where($key, $value);
        }
        $user = $query->first();

        if (!$user || $password === null) {
            // Burn the same bcrypt cycle as the real verify path.
            password_verify((string) ($password ?? ''), self::TIMING_HASH);
            return false;
        }

        $hash = (string) ($user->password ?? '');
        $hashInfo = $hash !== '' ? password_get_info($hash) : [];
        $hasUsableHash = $hash !== '' && ($hashInfo['algoName'] ?? 'unknown') !== 'unknown';

        // Even an account with no usable stored password must consume one
        // password-verification cycle. A short-circuit here lets attackers
        // distinguish seeded/disabled or corrupt-hash accounts from unknown
        // emails by timing.
        $verified = password_verify(
            (string) $password,
            $hasUsableHash ? $hash : self::TIMING_HASH
        );
        if (!$hasUsableHash || !$verified) {
            return false;
        }
        // Transparent upgrade: old-cost hashes are rehashed with current defaults.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $user->password = password_hash((string) $password, PASSWORD_DEFAULT);
            try {
                if (!$user->save()) {
                    // Keep the in-memory hash aligned with the database: the
                    // login fingerprint below must not revoke a valid session
                    // when this best-effort upgrade could not be persisted.
                    $user->password = $hash;
                }
            } catch (\Throwable) {
                // Rehash is best-effort — login must not depend on it.
                $user->password = $hash;
            }
        }

        static::login($user);
        return true;
    }

    /** Log in a user instance */
    public static function login(mixed $user): void
    {
        Session::put('auth_user_id', $user->id);
        Session::put('auth_user_class', $user::class);
        Session::put('auth_user_password_fingerprint', static::passwordFingerprint($user));
        // Guard against session fixation: issue a fresh session ID on login.
        Session::regenerate();
        // ...and a fresh CSRF token tied to the NEW session only.
        Session::forget('_csrf_token');
    }

    /** Log out */
    public static function logout(): void
    {
        Session::forget('auth_user_id');
        Session::forget('auth_user_class');
        Session::forget('auth_user_password_fingerprint');
        // The old session's CSRF token must die with the identity, and the
        // ID rotates so a copy taken while logged in is worthless after.
        Session::forget('_csrf_token');
        Session::regenerate();
    }

    /** Is anyone logged in? */
    public static function check(): bool
    {
        return static::user() !== null;
    }

    /** Is guest (not logged in)? */
    public static function guest(): bool
    {
        return !static::check();
    }

    /** Current user ID or null */
    public static function id(): mixed
    {
        return Session::get('auth_user_id');
    }

    /** Current user model or null */
    public static function user(): mixed
    {
        $id = static::id();
        if ($id === null) {
            return null;
        }
        $class = Session::get('auth_user_class', static::userClass());
        if (!is_string($class) || !class_exists($class)) {
            static::logout();
            return null;
        }

        $user = $class::find($id);
        $fingerprint = Session::get('auth_user_password_fingerprint');
        if (
            $user === null
            || !is_string($fingerprint)
            || !hash_equals($fingerprint, static::passwordFingerprint($user))
        ) {
            // Password resets/admin changes invalidate every existing session
            // on its next request. Sessions from before this hardening have no
            // fingerprint and must sign in again rather than bypass revocation.
            static::logout();
            return null;
        }

        return $user;
    }

    /** Non-reversible session marker for the current stored password hash. */
    protected static function passwordFingerprint(mixed $user): string
    {
        return hash('sha256', (string) ($user->password ?? ''));
    }

    // ---------- Registration ----------

    /**
     * Create + log in a user. 'password_confirmation'/'csrf_token' are
     * stripped, the password is hashed, and (when the users table has
     * email_verified_at) a verification mail goes out automatically.
     *
     * Attributes are set DIRECTLY (bypassing $fillable): the caller passes
     * already-validated registration data, and user models legitimately
     * exclude 'password' from mass assignment for their CRUD screens.
     */
    public static function register(array $data, bool $login = true): mixed
    {
        $class = static::userClass();
        unset($data['password_confirmation'], $data['csrf_token'], $data['_method'], $data['id'], $data['created_at'], $data['updated_at']);
        if (isset($data['password']) && is_string($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $user = new $class();
        foreach ($data as $key => $value) {
            $user->{$key} = $value;
        }
        $user->save();
        if ($login) {
            static::login($user);
        }
        if (static::verificationAvailable($user) && !static::verified($user)) {
            static::sendVerification($user);
        }
        return $user;
    }

    // ---------- Password reset ----------

    /**
     * Queue a password-reset request without looking up the email during the
     * HTTP response path. Always returns true: existence checks and token
     * creation happen after the response is sent, avoiding a timing oracle.
     */
    public static function sendResetLink(string $email): bool
    {
        static::ensureMailListener();
        static::ensureResetRequestListener();
        Event::queue('auth.reset_request', $email);
        return true;
    }

    /** One shared listener performs the existence check after the response. */
    protected static bool $resetRequestListenerRegistered = false;

    protected static function ensureResetRequestListener(): void
    {
        if (static::$resetRequestListenerRegistered) {
            return;
        }
        Event::listen('auth.reset_request', function (string $email): void {
            $class = Auth::userClass();
            if (!class_exists($class) || !$class::query()->where('email', $email)->exists()) {
                return;
            }
            $token = Auth::createToken($email, 'reset');
            $link = Auth::resetLink($email, $token);
            // This listener itself runs during Event::flush(), after App::run()
            // has sent the response. Fire the already-registered mail listener
            // directly rather than queueing a nested event for a later request.
            Event::fire('auth.mail', $email, 'Reset your password', "Use this link to reset your password (valid 1 hour):\n{$link}");
        });
        static::$resetRequestListenerRegistered = true;
    }

    /**
     * Queue an email-verification resend request without checking account state
     * during the HTTP response. Always return accepted for anti-enumeration.
     */
    public static function requestVerificationResend(string $email): bool
    {
        static::ensureMailListener();
        static::ensureVerificationResendListener();
        Event::queue('auth.verification_resend_request', $email);
        return true;
    }

    protected static bool $verificationResendListenerRegistered = false;

    protected static function ensureVerificationResendListener(): void
    {
        if (static::$verificationResendListenerRegistered) {
            return;
        }
        Event::listen('auth.verification_resend_request', function (string $email): void {
            $class = Auth::userClass();
            if (!class_exists($class)) {
                return;
            }
            $user = $class::query()->where('email', $email)->first();
            if (
                $user === null
                || !Auth::verificationAvailable($user)
                || !empty($user->email_verified_at)
            ) {
                return;
            }

            $token = Auth::createToken($email, 'verify');
            $link = rtrim((string) config('app.url', 'http://localhost'), '/')
                . '/verify-email/' . $token . '?email=' . urlencode($email);
            Event::fire(
                'auth.mail',
                $email,
                'Verify your email address',
                "Confirm your account with this link (valid 7 days):\n{$link}"
            );
        });
        static::$verificationResendListenerRegistered = true;
    }

    /** One shared listener that actually sends queued auth mails. */
    protected static function ensureMailListener(): void
    {
        if (static::$mailListenerRegistered) {
            return;
        }
        Event::listen('auth.mail', function (string $to, string $subject, string $body): void {
            Mail::raw($to, $subject, $body);
        });
        static::$mailListenerRegistered = true;
    }

    protected static bool $mailListenerRegistered = false;

    public static function resetLink(string $email, string $token): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/')
            . '/reset-password/' . $token . '?email=' . urlencode($email);
    }

    /**
     * Consume a reset token and change the password in one transaction.
     *
     * Claiming the token via DELETE is atomic: concurrent requests presenting
     * the same token cannot both pass the one-use check. The password update
     * and cleanup of sibling reset tokens commit with that claim.
     */
    public static function resetPassword(string $email, ?string $token, string $password): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $class = static::userClass();
        $now = date('Y-m-d H:i:s');
        $oldest = date('Y-m-d H:i:s', time() - 60 * 60);
        $tokenHash = hash('sha256', $token);

        return Database::connection()->transaction(function () use ($email, $tokenHash, $oldest, $now, $class, $password): bool {
            // Delete only an unexpired, non-future reset token. Row deletion is
            // the atomic claim; a concurrent request will affect zero rows.
            $claimed = db('auth_tokens')
                ->where('email', $email)
                ->where('type', 'reset')
                ->where('token_hash', $tokenHash)
                ->where('created_at', '>=', $oldest)
                ->where('created_at', '<=', $now)
                ->delete();

            if ($claimed < 1 || !class_exists($class)) {
                return false;
            }

            $user = $class::query()->where('email', $email)->first();
            if ($user === null) {
                return false;
            }

            $user->password = password_hash($password, PASSWORD_DEFAULT);
            if (!$user->save()) {
                // Database::transaction rolls back both the token claim and any
                // partial changes when this exception escapes the callback.
                throw new \RuntimeException('Could not persist the password reset.');
            }

            static::deleteTokens($email, 'reset');
            return true;
        });
    }

    // ---------- Email verification ----------

    public static function verificationAvailable(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }
        // columns() returns [] only for a genuinely missing table (the "no
        // verification column" case); any real database failure propagates now,
        // so a transient introspection error is never mistaken for "already
        // verified" — which would silently skip the verification email.
        return in_array('email_verified_at', $user->columns(), true);
    }

    public static function verified(mixed $user): bool
    {
        // A missing user is never verified. Models without an email_verified_at
        // column remain usable for apps that haven't enabled verification.
        if ($user === null) {
            return false;
        }
        return !static::verificationAvailable($user) || !empty($user->email_verified_at);
    }

    public static function sendVerification(mixed $user): void
    {
        $email = (string) ($user->email ?? '');
        if ($email === '') {
            return;
        }
        $token = static::createToken($email, 'verify');
        $link = rtrim((string) config('app.url', 'http://localhost'), '/')
            . '/verify-email/' . $token . '?email=' . urlencode($email);
        $subject = 'Verify your email address';
        $body = "Confirm your account with this link (valid 7 days):\n{$link}";

        // CLI commands have no HTTP response boundary at which App::run() can
        // flush queued events, so retain immediate delivery for standalone CLI use.
        if (PHP_SAPI === 'cli') {
            Mail::raw($email, $subject, $body);
            return;
        }

        // Web requests defer SMTP work until after the response is sent, matching
        // password-reset delivery and avoiding mail IO in the response path.
        static::ensureMailListener();
        Event::queue('auth.mail', $email, $subject, $body);
    }

    public static function verifyEmail(string $email, ?string $token): bool
    {
        if ($token === null || $token === '' || !static::tokenValid($email, 'verify', $token, 60 * 60 * 24 * 7)) {
            return false;
        }
        $class = static::userClass();
        $user = $class::query()->where('email', $email)->first();
        if ($user === null || !static::verificationAvailable($user)) {
            return false;
        }
        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->save();
        static::deleteTokens($email, 'verify');
        return true;
    }

    // ---------- Token internals (auth_tokens table) ----------

    /** @return string plain token (only the hash is stored) */
    public static function createToken(string $email, string $type): string
    {
        $plain = bin2hex(random_bytes(32));
        // Nothing else ever deletes an UNUSED token, so without this sweep the
        // table grows forever and every lookup walks it. 7 days is the longest
        // lifetime the framework issues (email verification), so pruning
        // anything older can never invalidate a link still worth clicking.
        db('auth_tokens')->where('created_at', '<', date('Y-m-d H:i:s', time() - 60 * 60 * 24 * 7))->delete();
        db('auth_tokens')->insert([
            'email' => $email,
            'type' => $type,
            'token_hash' => hash('sha256', $plain),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $plain;
    }

    public static function tokenValid(string $email, string $type, string $token, int $ttlSeconds = 3600): bool
    {
        $rows = db('auth_tokens')
            ->where('email', $email)
            ->where('type', $type)
            ->where('token_hash', hash('sha256', $token))
            ->get();
        foreach ($rows as $row) {
            $age = time() - (int) strtotime((string) $row->created_at);
            if ($age >= 0 && $age <= $ttlSeconds) {
                return true;
            }
        }
        return false;
    }

    public static function deleteTokens(string $email, string $type): void
    {
        db('auth_tokens')->where('email', $email)->where('type', $type)->delete();
    }
}

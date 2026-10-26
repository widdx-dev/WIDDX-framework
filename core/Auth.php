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
                $user->save();
            } catch (\Throwable) {
                // Rehash is best-effort — login must not depend on it.
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
            return null;
        }
        return $class::find($id);
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

    /** Create + mail a signed reset link. Returns false when the email is unknown. */
    public static function sendResetLink(string $email): bool
    {
        static::ensureMailListener();
        $class = static::userClass();
        if (!$class::query()->where('email', $email)->exists()) {
            return false; // caller must still report success (anti-enumeration)
        }
        $token = static::createToken($email, 'reset');
        $link = static::resetLink($email, $token);
        // Deferred until AFTER the response reached the client: the request
        // itself returns in the same time whether or not the mail goes out,
        // so reset-link requests cannot be timed into an enumeration oracle.
        Event::queue('auth.mail', $email, 'Reset your password', "Use this link to reset your password (valid 1 hour):\n{$link}");
        return true;
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

    /** Validate token, set the new password, burn ALL reset tokens. */
    public static function resetPassword(string $email, ?string $token, string $password): bool
    {
        if ($token === null || $token === '' || !static::tokenValid($email, 'reset', $token)) {
            return false;
        }
        $class = static::userClass();
        $user = $class::query()->where('email', $email)->first();
        if ($user === null) {
            return false;
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->save();
        static::deleteTokens($email, 'reset');
        return true;
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
        Mail::raw($email, 'Verify your email address', "Confirm your account with this link (valid 7 days):\n{$link}");
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

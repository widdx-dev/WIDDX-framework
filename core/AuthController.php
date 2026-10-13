<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Exceptions\ValidationException;

/**
 * Framework-owned auth flow — zero user code.
 *
 * Registered by the authRoutes() helper; every screen first looks for a
 * project view (views/auth/login.php …) and falls back to the built-in
 * template in core/views/auth/*. Override anything by adding your own
 * file — nothing else changes.
 */
class AuthController
{
    protected function render(string $template, array $data = []): Response
    {
        $html = View::exists($template)
            ? view($template, $data)
            : View::core($template, $data);
        return Response::make($html);
    }

    // ---------- Login / register / logout ----------

    public function showLogin(): Response
    {
        return Auth::check() ? redirect('/') : $this->render('auth.login');
    }

    public function login(): Response
    {
        $credentials = request()->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if (Auth::attempt($credentials)) {
            return redirect('/');
        }
        Session::flash('_errors', ['email' => [Lang::line('auth.failed')]]);
        Session::flash('_old', request()->except(['password', 'csrf_token']));
        return redirect('/login');
    }

    public function showRegister(): Response
    {
        return Auth::check() ? redirect('/') : $this->render('auth.register');
    }

    public function register(): Response
    {
        $data = request()->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);
        try {
            $user = Auth::register($data);
        } catch (ValidationException $e) {
            // model-level rules disagree — show them like any validation failure
            Session::flash('_errors', $e->errors);
            Session::flash('_old', request()->except(['password', 'password_confirmation', 'csrf_token']));
            return redirect('/register');
        }
        if (Auth::verificationAvailable($user) && !Auth::verified($user)) {
            return redirect('/email-verification');
        }
        return redirect('/');
    }

    public function logout(): Response
    {
        Auth::logout();
        return redirect('/login');
    }

    // ---------- Password reset ----------

    public function showForgot(): Response
    {
        return $this->render('auth.forgot');
    }

    public function sendResetLink(): Response
    {
        request()->validate(['email' => 'required|email']);
        // Returns false for unknown emails — we report success anyway
        // (anti-enumeration: attackers must not learn who has an account).
        Auth::sendResetLink((string) request('email'));
        Session::flash('success', Lang::line('auth.link_sent'));
        return redirect('/forgot-password');
    }

    public function showReset(string $token): Response
    {
        return $this->render('auth.reset', [
            'token' => $token,
            'email' => (string) request('email', ''),
        ]);
    }

    public function reset(): Response
    {
        request()->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $ok = Auth::resetPassword(
            (string) request('email'),
            (string) request('token', ''),
            (string) request('password')
        );
        if (!$ok) {
            Session::flash('_errors', ['token' => [Lang::line('auth.invalid_token')]]);
            Session::flash('_old', ['email' => (string) request('email')]);
            return redirect('/forgot-password');
        }
        Session::flash('success', Lang::line('auth.password_reset'));
        return redirect('/login');
    }

    // ---------- Email verification ----------

    public function showVerifyNotice(): Response
    {
        return $this->render('auth.verify');
    }

    public function verify(string $token): Response
    {
        $email = (string) request('email', '');
        if (Auth::verifyEmail($email, $token)) {
            Session::flash('success', Lang::line('auth.verified'));
            return redirect('/');
        }
        Session::flash('_errors', ['email' => [Lang::line('auth.invalid_token')]]);
        return redirect('/email-verification');
    }

    public function resendVerification(): Response
    {
        request()->validate(['email' => 'required|email']);
        $class = Auth::userClass();
        $user = class_exists($class) ? $class::query()->where('email', request('email'))->first() : null;
        if ($user !== null && Auth::verificationAvailable($user) && !Auth::verified($user)) {
            Auth::sendVerification($user);
        }
        Session::flash('success', Lang::line('auth.verify_sent'));
        return redirect('/email-verification');
    }
}

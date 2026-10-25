<?php

/**
 * Widdx integration suite — drives the framework END TO END:
 *   1. scaffolds a real project (php widdx new)
 *   2. boots it on php -S and speaks raw HTTP (CRUD, flash, CSRF, 404/405,
 *      validation rules incl. unique/confirmed, auth, security headers,
 *      queued events, Arabic locale, custom messages)
 *   3. runs the CLI generators and lints everything they emit
 *   4. talks SMTP through the real SmtpMail client against a fake server
 *
 * Run: php tests/integration.php   (also executed by: php widdx-test)
 */

declare(strict_types=1);

if (PHP_OS_FAMILY === 'Windows') {
    echo "SKIP: the integration suite is POSIX-only (uses php -S + shell job control).\n";
    exit(0);
}

$ROOT = dirname(__DIR__);
// Is this the FRAMEWORK source repo, or a project built with it? composer.json says:
// the source repo is type=framework, every scaffold is type=project. Nothing may read
// app/ or views/ off $ROOT unless this is true — a real project's own demo files are
// not the framework's demo files.
$composerType = (static function (string $root): string {
    $json = json_decode((string) @file_get_contents($root . '/composer.json'), true);
    return is_array($json) ? (string) ($json['type'] ?? '') : '';
})($ROOT);
$isFrameworkSource = $composerType === 'framework';
$TMP = (getenv('ITTMP') !== false ? getenv('ITTMP') : sys_get_temp_dir() . '/widdx-it') . '-' . getmypid() . '-' . random_int(1000, 9999);
@mkdir($TMP, 0777, true);

$GLOBALS['server_pids'] = [];
$GLOBALS['main_env_created'] = false;

register_shutdown_function(static function () use ($TMP, $ROOT) {
    foreach ($GLOBALS['server_pids'] as $pid) {
        if ($pid > 0) {
            @exec('kill ' . (int) $pid . ' 2>/dev/null');
        }
    }
    if ($GLOBALS['main_env_created'] && is_file($ROOT . '/.env')) {
        @unlink($ROOT . '/.env');
    }
    if (getenv('ITKEEP')) {
        fwrite(STDERR, "KEPT TMP: {$TMP}\n");
    } elseif (str_contains($TMP, '/widdx-it') && is_dir($TMP)) {
        @exec('rm -rf ' . escapeshellarg($TMP));
    }
});

// ---------------------------------------------------------------- harness

$pass = 0;
$fail = 0;

function sec(string $name): void
{
    echo "\n== {$name} ==\n";
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $GLOBALS['pass']++;
        echo "  PASS  {$name}\n";
    } else {
        $GLOBALS['fail']++;
        echo "  FAIL  {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    }
}

function skip(string $name, string $why): void
{
    echo "  SKIP  {$name}  [{$why}]\n";
}

/** Tests that exercise CRUD behavior explicitly opt in after verifying the generator's secure default. */
function integration_public_resource(string $project, string $table): void
{
    $path = $project . '/routes/web.php';
    $routes = (string) file_get_contents($path);
    $protected = "resource('{$table}');";
    $public = "resource('{$table}')->public();";
    if (!str_contains($routes, $protected)) {
        throw new RuntimeException("Expected generated protected resource route for [{$table}].");
    }
    file_put_contents($path, str_replace($protected, $public, $routes));
}

function shell(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function pick_port(): int
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $en, $em);
    $name = stream_socket_get_name($srv, false);
    fclose($srv);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * 2.7: for the networked drivers the database outlives a single suite run —
 * wipe the schema first so leftover rows (unique emails, stale jobs) cannot
 * poison a rerun. sqlite needs nothing: its file is per-project under $TMP.
 */
function reset_server_db(): void
{
    $drv = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
    if (!in_array($drv, ['mysql', 'pgsql'], true)) {
        return;
    }
    $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_PORT') ?: ($drv === 'mysql' ? 3306 : 5432));
    $user = (string) (getenv('DB_USERNAME') ?: ($drv === 'mysql' ? 'root' : 'postgres'));
    $pass = (string) (getenv('DB_PASSWORD') ?: ($drv === 'mysql' ? 'widdxpass' : ''));
    $name = (string) (getenv('DB_DATABASE') ?: 'widdx');
    if ($drv === 'mysql') {
        $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
        $pdo->exec("DROP DATABASE IF EXISTS {$name}");
        $pdo->exec("CREATE DATABASE {$name} CHARACTER SET utf8mb4");
        return;
    }
    // pgsql: must connect to the TARGET database — dropping public while
    // attached to the 'postgres' maintenance DB wipes the wrong schema.
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
    $pdo->exec("DROP SCHEMA IF EXISTS public CASCADE");
    $pdo->exec("CREATE SCHEMA public");
    $pdo->exec("GRANT ALL ON SCHEMA public TO {$user}");
}

/**
 * 2.7: a PDO for assertions the parent process makes against the SCAFFOLD
 * project's own database. sqlite → that project's file; mysql/pgsql → the
 * shared server DB the project resolves from the exported env.
 */
function project_pdo(string $sqliteFile): PDO
{
    $drv = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
    if ($drv === 'mysql' || $drv === 'pgsql') {
        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('DB_PORT') ?: ($drv === 'mysql' ? 3306 : 5432));
        $user = (string) (getenv('DB_USERNAME') ?: ($drv === 'mysql' ? 'root' : 'postgres'));
        $pass = (string) (getenv('DB_PASSWORD') ?: ($drv === 'mysql' ? 'widdxpass' : ''));
        $name = (string) (getenv('DB_DATABASE') ?: 'widdx');
        return new PDO("{$drv}:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
    }
    return new PDO('sqlite:' . $sqliteFile);
}

/** 2.7: driver-aware "does this table exist" without loading the framework. */
function db_has_table(PDO $pdo, string $table): bool
{
    $drv = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
    if ($drv === 'sqlite') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND lower(name)=lower(?)");
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

reset_server_db();

function dechunk(string $data): string
{
    $out = '';
    while ($data !== '') {
        $nl = strpos($data, "\r\n");
        if ($nl === false) {
            break;
        }
        $size = hexdec(substr($data, 0, $nl));
        if ($size === 0) {
            break;
        }
        $out .= substr($data, $nl + 2, $size);
        $data = substr($data, $nl + 2 + $size + 2);
    }
    return $out;
}

/**
 * Minimal HTTP/1.1 client over a raw socket with a cookie jar.
 * @param array<string,string> $jar
 * @return array{status:int, headers:array, body:string}
 */
function http(int $port, string $method, string $path, ?array $form = null, array &$jar = [], ?string $rawBody = null, ?string $contentType = null, array $extraHeaders = []): array
{
    $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $en, $es, 5.0);
    if ($fp === false) {
        return ['status' => 0, 'headers' => ['_error' => $es], 'body' => ''];
    }
    stream_set_timeout($fp, 10);
    $body = $rawBody;
    if ($body === null && $form !== null) {
        $body = http_build_query($form);
    }
    if ($body !== null && $contentType === null) {
        $contentType = 'application/x-www-form-urlencoded';
    }
    $lines = ["{$method} {$path} HTTP/1.1", 'Host: 127.0.0.1:' . $port, 'Connection: close'];
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $lines[] = 'Cookie: ' . implode('; ', $pairs);
    }
    if ($body !== null) {
        $lines[] = 'Content-Type: ' . ($contentType ?? 'application/x-www-form-urlencoded');
        $lines[] = 'Content-Length: ' . strlen($body);
    }
    foreach ($extraHeaders as $h) {
        $lines[] = $h;
    }
    fwrite($fp, implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? ''));

    $resp = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $resp .= $chunk;
    }
    fclose($fp);

    $split = explode("\r\n\r\n", $resp, 2);
    $head = $split[0] ?? '';
    $raw = $split[1] ?? '';
    $headLines = explode("\r\n", $head);
    $status = 0;
    if (preg_match('#HTTP/[\d.]+ (\d{3})#', $headLines[0] ?? '', $m)) {
        $status = (int) $m[1];
    }
    $headers = [];
    $setCookies = [];
    foreach (array_slice($headLines, 1) as $l) {
        if (!str_contains($l, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $l, 2);
        $k = strtolower(trim($k));
        $v = trim($v);
        if ($k === 'set-cookie') {
            $setCookies[] = $v;
        } else {
            $headers[$k] = $v;
        }
    }
    if (($headers['transfer-encoding'] ?? '') === 'chunked') {
        $raw = dechunk($raw);
    }
    foreach ($setCookies as $sc) {
        $pair = explode(';', $sc)[0];
        if (str_contains($pair, '=')) {
            [$n, $val] = explode('=', $pair, 2);
            if ($val === '' || str_starts_with(strtolower($n), 'expires')) {
                unset($jar[trim($n)]);
            } else {
                $jar[trim($n)] = $val;
            }
        }
    }
    if ($setCookies !== []) {
        $headers['set-cookie'] = implode(' | ', $setCookies);
    }
    if (getenv('ITTRACE')) {
        fwrite(STDERR, "TRACE {$method} {$path} -> {$status} SC=" . implode('||', $setCookies) . ' JAR=' . json_encode($jar) . "\n");
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $raw];
}

/** Pull the session's CSRF token out of any rendered form (web group is on by default). */
function grab_csrf(int $port, string $formPath, array &$jar): string
{
    $r = http($port, 'GET', $formPath, null, $jar);
    return preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m) === 1 ? $m[1] : '';
}

function start_server(string $proj, int $port): int
{
    @mkdir($proj . '/storage/sessions', 0777, true);
    $router = is_file($proj . '/public/router.php')
        ? ' ' . escapeshellarg($proj . '/public/router.php')
        : '';
    $cmd = 'nohup ' . escapeshellarg(PHP_BINARY)
        . ' -d session.save_path=' . escapeshellarg($proj . '/storage/sessions')
        . " -S 127.0.0.1:{$port} -t " . escapeshellarg($proj . '/public')
        . $router
        . ' > ' . escapeshellarg($proj . '/server.log') . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($cmd));
    for ($i = 0; $i < 100; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $en, $em, 0.25);
        if ($s !== false) {
            fclose($s);
            $GLOBALS['server_pids'][] = $pid;
            return $pid;
        }
        usleep(100000);
    }
    throw new RuntimeException("server on port {$port} did not start:\n" . @file_get_contents($proj . '/server.log'));
}

function stop_server(int $pid, int $port): void
{
    @exec('kill ' . (int) $pid . ' 2>/dev/null');

    // Do not start the replacement while the old worker still owns the port.
    // Otherwise start_server() can mistake the old listener for the new one,
    // then the old process exits and all following HTTP probes return status 0.
    for ($i = 0; $i < 100; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $en, $em, 0.05);
        if ($s === false) {
            return;
        }
        fclose($s);
        usleep(50000);
    }

    throw new RuntimeException("server on port {$port} did not stop after PID {$pid} was killed");
}

// ------------------------------------------------------- 1. scaffold project

sec('Scaffold: php widdx new app');

[$code, $out] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new app');
ok('widdx new exits 0', $code === 0, $out);
$proj = $TMP . '/app';

// A project made from an absolute path (`php widdx new /tmp/x/pkg-name`) must still
// get a legal composer package name. The whole argument used to be pasted into it,
// giving "widdx//tmp/x/pkg-name", which composer refuses to load.
[$pkgCode, $pkgOut] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new ' . escapeshellarg($TMP . '/pkg-name'));
ok('widdx new with an absolute path exits 0', $pkgCode === 0, $pkgOut);
$pkgJson = json_decode((string) @file_get_contents($TMP . '/pkg-name/composer.json'), true);
$pkgName = is_array($pkgJson) ? (string) ($pkgJson['name'] ?? '') : '';
ok('the scaffolded package name is composer-legal',
    preg_match('#^[a-z0-9._-]+/[a-z0-9._-]+$#', $pkgName) === 1, $pkgName);

// The agent handbook is two layers now: AGENTS.md binds, .agents/*.md is the reference
// it routes to. A scaffold that ships the router without the files behind it hands every
// AI assistant in the project dead links, so check the real generated tree — not the CLI source.
$router = (string) @file_get_contents($proj . '/AGENTS.md');
ok('scaffold ships the binding handbook', str_contains($router, '## PART 2A — THE LAWS'));
$linked = [];
preg_match_all('#`(\.agents/[0-9A-Za-z._-]+\.md)`#', $router, $routerMatches);
foreach (array_unique($routerMatches[1]) as $rel) {
    $linked[] = $rel;
    ok("scaffold ships {$rel}", is_file($proj . '/' . $rel));
}
ok('the router points at at least 10 reference files', count($linked) >= 10, implode(',', $linked));

// Bring the demo app files from the framework repo into the scaffold. A
// scaffolded project ships a bare app/, so without them the suite exercises the
// zero-code fallback path (model + built-in pages) instead. Guarded by
// $isFrameworkSource: copied from a real project, the scaffold inherits a User
// that relates to classes the scaffold never receives.
$repoDemo = $isFrameworkSource
    && is_file($ROOT . '/app/Controllers/UserController.php')
    && is_dir($ROOT . '/views/users');
if ($repoDemo) {
    copy($ROOT . '/app/Controllers/UserController.php', $proj . '/app/Controllers/UserController.php');
    copy($ROOT . '/app/Models/User.php', $proj . '/app/Models/User.php');
    shell('cp -r ' . escapeshellarg($ROOT . '/views/users') . ' ' . escapeshellarg($proj . '/views/'));
}

// Routes: full users resource + probe routes used by the scenarios below.
$probes = <<<'ROUTES'

// ===== integration probes (appended by tests/integration.php) =====
use Widdx\Auth;
use Widdx\Response;

resource('users', \App\Controllers\UserController::class)->public();

post('/it-login', function () {
    $ok = Auth::attempt(request()->only(['email', 'password']));
    return Response::json(['ok' => $ok, 'id' => Auth::id()]);
});
get('/it-auth-check', fn() => Response::json(['check' => Auth::check(), 'id' => Auth::id()]));
post('/it-logout', function () {
    Auth::logout();
    return Response::json(['ok' => true]);
});
post('/it-validate', function () {
    try {
        request()->validate([
            'name' => 'required|min:3',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|confirmed',
        ]);
        return Response::json(['ok' => true]);
    } catch (\Widdx\Exceptions\ValidationException $e) {
        return Response::json(['ok' => false, 'errors' => $e->errors], 422);
    }
});
post('/it-validate-custom', function () {
    try {
        request()->validate(['token' => 'required'], ['token.required' => 'CUSTOM :field message']);
        return Response::json(['ok' => true]);
    } catch (\Widdx\Exceptions\ValidationException $e) {
        return Response::json(['errors' => $e->errors], 422);
    }
});
get('/it-cols', function () {
    try {
        db('users')->allowColumns(['id'])->where('secret', 1)->get();
        return Response::json(['blocked' => false]);
    } catch (\Widdx\Exceptions\DatabaseException $e) {
        return Response::json(['blocked' => true]);
    }
});
get('/it-event', function () {
    $file = \Widdx\App::basePath('') . '/storage/event-hit.txt';
    \Widdx\Event::listen('it.ping', function ($payload) use ($file) {
        file_put_contents($file, (string) $payload);
    });
    \Widdx\Event::queue('it.ping', 'fired');
    return Response::json(['before' => is_file($file)]);
});
get('/it-event-check', fn() => Response::json([
    'hit' => is_file(\Widdx\App::basePath('') . '/storage/event-hit.txt'),
]));
get('/it-throttle', fn() => Response::json(['ok' => true]))->middleware(new \Widdx\ThrottleMiddleware(2, 60));
// id lookup by email — works whether the list page is the demo view or the
// built-in zero-code one (their row markup differs).
get('/it-id', function () {
    // QueryBuilder rows are stdClass objects, not arrays.
    $row = db('users')->where('email', (string) request()->query('email'))->first();
    return Response::json(['id' => $row->id ?? null]);
});
// 1.4 guard: the framework middleware itself, available in every project.
get('/it-guarded', fn() => Response::json(['ok' => true]))->middleware(\Widdx\AuthMiddleware::class);
// The scaffolded seeder ships no usable password any more (a hash of a string
// printed in the generator is a public credential), so the auth assertions
// below set a test-only one through this probe.
post('/it-setpass', function () {
    $u = \App\Models\User::where('email', (string) request()->input('email'))->first();
    if ($u === null) {
        return Response::json(['ok' => false], 404);
    }
    $u->password = password_hash((string) request()->input('password'), PASSWORD_DEFAULT);
    $u->save();
    return Response::json(['ok' => true]);
});
// Ops probe: trusted proxies + the real peer IP. Registered here, before the
// server starts — a live append to routes/web.php is not reliably picked up by
// php -S, which made these assertions flake.
\Widdx\Request::setTrustedProxies(['127.0.0.1']);
get('/it-ip', fn() => Response::json(['ip' => request()->ip()]));
ROUTES;
file_put_contents($proj . '/routes/web.php', $probes . "\n", FILE_APPEND);

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('migrate exits 0', $code === 0, $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx db:seed');
ok('db:seed exits 0', $code === 0, $out);

// 1.4 needs no demo file: /it-guarded (framework AuthMiddleware) was registered
// with the other probes BEFORE the server started — php -S intermittently fails
// to pick up a route appended to a live server (the same trap that made
// /it-boom 404), so the guard assertions never depend on a mid-flight append.

$port = pick_port();
$pid = start_server($proj, $port);
$jar = [];

// ------------------------------------------------------------ 2. CRUD flows

sec('CRUD over real HTTP');

$r = http($port, 'GET', '/', null, $jar);
ok('GET / is 200', $r['status'] === 200, "status={$r['status']}");
$sc = strtolower((string) ($r['headers']['set-cookie'] ?? ''));
ok('session cookie HttpOnly + SameSite', str_contains($sc, 'httponly') && str_contains($sc, 'samesite'), $sc);

$r = http($port, 'GET', '/users');
ok('GET /users is 200', $r['status'] === 200, "status={$r['status']}");
ok('seeded users listed', str_contains($r['body'], 'Admin User'), substr(md5($r['body']), 0, 8));

$r = http($port, 'GET', '/users/create');
ok('GET /users/create is 200', $r['status'] === 200, "status={$r['status']}");

// web group is ON by default (1.3): every mutating request needs a token.
$tokU = grab_csrf($port, '/users/create', $jar);
ok('1.3 web group put a csrf token in the form automatically', strlen($tokU) >= 32, 'token=' . $tokU);

$r = http($port, 'POST', '/users', ['csrf_token' => $tokU, 'name' => 'It Test', 'email' => 'it@test.local'], $jar);
ok('POST /users redirects', $r['status'] === 302, "status={$r['status']}");
$r = http($port, 'GET', '/users', null, $jar);
ok('created user visible in list', str_contains($r['body'], 'It Test'));

$r = http($port, 'GET', '/it-id?email=it%40test.local');
$id = json_decode($r['body'], true)['id'] ?? null;
ok('created row is readable by unique email', $id !== null, $r['body']);

if ($id !== null) {
    $r = http($port, 'GET', '/users/' . $id);
    ok('GET show page 200', $r['status'] === 200 && str_contains($r['body'], 'It Test'), "status={$r['status']}");

    $r = http($port, 'POST', '/users/' . $id, ['csrf_token' => $tokU, '_method' => 'PUT', 'name' => 'Renamed', 'email' => 'it@test.local'], $jar);
    ok('_method=PUT spoof redirects', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/users/' . $id);
    ok('update applied', str_contains($r['body'], 'Renamed'));

    $r = http($port, 'POST', '/users/' . $id, ['csrf_token' => $tokU, '_method' => 'DELETE'], $jar);
    ok('_method=DELETE spoof redirects', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/users/' . $id);
    ok('deleted user 404s', $r['status'] === 404, "status={$r['status']}");
}

$r = http($port, 'GET', '/users/987654');
ok('unknown id is friendly 404', $r['status'] === 404, "status={$r['status']}");

$r = http($port, 'POST', '/users/1');
ok('POST on show-uri is 405', $r['status'] === 405, "status={$r['status']}");

// ------------------------------------------------- 3. validation flash + old

sec('Model-auto validation: flash errors + old input');

$r = http($port, 'GET', '/users/create', null, $jar);
$r = http($port, 'POST', '/users', ['csrf_token' => $tokU, 'name' => '', 'email' => 'not-an-email'], $jar);
ok('invalid POST redirects back', $r['status'] === 302, "status={$r['status']}");
$r = http($port, 'GET', '/users/create', null, $jar);
ok('validation error flashed', str_contains($r['body'], 'valid email'), substr($r['body'], 0, 120));
ok('old input repopulated', str_contains($r['body'], 'value="not-an-email"'));

// A1 — the validation-redirect in App::run() must use the same same-site door as
// back(). A failing POST carrying an off-site Referer must land on '/', never on
// the attacker's host (this path bypassed the hardened back() helper before v0.3.8).
$r = http($port, 'POST', '/users', ['csrf_token' => $tokU, 'name' => '', 'email' => 'not-an-email'], $jar, null, null, ['Referer: http://evil.test/phish']);
ok('validation redirect with an off-site Referer stays local', (string) ($r['headers']['location'] ?? '') === '/', 'location=' . ($r['headers']['location'] ?? '(none)'));

// --------------------------------------------- 4. rules, columns, events, auth

sec('Validation rules (JSON probes)');

$r = http($port, 'POST', '/it-validate', ['csrf_token' => $tokU, 'name' => 'Sam', 'email' => 'fresh@test.local', 'password' => 'secret1', 'password_confirmation' => 'secret1'], $jar);
$data = json_decode($r['body'], true);
ok('valid payload passes', $r['status'] === 200 && ($data['ok'] ?? false) === true, $r['body']);

$r = http($port, 'POST', '/it-validate', ['csrf_token' => $tokU, 'email' => 'admin@example.com', 'name' => 'Sam', 'password' => 'secret1', 'password_confirmation' => 'secret1'], $jar);
$data = json_decode($r['body'], true);
ok('unique: rejects seeded email', $r['status'] === 422 && str_contains($data['errors']['email'][0] ?? '', 'already been taken'), $r['body']);

$r = http($port, 'POST', '/it-validate', ['csrf_token' => $tokU, 'name' => 'Sam', 'email' => 'fresh@test.local', 'password' => 'aaa111', 'password_confirmation' => 'bbb222'], $jar);
$data = json_decode($r['body'], true);
ok('confirmed: mismatch caught', str_contains($data['errors']['password'][0] ?? '', 'confirmation does not match'), $r['body']);

$r = http($port, 'POST', '/it-validate', ['csrf_token' => $tokU, 'name' => 'ab', 'email' => 'fresh@test.local', 'password' => 'secret1', 'password_confirmation' => 'secret1'], $jar);
$data = json_decode($r['body'], true);
ok('min:3 caught', str_contains($data['errors']['name'][0] ?? '', 'at least 3'), $r['body']);

$r = http($port, 'POST', '/it-validate-custom', ['csrf_token' => $tokU], $jar);
$data = json_decode($r['body'], true);
ok('custom message overrides lang', str_contains($data['errors']['token'][0] ?? '', 'CUSTOM token message'), $r['body']);

sec('allowColumns + queued events + auth');

$r = http($port, 'GET', '/it-cols');
$data = json_decode($r['body'], true);
ok('allowColumns blocks off-list column', ($data['blocked'] ?? false) === true, $r['body']);

@unlink($proj . '/storage/event-hit.txt');
$r = http($port, 'GET', '/it-event');
$data = json_decode($r['body'], true);
ok('queued event not fired before response', ($data['before'] ?? true) === false, $r['body']);
$r = http($port, 'GET', '/it-event-check');
$data = json_decode($r['body'], true);
ok('Event::flush fired after response', ($data['hit'] ?? false) === true, $r['body']);

$r = http($port, 'POST', '/it-setpass', ['csrf_token' => $tokU, 'email' => 'admin@example.com', 'password' => 'it-local-secret'], $jar);
$data = json_decode($r['body'], true);
ok('a test-only password can be set on the seeded user', ($data['ok'] ?? false) === true, $r['body']);

$r = http($port, 'POST', '/it-login', ['csrf_token' => $tokU, 'email' => 'admin@example.com', 'password' => 'wrong'], $jar);
$data = json_decode($r['body'], true);
ok('bad credentials rejected', ($data['ok'] ?? true) === false, $r['body']);

$r = http($port, 'POST', '/it-login', ['csrf_token' => $tokU, 'email' => 'admin@example.com', 'password' => 'it-local-secret'], $jar);
$data = json_decode($r['body'], true);
ok('attempt() logs in', ($data['ok'] ?? false) === true && (int) ($data['id'] ?? 0) > 0, $r['body']);

$r = http($port, 'GET', '/it-auth-check', null, $jar);
$data = json_decode($r['body'], true);
ok('session persists login', ($data['check'] ?? false) === true, $r['body']);

// 1.9: login rotated the CSRF token (fresh session identity) — pull a new one.
$tokMid = grab_csrf($port, '/users/create', $jar);
ok('1.9 login issues a CSRF token different from the pre-login one', $tokMid !== $tokU, 'token unchanged');
$sidPre = $jar['PHPSESSID'] ?? '';
$r = http($port, 'POST', '/it-logout', ['csrf_token' => $tokMid], $jar);
$sidPost = $jar['PHPSESSID'] ?? '';
ok('1.9 logout rotates the session id', $sidPost !== '' && $sidPost !== $sidPre, "pre={$sidPre} post={$sidPost}");
$r = http($port, 'GET', '/it-auth-check', null, $jar);
$data = json_decode($r['body'], true);
ok('logout clears session', ($data['check'] ?? true) === false, $r['body']);

// 1.4: a route guarded by the framework AuthMiddleware must block guests (302
// HTML / 401 JSON) and pass members. /it-guarded was registered with the other
// probes, before the server started.
$r = http($port, 'GET', '/it-guarded', null, $jar);
ok('1.4 guest through AuthMiddleware = 302 /login', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), '/login'), 'status=' . $r['status']);
$tokU = grab_csrf($port, '/users/create', $jar); // logout rotated the token (1.9)
$r = http($port, 'POST', '/it-login', ['csrf_token' => $tokU, 'email' => 'admin@example.com', 'password' => 'it-local-secret'], $jar);
$r = http($port, 'GET', '/it-guarded', null, $jar);
ok('1.4 member passes the guard', $r['status'] === 200 && str_contains($r['body'], 'ok'), 'status=' . $r['status']);
$jarG = [];
$r = http($port, 'GET', '/it-guarded', null, $jarG, null, null, ['Accept: application/json']);
ok('1.4 guest + JSON caller = 401 from guard', $r['status'] === 401 && str_contains($r['body'], 'Unauthenticated'), 'status=' . $r['status']);

// ------------------------------------------------------------ 5. CSRF stage

sec('CSRF: registering the middleware globally on top of the web group');

// CSRF is already on through the ambient web group; this append checks that a
// project adding it again does not break the round-trip. (routes/web.php edits
// are NOT re-read by the live php -S worker, so this line is really a probe
// that the existing protection stays correct.)
file_put_contents($proj . '/routes/web.php', "\n" . '\\Widdx\\Router::use(\\Widdx\\CsrfMiddleware::class);' . "\n", FILE_APPEND);

$r = http($port, 'POST', '/users', ['name' => 'No Token', 'email' => 'nt@test.local']);
ok('POST without token is 419', $r['status'] === 419, "status={$r['status']}");

$r = http($port, 'GET', '/users/create', null, $jar);
$token = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
ok('csrf token present in form', $token !== null && strlen((string) $token) >= 32, 'token=' . var_export($token, true));

if ($token !== null) {
    $r = http($port, 'POST', '/users', ['csrf_token' => $token, 'name' => 'Csrf User', 'email' => 'csrf@test.local'], $jar);
    ok('valid token accepted', $r['status'] === 302, 'status=' . $r['status']);
    $r = http($port, 'GET', '/users', null, $jar);
    ok('csrf-protected create worked', str_contains($r['body'], 'Csrf User'));

    $r = http($port, 'POST', '/users', ['csrf_token' => 'deadbeefdeadbeef', 'name' => 'X', 'email' => 'x@test.local'], $jar);
    ok('bogus token is 419', $r['status'] === 419, "status={$r['status']}");
}

// ---------------------------------------------------- 6. security headers

sec('SecurityHeaders on every web response (ambient web group)');

file_put_contents($proj . '/routes/web.php', "\n" . '\\Widdx\\Router::use(\\Widdx\\SecurityHeaders::class);' . "\n", FILE_APPEND);
$r = http($port, 'GET', '/', null, $jar);
ok('X-Frame-Options set', ($r['headers']['x-frame-options'] ?? '') === 'SAMEORIGIN', json_encode($r['headers']));
ok('X-Content-Type-Options set', ($r['headers']['x-content-type-options'] ?? '') === 'nosniff');
ok('Referrer-Policy set', ($r['headers']['referrer-policy'] ?? '') === 'strict-origin-when-cross-origin');

sec('ThrottleMiddleware rate limiting');

$codes = [];
for ($i = 0; $i < 3; $i++) {
    $r = http($port, 'GET', '/it-throttle', null, $jar);
    $codes[] = $r['status'];
}
ok('first two hits pass, third is 429', $codes === [200, 200, 429], json_encode($codes));

// ------------------------------------------------------------ 7. Arabic locale

sec('Arabic locale (APP_LOCALE=ar)');

file_put_contents($proj . '/.env', "APP_LOCALE=ar\n");
stop_server($pid, $port);
$port = pick_port();
$pid = start_server($proj, $port);
$jar = [];
$r = http($port, 'GET', '/users/create', null, $jar);
ok('html tag is RTL under Arabic locale', str_contains($r['body'], 'lang="ar" dir="rtl"'), substr($r['body'], 0, 200));
$token = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
$r = http($port, 'POST', '/it-validate', [
    'csrf_token' => (string) $token,
    'name' => '',
    'email' => 'admin@example.com',
    'password' => 'aaaa1111',
    'password_confirmation' => 'bbbb2222',
], $jar);
$data = json_decode($r['body'], true);
ok('required message in Arabic', str_contains($data['errors']['name'][0] ?? '', 'مطلوب'), $r['body']);
ok('unique message in Arabic', str_contains($data['errors']['email'][0] ?? '', 'مستخدم بالفعل'), $r['body']);

// ------------------------------------------------------- 8. CLI generators

sec('CLI generators inside the scaffold (+ lint)');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:resource Article');
ok('make:resource exits 0', $code === 0, $out);
$ctrl = $proj . '/app/Controllers/ArticleController.php';
ok('ArticleController generated', is_file($ctrl));
if (is_file($ctrl)) {
    [$lc, $lo] = shell(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ctrl));
    ok('generated controller parses', $lc === 0, $lo);
    ok('controller uses real $items var', str_contains((string) file_get_contents($ctrl), '$items'));
}

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:model Widget');
ok('make:model exits 0', $code === 0, $out);
$model = $proj . '/app/Models/Widget.php';
ok('Widget model generated + parses', is_file($model) && shell(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($model))[0] === 0);

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:migration create_pages_table');
ok('make:migration exits 0', $code === 0, $out);
$mig = array_merge(
    glob($proj . '/database/migrations/*create_pages_table.php') ?: [],
    glob($proj . '/database/migrations/*CreatePagesTable.php') ?: []
);
ok('migration file created', count($mig) > 0, 'ls: ' . shell('ls ' . escapeshellarg($proj . '/database/migrations'))[1]);
if (!empty($mig)) {
    ok('migration table inferred as pages', str_contains((string) file_get_contents($mig[0]), 'pages'));
    ok('migration parses', shell(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($mig[0]))[0] === 0);
}

// ------------------------------------------- 8b. zero-code CRUD (make:crud)

sec('Zero-Code CRUD: make:crud + built-in views');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Post title:string body:text? views:integer? price:float? published:bool?');
ok('make:crud exits 0', $code === 0, $out);
ok('Post model generated with NO controller', is_file($proj . '/app/Models/Post.php') && !is_file($proj . '/app/Controllers/PostController.php'));
ok('route appended automatically as protected by default', str_contains((string) file_get_contents($proj . '/routes/web.php'), "resource('posts');"));
// This section tests the generated CRUD UI and mutations, not access control.
// Opt in explicitly after asserting the generator itself is protected by default.
integration_public_resource($proj, 'posts');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('make:crud migration applies', $code === 0, $out);

$r = http($port, 'GET', '/posts', null, $jar);
ok('built-in index renders with no view files', $r['status'] === 200 && str_contains($r['body'], 'Posts') && str_contains($r['body'], '+ New'), "status={$r['status']}");

$r = http($port, 'GET', '/posts/create', null, $jar);
$token = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
ok('built-in create form auto-generated from rules', $r['status'] === 200 && $token !== null && str_contains($r['body'], 'name="title"'), 'no title input/token');

// HTML5 blocks decimals unless the input says step="any" — a float column
// without it silently refuses 39.9 in every browser.
ok('float rule renders step="any"', (bool) preg_match('#name="price"[^>]*step="any"#', $r['body']), 'price input missing step=any');
ok('integer rule renders step="1"', (bool) preg_match('#name="views"[^>]*step="1"#', $r['body']), 'views input missing step=1');

// form.php is included by create.php/edit.php, which add no wrapper of their
// own — if it stops emitting layout-top the page renders with no <head> or CSS.
ok('built-in create page is a full HTML document', str_starts_with(ltrim($r['body']), '<!DOCTYPE html>') && str_contains($r['body'], '</html>'), 'layout-top/bottom missing');

// A bool rule must render a checkbox, not a text box.
ok('bool rule renders a checkbox', (bool) preg_match('#<input[^>]*type="checkbox"[^>]*name="published"|<input[^>]*name="published"[^>]*type="checkbox"#', $r['body']), 'no checkbox for published');

if ($token !== null) {
    $r = http($port, 'POST', '/posts', ['csrf_token' => $token, 'title' => 'Hello Zero', 'body' => 'nothing', 'published' => '1'], $jar);
    ok('zero-code store redirects', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/posts', null, $jar);
    ok('built-in list shows the new record', str_contains($r['body'], 'Hello Zero'));
    // 1/0 in a list is unreadable — a bool column renders yes/no.
    ok('built-in list renders the bool column as yes', str_contains($r['body'], '✓ yes'), 'no yes marker');
    // an empty optional cell renders a dash instead of nothing at all
    ok('built-in list marks empty cells', str_contains($r['body'], 'color:#cbd5e1'), 'no empty-cell marker');

    $postId = null;
    if (preg_match('#/posts/(\d+)"[^>]*>View|/posts/(\d+)#', $r['body'], $m)) {
        $postId = (int) ($m[1] ?: $m[2]);
    }
    if ($postId) {
        $r = http($port, 'GET', '/posts/' . $postId, null, $jar);
        ok('built-in show page works', $r['status'] === 200 && str_contains($r['body'], 'Hello Zero'), "status={$r['status']}");

        $r = http($port, 'GET', '/posts/' . $postId . '/edit', null, $jar);
        ok('built-in edit page is a full HTML document', str_starts_with(ltrim($r['body']), '<!DOCTYPE html>') && str_contains($r['body'], '</html>'), 'no layout');
        ok('edit form prefills the stored value', str_contains($r['body'], 'value="Hello Zero"'), 'title not prefilled');
        // unchecked boxes submit nothing; without the hidden 0 the old value
        // would survive every save and the field could never be turned off.
        ok('checkbox has a hidden 0 companion', (bool) preg_match('#name="published" value="0">#', $r['body']), 'no hidden 0');
        ok('stored true prefills the checkbox as checked', (bool) preg_match('#name="published"[^>]*value="1"[^>]*checked#', $r['body']), 'not checked');
        if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m2)) {
            $r = http($port, 'POST', '/posts/' . $postId, ['csrf_token' => $m2[1], '_method' => 'PUT', 'title' => 'Renamed Zero', 'body' => 'edited', 'published' => '0'], $jar);
            ok('zero-code update redirects', $r['status'] === 302, "status={$r['status']}");
            $r = http($port, 'GET', '/posts/' . $postId, null, $jar);
            ok('zero-code update persisted', str_contains($r['body'], 'Renamed Zero'), 'still the old title');
            // an unchecked box sends no field at all — the detail page says no
            ok('built-in show renders the bool column as no', str_contains($r['body'], '✗ no') && !str_contains($r['body'], '✓ yes'), 'bool cell wrong');
        } else {
            ok('edit page carries a csrf token', false, 'no token in edit form');
        }
    } else {
        ok('post id extractable', false, 'no id found');
    }
}

// ------------------------------------------ 8c. relations: *_id becomes select

sec('Relations: make:crud with author_id renders a live select');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Author name:string');
ok('make:crud Author exits 0', $code === 0, $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Book title:string author_id:integer');
ok('make:crud Book (author_id) exits 0', $code === 0, $out);
integration_public_resource($proj, 'authors');
integration_public_resource($proj, 'books');
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('relation migrations apply', $code === 0, $out);

$r = http($port, 'GET', '/authors/create', null, $jar);
$token = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
if ($token !== null) {
    $r = http($port, 'POST', '/authors', ['csrf_token' => $token, 'name' => 'Frank Herbert'], $jar);
    ok('author created via built-in form', $r['status'] === 302, "status={$r['status']}");
}

$r = http($port, 'GET', '/books/create', null, $jar);
ok(
    'author_id auto-renders as select of authors',
    $r['status'] === 200
        && str_contains($r['body'], 'id="f_author_id"')
        && str_contains($r['body'], '<select')
        && str_contains($r['body'], 'Frank Herbert'),
    substr($r['body'], 0, 200)
);

$aid = null;
$r = http($port, 'GET', '/authors', null, $jar);
if (preg_match('#/authors/(\d+)#', $r['body'], $m)) {
    $aid = (int) $m[1];
}
$token = null;
$r = http($port, 'GET', '/books/create', null, $jar);
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
if ($aid !== null && $token !== null) {
    $r = http($port, 'POST', '/books', ['csrf_token' => $token, 'title' => 'Dune', 'author_id' => (string) $aid], $jar);
    ok('book linked to author via inferred select', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/books', null, $jar);
    ok('book listed', str_contains($r['body'], 'Dune'));
} else {
    ok('author id/token extractable', false, 'aid=' . var_export($aid, true));
}

// ---------------------------------------------------------------- 8d. uploads

sec('File uploads through zero-code CRUD (multipart)');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Photo caption:string cover:image');
ok('make:crud Photo (image column) exits 0', $code === 0, $out);
integration_public_resource($proj, 'photos');
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('photo migration applies', $code === 0, $out);

$r = http($port, 'GET', '/photos/create', null, $jar);
ok(
    'create form renders file input with multipart enctype',
    $r['status'] === 200
        && str_contains($r['body'], 'enctype="multipart/form-data"')
        && str_contains($r['body'], 'type="file"'),
    substr($r['body'], 0, 200)
);
$token = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $token = $m[1];
}
if ($token !== null) {
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $b = 'widdx-it-boundary';
    $mp = "--{$b}\r\nContent-Disposition: form-data; name=\"csrf_token\"\r\n\r\n{$token}\r\n"
        . "--{$b}\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\nSea cliff\r\n"
        . "--{$b}\r\nContent-Disposition: form-data; name=\"cover\"; filename=\"sea.gif\"\r\n"
        . "Content-Type: image/gif\r\n\r\n" . $gif . "\r\n--{$b}--\r\n";
    $r = http($port, 'POST', '/photos', null, $jar, $mp, 'multipart/form-data; boundary=' . $b);
    ok('multipart upload accepted', $r['status'] === 302, "status={$r['status']} body=" . substr($r['body'], 0, 200));

    $stored = glob($proj . '/storage/app/photos/*sea.gif');
    ok('image physically stored in storage/app/photos', is_array($stored) && count($stored) > 0);

    $r = http($port, 'GET', '/photos', null, $jar);
    ok('caption listed, path stored in db', str_contains($r['body'], 'Sea cliff') && str_contains($r['body'], 'sea.gif'));
} else {
    ok('photos create token extracted', false);
}

// ---------------------------------------------------------------- 8e. auth

sec('Zero-code auth: make:auth full flow (register/verify/reset/policy)');

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:auth');
ok('make:auth exits 0', $code === 0, $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('auth migrations apply', $code === 0, $out);

$csrf = static function (string $path) use ($port, &$jar): ?string {
    $r = http($port, 'GET', $path, null, $jar);
    return preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m) ? $m[1] : null;
};
// /login redirects authenticated users away, so grab logout/reset tokens
// from the verify-notice page which renders for everyone.
$authState = static function () use ($port, &$jar): bool {
    $r = http($port, 'GET', '/it-auth-check', null, $jar);
    $j = json_decode($r['body'], true);
    return ($j['check'] ?? false) === true;
};
$readMail = static function () use ($proj): string {
    $all = '';
    foreach (glob($proj . '/storage/logs/*') ?: [] as $f) {
        if (is_file($f)) {
            $all .= (string) @file_get_contents($f);
        }
    }
    return $all;
};

$r = http($port, 'GET', '/register', null, $jar);
ok('built-in register form renders', $r['status'] === 200 && str_contains($r['body'], 'password_confirmation'), substr($r['body'], 0, 160));

$tok = $csrf('/register');
$r = http($port, 'POST', '/register', [
    'csrf_token' => (string) $tok,
    'name' => 'Sara',
    'email' => 'sara@widdx.test',
    'password' => 'supersecret1',
    'password_confirmation' => 'supersecret1',
], $jar);
ok('register 302', $r['status'] === 302, "status={$r['status']}");
ok('register logs in automatically', $authState(), 'still guest after register');

$logs = $readMail();
ok('verification mail logged with link', (bool) preg_match('#/verify-email/([a-f0-9]{64})\?email=([^&\s"]+)#', $logs, $vm), 'no link found');
if (isset($vm[1])) {
    $r = http($port, 'GET', '/verify-email/' . $vm[1] . '?email=' . $vm[2], null, $jar);
    ok('verification link accepted', $r['status'] === 302, "status={$r['status']}");
    $pdb = project_pdo($proj . '/database.sqlite'); // 2.7: honours DB_CONNECTION
    $row = $pdb->query("SELECT email_verified_at FROM users WHERE email='sara@widdx.test'")->fetch(PDO::FETCH_ASSOC);
    ok('email_verified_at persisted', !empty($row['email_verified_at']));
    $pdb = null;
}

$tok = $csrf('/email-verification');
http($port, 'POST', '/logout', ['csrf_token' => (string) $tok], $jar);
ok('logout clears session', $authState() === false, 'still logged in after logout');

$tok = $csrf('/login');
$r = http($port, 'POST', '/login', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test', 'password' => 'WRONGpass1'], $jar);
if (getenv('ITTRACE')) {
    $sid = $jar['PHPSESSID'] ?? '';
    $sf = @file_get_contents($proj . '/storage/sessions/sess_' . $sid);
    fwrite(STDERR, 'BADPOST LOC=' . ($r['headers']['location'] ?? '?') . "\nSESSFILE=" . substr((string) $sf, 0, 500) . "\n---\n");
}
ok('failed login redirects back to /login', $r['status'] === 302, "status={$r['status']} toklen=" . strlen((string) $tok) . ' loc=' . ($r['headers']['location'] ?? '?') . ' sc=' . ($r['headers']['set-cookie'] ?? '-'));
$r = http($port, 'GET', '/login', null, $jar);
ok('failed login shows translated error', str_contains($r['body'], 'do not match') || str_contains($r['body'], 'لا تطابق'), 'jar=' . json_encode($jar) . ' sc=' . ($r['headers']['set-cookie'] ?? '-') . ' bodytail=' . substr(preg_replace('/\s+/', ' ', $r['body']), -260));
$tok = $csrf('/login');
http($port, 'POST', '/login', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test', 'password' => 'supersecret1'], $jar);
if (getenv('ITTRACE')) {
    $pdb2 = project_pdo($proj . '/database.sqlite'); // 2.7: honours DB_CONNECTION
    $hash = (string) $pdb2->query("SELECT password FROM users WHERE email='sara@widdx.test'")->fetchColumn();
    $uid = (int) $pdb2->query("SELECT id FROM users WHERE email='sara@widdx.test'")->fetchColumn();
    fwrite(STDERR, 'GOODLOGIN hashlen=' . strlen($hash) . ' verify=' . var_export(password_verify('supersecret1', $hash), true) . " uid={$uid}\n");
    $sid2 = $jar['PHPSESSID'] ?? '';
    fwrite(STDERR, 'GOODLOGIN SESSFILE=' . substr((string) @file_get_contents($proj . '/storage/sessions/sess_' . $sid2), 0, 300) . "\n");
}
ok('login with correct credentials works', $authState(), 'login failed');

// password reset end-to-end (log the session out first so the
// old-vs-new password checks measure fresh attempts, not the live session)
$tok = $csrf('/email-verification');
http($port, 'POST', '/logout', ['csrf_token' => (string) $tok], $jar);
$tok = $csrf('/forgot-password');
$r = http($port, 'POST', '/forgot-password', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test'], $jar);
ok('forgot-password accepted', $r['status'] === 302, "status={$r['status']}");
$logs = $readMail();
ok('reset link mailed', (bool) preg_match('#/reset-password/([a-f0-9]{64})\?email=([^&\s"]+)#', $logs, $rm), 'no reset link');
if (isset($rm[1])) {
    $r = http($port, 'GET', '/reset-password/' . $rm[1] . '?email=' . $rm[2], null, $jar);
    ok('reset form renders for this token', $r['status'] === 200 && str_contains($r['body'], 'Reset password'), "status={$r['status']}");
    $tok = $csrf('/reset-password/' . $rm[1]);
    $r = http($port, 'POST', '/reset-password', [
        'csrf_token' => (string) $tok,
        'token' => $rm[1],
        'email' => 'sara@widdx.test',
        'password' => 'brandnewpass9',
        'password_confirmation' => 'brandnewpass9',
    ], $jar);
    ok('reset completes', $r['status'] === 302, "status={$r['status']}");

    $tok = $csrf('/login');
    http($port, 'POST', '/login', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test', 'password' => 'supersecret1'], $jar);
    ok('old password rejected after reset', $authState() === false, 'old password still works');

    $tok = $csrf('/login');
    http($port, 'POST', '/login', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test', 'password' => 'brandnewpass9'], $jar);
    ok('new password works', $authState(), 'new password rejected');

    $tok = $csrf('/email-verification');
    http($port, 'POST', '/logout', ['csrf_token' => (string) $tok], $jar);
}

// Policies guard zero-code CRUD with NO wiring code
$policyDir = $proj . '/app/Policies';
if (!is_dir($policyDir)) {
    mkdir($policyDir, 0777, true);
}
// 8e runs as a LOGGED-IN member: a denied member is 403, a denied guest
// would be 302 to /login (fail-closed semantics of 1.2).
$tok = $csrf('/login');
$r = http($port, 'POST', '/login', ['csrf_token' => (string) $tok, 'email' => 'sara@widdx.test', 'password' => 'brandnewpass9'], $jar);
ok('member logged in for policy checks', $authState(), 'not logged in');
file_put_contents($policyDir . '/BookPolicy.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Policies;
class BookPolicy
{
    public function viewAny(mixed $user): bool
    {
        return false;
    }
}
PHP);
$r = http($port, 'GET', '/books', null, $jar);
ok('policy deny → 403 automatically', $r['status'] === 403, "status={$r['status']}");
file_put_contents($policyDir . '/BookPolicy.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Policies;
// Deny-by-default semantics (like Laravel): a policy must implement every
// ability it wants to keep open — absent methods mean DENIED.
class BookPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, mixed $book = null): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }

    public function update(mixed $user, mixed $book = null): bool
    {
        return true;
    }

    public function delete(mixed $user, mixed $book = null): bool
    {
        return true;
    }
}
PHP);
$r = http($port, 'GET', '/books', null, $jar);
ok('policy allow → list visible again', $r['status'] === 200 && str_contains($r['body'], 'Dune'), "status={$r['status']}");

// ---------------------------------------------------------------- 8f. ops

sec('Ops: trusted proxies, foreign keys, queue retry, scheduler');

// /it-ip + setTrustedProxies were registered with the other probes, before the
// server started (see the note in that block).
mkdir($proj . '/app/Jobs', 0777, true);
file_put_contents($proj . '/app/Jobs/ItFailingJob.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Jobs;
use Widdx\Job;
class ItFailingJob extends Job
{
    protected int $tries = 1;
    public function handle(array $data): void
    {
        throw new \RuntimeException('it-planned-failure');
    }
}
PHP);

// 1) trusted proxies: XFF ignored without them honored with them
$r = http($port, 'GET', '/it-ip', null, $jar);
if (preg_match('#<strong>(?:Type|Message):</strong> ([^<]*)#', $r['body'], $mm)) {
    fwrite(STDERR, "IT-IP ERROR PAGE: {$mm[1]}\n");
}
$j = json_decode($r['body'], true);
ok('no XFF → peer ip', ($j['ip'] ?? '') === '127.0.0.1', $r['body']);
$r = http($port, 'GET', '/it-ip', null, $jar, null, null, ['X-Forwarded-For: 203.0.113.7, 10.0.0.9']);
$j = json_decode($r['body'], true);
ok('trusted proxy → right-most hop', ($j['ip'] ?? '') === '10.0.0.9', $r['body']);

// 2) make:crud fk(Author): real REFERENCES + exists rule + select
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Author name:string >/dev/null 2>&1; '
    . escapeshellarg(PHP_BINARY) . ' widdx make:crud Book title:string "author:fk(Author)"');
ok('make:crud with fk(Author) exits 0', $code === 0, $out);
$mig = glob($proj . '/database/migrations/*create_books_table.php');
$mig = $mig === false ? [] : (function (array $f): array { rsort($f); return $f; })($mig);
ok('latest books migration builds a Schema FK (foreignId->constrained authors)', count($mig) > 0 && str_contains((string) file_get_contents($mig[0]), "foreignId('author_id')->constrained('authors')"));
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('fk migrations apply', $code === 0, $out);

// php -S serves every request from ONE worker process, so a class it already
// loaded stays loaded: the Book model rewritten by make:crud above would still
// be the earlier version (no exists rule) and the fk probes below would test a
// stale class — which is exactly how this section flaked between PHP versions.
stop_server($pid, $port);
// Same port: the $csrf helper captured $port by value, so a new port would make
// every token it grabs come back empty (419 everywhere).
$pid = start_server($proj, $port);
$jar = [];

$tok = $csrf('/authors/create');
$r = http($port, 'POST', '/authors', ['csrf_token' => (string) $tok, 'name' => 'Ursula'], $jar);
ok('author created', $r['status'] === 302, "status={$r['status']}");
$r = http($port, 'GET', '/books/create', null, $jar);
if (preg_match('#<strong>(?:Type|Message):</strong> ([^<]*)#', $r['body'], $mm)) {
    fwrite(STDERR, "BOOKS-CREATE ERROR PAGE: {$mm[1]}\n");
}
ok('author_id auto-select present', $r['status'] === 200 && str_contains($r['body'], 'id="f_author_id"') && str_contains($r['body'], 'Ursula'), substr($r['body'], 0, 160));

$aid = null;
$r = http($port, 'GET', '/authors', null, $jar);
if (preg_match('#/authors/(\d+)#', $r['body'], $m)) {
    $aid = (int) $m[1];
}
// exists: rule rejects a dangling author id
$tok = $csrf('/books/create');
$r = http($port, 'POST', '/books', ['csrf_token' => (string) $tok, 'title' => 'Ghost', 'author_id' => '999999'], $jar);
// validation failure → back to /books/create; a store → /books. Keep the target
// so a failure here says which path ran instead of just "Ghost is present".
$ghostTo = (string) ($r['headers']['location'] ?? '');
if ($aid !== null) {
    $tok = $csrf('/books/create');
    $r = http($port, 'POST', '/books', ['csrf_token' => (string) $tok, 'title' => 'The Enchanted Night', 'author_id' => (string) $aid], $jar);
    ok('valid fk value stored', $r['status'] === 302, "status={$r['status']}");
}
$r = http($port, 'GET', '/books', null, $jar);
// The proof is the row itself: a stored Ghost appears as a ">Ghost<" table cell,
// while a flashed old input on the list page never does. The redirect target is
// reported for diagnosis only.
ok('exists-rule rejected dangling fk (Ghost not stored)', !str_contains($r['body'], '>Ghost<'),
    "location={$ghostTo}");
ok('valid book listed', str_contains($r['body'], 'The Enchanted Night'), substr($r['body'], 0, 160));

// 3) queue: dispatch via the CLI (fresh process, real driver resolution),
//    work it into failed_jobs, then retry it
file_put_contents($proj . '/.env', "\nQUEUE_DRIVER=database\n", FILE_APPEND);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx queue:push "App\\Jobs\\ItFailingJob" "{}"');
ok('queue:push dispatches the job', $code === 0 && str_contains($out, 'Pushed job #'), $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx queue:work --max=5');
ok('worker processed the failing job', $code === 0 && str_contains($out, 'Processed 1 job'), $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && QUEUE_DRIVER=database ' . escapeshellarg(PHP_BINARY) . ' widdx queue:failed');
ok('failed_jobs lists the dead job', $code === 0 && str_contains($out, 'ItFailingJob') && str_contains($out, 'it-planned-failure'), $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && QUEUE_DRIVER=database ' . escapeshellarg(PHP_BINARY) . ' widdx queue:retry all');
ok('queue:retry re-queues', $code === 0 && str_contains($out, 'Re-queued 1'), $out);

// 4) scheduler: one entry, bucket state prevents double-run
// Minimal project scaffolds may not have created this runtime directory yet.
if (!is_dir($proj . '/storage/app')) {
    mkdir($proj . '/storage/app', 0775, true);
}
file_put_contents($proj . '/routes/console.php', <<<'PHP'
<?php
\Widdx\Schedule::call(function () {
    file_put_contents(WIDDX_BASE_PATH . '/storage/app/sched.flag', date('c'));
})->everyMinute();
PHP);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx schedule:run');
ok('schedule:run fires a due task', $code === 0 && str_contains($out, 'Ran 1'), $out);
ok('scheduled closure wrote the flag', is_file($proj . '/storage/app/sched.flag'));
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx schedule:run');
ok('same minute bucket → nothing due', $code === 0 && str_contains($out, 'Nothing due'), $out);

// ---------------------------------------------------------------- 9. new:api

sec('php widdx new:api scaffold');

[$code, $out] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new:api apiapp');
ok('new:api exits 0', $code === 0, $out);
ok('api routes generated', str_contains((string) @file_get_contents($TMP . '/apiapp/routes/web.php'), 'apiResource('));
$phpBin = escapeshellarg(PHP_BINARY);
$lintOut = shell('find ' . escapeshellarg($TMP . '/apiapp') . ' -name "*.php" | while read -r f; do ' . $phpBin . ' -l "$f" >/dev/null 2>&1 || echo "PARSEFAIL $f"; done')[1];
ok('every apiapp file parses', trim($lintOut) === '', $lintOut);

// ------------------------------------------------------------- 10. real SMTP

sec('SmtpMail against a fake SMTP server (real socket)');

$smtpPort = pick_port();
$transcript = $TMP . '/smtp-transcript.txt';
$ready = $TMP . '/smtp-ready.txt';

$serverScript = $TMP . '/fake-smtp.php';
file_put_contents($serverScript, <<<'SMTP'
<?php
$listen = @stream_socket_server('tcp://127.0.0.1:' . (int) $argv[1], $en, $em);
if (!$listen) {
    exit(1);
}
file_put_contents($argv[2], "READY\n");
$conns = 0;
while ($conns < 3) {
    $sock = @stream_socket_accept($listen, 25);
    if (!$sock) {
        break;
    }
    $log = '';
    $authStage = 0;
    fwrite($sock, "220 fake ESMTP ready\r\n");
    while (!feof($sock)) {
        $line = fgets($sock, 4096);
        if ($line === false) {
            break;
        }
        $log .= $line;
        $u = strtoupper(trim($line));
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) {
            fwrite($sock, "250-fake\r\n250 AUTH LOGIN\r\n");
        } elseif ($u === 'AUTH LOGIN') {
            $authStage = 1;
            fwrite($sock, "334 VXNlcm5hbWU6\r\n");
        } elseif ($authStage === 1) {
            $authStage = 2;
            fwrite($sock, "334 U2VjcmV0Og==\r\n");
        } elseif ($authStage === 2) {
            $authStage = 0;
            fwrite($sock, "235 authenticated\r\n");
        } elseif (str_starts_with($u, 'MAIL FROM') || str_starts_with($u, 'RCPT TO')) {
            fwrite($sock, "250 ok\r\n");
        } elseif ($u === 'DATA') {
            fwrite($sock, "354 send it\r\n");
            while (!feof($sock)) {
                $l2 = fgets($sock, 4096);
                if ($l2 === false) {
                    break;
                }
                $log .= $l2;
                if (rtrim($l2, "\r\n") === '.') {
                    break;
                }
            }
            fwrite($sock, "250 queued\r\n");
        } elseif ($u === 'QUIT') {
            fwrite($sock, "221 bye\r\n");
            break;
        } else {
            fwrite($sock, "250 ok\r\n");
        }
    }
    fclose($sock);
    file_put_contents($argv[3], $log, FILE_APPEND);
    $conns++;
}
fclose($listen);
SMTP);

$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($serverScript) . ' ' . $smtpPort
    . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($transcript),
    [1 => ['file', $TMP . '/smtp-out.log', 'w'], 2 => ['file', $TMP . '/smtp-out.log', 'a']],
    $pipes
);

$envPath = $ROOT . '/.env';
$GLOBALS['main_env_created'] = !is_file($envPath);
if ($GLOBALS['main_env_created']) {
    file_put_contents($envPath, "MAIL_DRIVER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT={$smtpPort}\nMAIL_USERNAME=user\nMAIL_PASSWORD=pass\nMAIL_ENCRYPTION=\nMAIL_FROM=sender@test.local\nMAIL_FROM_NAME=IT\n");
}

if (!is_resource($proc) || !$GLOBALS['main_env_created']) {
    ok('SMTP conversation (skipped — server unavailable or a real .env exists)', true);
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
} else {
    for ($i = 0; $i < 40 && !is_file($ready); $i++) {
        usleep(100000);
    }

    if (is_file($ROOT . '/vendor/autoload.php')) {
        require $ROOT . '/vendor/autoload.php';
    } else {
        spl_autoload_register(static function (string $class) use ($ROOT): void {
            $map = ['Widdx\\' => $ROOT . '/core/', 'App\\' => $ROOT . '/app/', 'Psr\\' => $ROOT . '/polyfill/'];
            foreach ($map as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                        return;
                    }
                }
            }
        });
    }
    require_once $ROOT . '/core/helpers.php';
    \Widdx\App::basePath($ROOT);
    \Widdx\App::loadEnv($ROOT);
    \Widdx\App::loadConfig($ROOT . '/config');

    $mailer = new \Widdx\SmtpMail();
    $sent = $mailer->raw('rcpt@test.local', 'Hello IT', "line1\r\n.Evil\r\nline3", ['Cc' => 'cc@test.local']);
    ok('SmtpMail::raw returns true', $sent === true);

    $mailer->raw('rcpt2@test.local', 'Second', 'body', ['Bcc' => 'hidden@test.local']);

    $attFile = $TMP . '/hello-attach.txt';
    file_put_contents($attFile, 'ATTACHED-CONTENT-42');
    $mailer->attach($attFile)->raw('rcpt3@test.local', 'With File', 'see attached');

    proc_terminate($proc);
    proc_close($proc);
    usleep(200000);
    $log = (string) @file_get_contents($transcript);

    ok('EHLO handshake', str_contains($log, 'EHLO'));
    ok('AUTH LOGIN + base64 credentials', str_contains($log, 'AUTH LOGIN') && str_contains($log, base64_encode('pass')));
    ok('MAIL FROM from config', str_contains($log, 'MAIL FROM:<sender@test.local>'));
    ok('To + Cc in envelope', str_contains($log, 'RCPT TO:<rcpt@test.local>') && str_contains($log, 'RCPT TO:<cc@test.local>'));
    ok('dot-stuffing applied to body', str_contains($log, "\r\n..Evil"));
    ok('Bcc in envelope but stripped from data', str_contains($log, 'RCPT TO:<hidden@test.local>') && !str_contains($log, 'Bcc:'));
    ok('attachment sent as multipart/mixed', str_contains($log, 'multipart/mixed') && str_contains($log, 'filename="hello-attach.txt"'));
    ok('attachment base64 payload on the wire', str_contains($log, base64_encode('ATTACHED-CONTENT-42')));

    $inj = false;
    try {
        $mailer->raw('x@y.z', 's', 'b', ['X-Evil' => "ok\r\nBcc: spam@evil.z"]);
    } catch (\Widdx\Exceptions\MailException) {
        $inj = true;
    }
    ok('header injection blocked with MailException', $inj);

    $bad = new \Widdx\SmtpMail();
    // Force an unreachable endpoint via reflection (PHPStan-clean, and unlike
    // a top-level `static function` + call() it actually binds).
    foreach ([['host', '127.0.0.1'], ['port', 1], ['encryption', '']] as [$prop, $val]) {
        $rp = new \ReflectionProperty(\Widdx\SmtpMail::class, $prop);
        $rp->setValue($bad, $val);
    }
    $caught = false;
    try {
        $bad->raw('a@b.c', 's', 'b');
    } catch (\Widdx\Exceptions\MailException $e) {
        $caught = str_contains($e->getMessage(), 'SMTP connection');
    }
    ok('unreachable server surfaces MailException', $caught);
}

// --------------------------------------------- 9. Shared hosting: web installer

sec('Shared hosting style: fresh project + /install.php, no terminal, no .env');

// Simulate "download zip + FTP upload": a bare `widdx new` scaffold that
// still has NO .env (only .env.example) — exactly what a shared-hosting
// user uploads. One local command pre-generates a CRUD migration, the way
// a developer would before uploading (or via a teammate).
[$code, $out] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new hostapp');
ok('widdx new hostapp exits 0', $code === 0, $out);
$proj2 = $TMP . '/hostapp';
ok('scaffold ships public/install.php', is_file($proj2 . '/public/install.php'));
ok('scaffold ships core/Installer.php', is_file($proj2 . '/core/Installer.php'));
ok('fresh project has NO .env (installer stays available)', !is_file($proj2 . '/.env'));

[$code, $out] = shell('cd ' . escapeshellarg($proj2) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Note title:string');
ok('make:crud in hostapp exits 0', $code === 0, $out);
// Installer scenarios exercise the CRUD workflow itself; public access is an
// explicit test-fixture choice, not the generator's default.
integration_public_resource($proj2, 'notes');

[$code, $out] = shell('cd ' . escapeshellarg($proj2) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Credential name:string password:string is_admin:boolean? role:string? email_verified_at:string? remember_token:string? api_token:string? permissions:string?');
ok('make:crud Credential password fixture exits 0', $code === 0, $out);
$credentialModelSource = (string) file_get_contents($proj2 . '/app/Models/Credential.php');
preg_match('/protected array \$fillable = \[([^\]]*)\];/', $credentialModelSource, $credentialFillableMatch);
$credentialFillable = $credentialFillableMatch[1] ?? '';
ok('H-02 generated fillable excludes privilege and token fields',
    !str_contains($credentialFillable, "'is_admin'") && !str_contains($credentialFillable, "'role'")
        && !str_contains($credentialFillable, "'email_verified_at'") && !str_contains($credentialFillable, "'remember_token'")
        && !str_contains($credentialFillable, "'api_token'") && !str_contains($credentialFillable, "'permissions'"),
    $credentialFillable);
integration_public_resource($proj2, 'credentials');
file_put_contents($proj2 . '/routes/web.php', "\napiResource('credentials')->public();\n", FILE_APPEND);

// 1.5 needs a route that THROWS. Register it BEFORE the server starts: a live
// `file_put_contents(..., FILE_APPEND)` on routes/web.php right before the very
// next request can be served from opcache's previously-compiled copy (it only
// revalidates mtime every ~2s), so the freshly-appended route 404s. Registered
// up front it is part of the first compile and always present.
file_put_contents($proj2 . '/routes/web.php', "\nget('/it-boom', function () { throw new \\RuntimeException('SECRET-TRACE-15'); });\n", FILE_APPEND);

// 2.3: JSON / bearer handling over real HTTP. Registered up front (same reason
// as /it-boom). Under /api, browser requests with a session cookie require CSRF;
// stateless API clients without that cookie remain exempt.
file_put_contents(
    $proj2 . '/routes/web.php',
    "\npost('/api/it-json', function () {\n    \$r = request();\n    return \\Widdx\\Response::json(['name' => \$r->input('name'), 'bearer' => \$r->bearerToken(), 'age' => \$r->json('age')]);\n});\n",
    FILE_APPEND
);

// H-01 regression probe: session-authenticated API writes must require CSRF.
file_put_contents(
    $proj2 . '/routes/web.php',
    "\nget('/api/it-csrf-token', fn() => \\Widdx\\Response::json(['token' => request()->csrfToken()]));\n"
    . "\nget('/api/it-session-login', function () { \\Widdx\\Auth::login(\\App\\Models\\Credential::findOrFail(1)); return \\Widdx\\Response::json(['ok' => true]); });\n"
    . "\nget('/api/it-csrf', fn() => \\Widdx\\Response::json(['mutated' => false]));\n"
    . "\npost('/api/it-csrf', fn() => \\Widdx\\Response::json(['mutated' => true]));\n"
    . "\nput('/api/it-csrf', fn() => \\Widdx\\Response::json(['mutated' => true]));\n"
    . "\npatch('/api/it-csrf', fn() => \\Widdx\\Response::json(['mutated' => true]));\n"
    . "\ndelete('/api/it-csrf', fn() => \\Widdx\\Response::json(['mutated' => true]));\n",
    FILE_APPEND
);

$port2 = pick_port();
$pid2 = start_server($proj2, $port2);
$jar2 = [];

$r = http($port2, 'GET', '/install.php', null, $jar2);
ok('install.php serves the Arabic form', $r['status'] === 200 && str_contains($r['body'], 'تنصيب') && str_contains($r['body'], 'dir="rtl"'), substr($r['body'], 0, 200));

// 1.10: the form must carry an installer CSRF token, and the proof-of-
// possession key must exist on disk WITHOUT ever being rendered.
$tok10 = null;
if (preg_match('#name="installer_token" value="([^"]+)"#', $r['body'], $m10)) {
    $tok10 = $m10[1];
}
ok('1.10 installer form carries a CSRF token', is_string($tok10) && strlen((string) $tok10) >= 32, 'token=' . var_export($tok10, true));
$keyFile = $proj2 . '/storage/app/install.key';
ok('1.10 install key file created on first GET', is_file($keyFile));
$key10 = trim((string) @file_get_contents($keyFile));
ok('1.10 key value is NEVER leaked in the HTML', $key10 !== '' && !str_contains($r['body'], $key10));

$r = http($port2, 'POST', '/install.php', [
    'action' => 'install', 'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_PATH' => $proj2 . '/host.sqlite',
], $jar2);
ok('1.10 POST without installer token is refused, no .env written', $r['status'] === 200 && !is_file($proj2 . '/.env') && str_contains($r['body'], 'CSRF'), 'status=' . $r['status']);
$r = http($port2, 'POST', '/install.php', [
    'action' => 'install', 'installer_token' => 'deadbeefdeadbeefdeadbeefdeadbeef', 'install_key' => $key10,
    'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_PATH' => $proj2 . '/host.sqlite',
], $jar2);
ok('1.10 bogus installer token is refused', $r['status'] === 200 && !is_file($proj2 . '/.env'));
$r = http($port2, 'POST', '/install.php', [
    'action' => 'install', 'installer_token' => (string) $tok10, 'install_key' => 'wrong-key-wrong-key-wrong-key-xxxxx',
    'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_PATH' => $proj2 . '/host.sqlite',
], $jar2);
ok('1.10 wrong install key is refused', $r['status'] === 200 && !is_file($proj2 . '/.env') && str_contains($r['body'], 'مفتاح التنصيب'), substr($r['body'], 0, 150));

$r = http($port2, 'GET', '/', null, $jar2);
ok('welcome page reachable pre-install', $r['status'] === 200);

// Fill the installer form properly (sqlite, absolute path so php -S CWD
// cannot matter) — token from the form + key read "over FTP" from disk.
$r = http($port2, 'POST', '/install.php', [
    'action' => 'install',
    'installer_token' => (string) $tok10,
    'install_key' => $key10,
    'DB_CONNECTION' => 'sqlite',
    'DB_SQLITE_PATH' => $proj2 . '/host.sqlite',
    'APP_LOCALE' => 'ar',
    'APP_DEBUG' => '1',
], $jar2);
ok('install POST succeeds', $r['status'] === 200 && str_contains($r['body'], 'تم التنصيب'), substr($r['body'], 0, 300));
ok('.env was created', is_file($proj2 . '/.env'));
ok('1.10 .env written with 0600 perms', substr(sprintf('%o', (int) fileperms($proj2 . '/.env')), -4) === '0600', substr(sprintf('%o', (int) @fileperms($proj2 . '/.env')), -4));
ok('1.10 installer deleted itself after success', !is_file($proj2 . '/public/install.php'));
ok('1.10 install key removed once locked', !is_file($keyFile));
$env2 = (string) @file_get_contents($proj2 . '/.env');
ok('.env carries the chosen settings', str_contains($env2, 'DB_CONNECTION=sqlite') && str_contains($env2, 'APP_LOCALE=ar') && str_contains($env2, 'host.sqlite'));
ok('migration ran from the browser', is_file($proj2 . '/host.sqlite'));
ok('installer self-locked', is_file($proj2 . '/storage/app/installed.lock'));

$r = http($port2, 'GET', '/install.php', null, $jar2);
ok('install.php is 404 after install (one-shot)', $r['status'] === 404, substr($r['body'], 0, 150));
$r = http($port2, 'POST', '/install.php', ['action' => 'install', 'DB_CONNECTION' => 'sqlite'], $jar2);
ok('POST cannot bypass the lock either (404 gone, or 419 CSRF before that)', in_array($r['status'], [404, 419], true), 'status=' . $r['status']);

// The app itself runs with zero terminal involvement.
$r = http($port2, 'GET', '/notes', null, $jar2);
ok('CRUD page works after web install', $r['status'] === 200 && str_contains($r['body'], 'lang="ar" dir="rtl"'), substr($r['body'], 0, 200));

$r = http($port2, 'GET', '/notes/create', null, $jar2);
$tok2 = null;
if (preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $m)) {
    $tok2 = $m[1];
}
$r = http($port2, 'POST', '/notes', ['csrf_token' => (string) $tok2, 'title' => 'FTP-only note'], $jar2);
$r = http($port2, 'GET', '/notes', null, $jar2);
ok('record inserted through the web-installed app', $r['status'] === 200 && str_contains($r['body'], 'FTP-only note'), substr($r['body'], 0, 300));

// H-02 regression: generated CRUD must hash passwords and never render them.
$r = http($port2, 'GET', '/credentials/create', null, $jar2);
$credentialToken = preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $cm) ? $cm[1] : '';
ok('H-02 password form uses type=password with no value',
    str_contains($r['body'], 'type="password"') && !preg_match('#type="password"[^>]*value="[^"]+#', $r['body']),
    substr($r['body'], 0, 220));
$r = http($port2, 'POST', '/credentials', [
    'csrf_token' => $credentialToken, 'name' => 'Password probe', 'password' => 'secret-pass-123',
    'is_admin' => '1', 'role' => 'admin', 'email_verified_at' => '2026-01-01',
    'remember_token' => 'remember-secret', 'api_token' => 'api-secret', 'permissions' => '*',
], $jar2);
ok('H-02 generated CRUD create accepted', $r['status'] === 302, 'status=' . $r['status']);
$credentialDb = new PDO('sqlite:' . $proj2 . '/host.sqlite');
$credentialRow = $credentialDb->query("SELECT id, password FROM credentials ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$credentialId = (int) ($credentialRow['id'] ?? 0);
$credentialPassword = (string) ($credentialRow['password'] ?? '');
ok('H-02 stored password is a password_hash result',
    $credentialPassword !== 'secret-pass-123' && password_verify('secret-pass-123', $credentialPassword),
    'stored=' . substr($credentialPassword, 0, 30));
$credentialDb->exec('SELECT 1');
$credentialSensitive = $credentialDb->query('SELECT is_admin, role, email_verified_at, remember_token, api_token, permissions FROM credentials WHERE id = ' . $credentialId)->fetch(PDO::FETCH_ASSOC) ?: [];
ok('H-02 CRUD rejects submitted privilege, verification, and token fields',
    (int) ($credentialSensitive['is_admin'] ?? 0) === 0
        && ($credentialSensitive['role'] ?? null) === null
        && ($credentialSensitive['email_verified_at'] ?? null) === null
        && ($credentialSensitive['remember_token'] ?? null) === null
        && ($credentialSensitive['api_token'] ?? null) === null
        && ($credentialSensitive['permissions'] ?? null) === null,
    json_encode($credentialSensitive));
$editCredential = http($port2, 'GET', '/credentials/' . $credentialId . '/edit', null, $jar2);
$editCredentialToken = preg_match('#name="csrf_token" value="([^"]+)"#', $editCredential['body'], $em) ? $em[1] : '';
$r = http($port2, 'POST', '/credentials/' . $credentialId, [
    'csrf_token' => $editCredentialToken, '_method' => 'PUT', 'name' => 'Password probe', 'password' => '',
], $jar2);
$preservedPassword = (string) $credentialDb->query('SELECT password FROM credentials WHERE id = ' . $credentialId)->fetchColumn();
ok('H-02 empty password update preserves the existing hash',
    $r['status'] === 302 && $preservedPassword === $credentialPassword && password_verify('secret-pass-123', $preservedPassword),
    'status=' . $r['status']);
$r = http($port2, 'POST', '/credentials/' . $credentialId, [
    'csrf_token' => $editCredentialToken, '_method' => 'PUT', 'name' => 'Password probe', 'password' => 'new-secret-456',
], $jar2);
$changedPassword = (string) $credentialDb->query('SELECT password FROM credentials WHERE id = ' . $credentialId)->fetchColumn();
ok('H-02 new password update stores a new valid hash',
    $r['status'] === 302 && password_verify('new-secret-456', $changedPassword) && !password_verify('secret-pass-123', $changedPassword),
    'status=' . $r['status']);
foreach ([
    '/credentials' => 'index',
    '/credentials/' . $credentialId => 'show',
    '/credentials/' . $credentialId . '/edit' => 'edit',
] as $credentialPath => $credentialPage) {
    $r = http($port2, 'GET', $credentialPath, null, $jar2);
    ok('H-02 password hidden from CRUD ' . $credentialPage,
        $r['status'] === 200 && !str_contains($r['body'], $credentialPassword) && !str_contains($r['body'], 'secret-pass-123'),
        'status=' . $r['status']);
}
$r = http($port2, 'GET', '/api/credentials', null, $jar2, null, null, ['Accept: application/json']);
$credentialJson = json_decode($r['body'], true);
ok('H-02 API JSON omits password fields',
    $r['status'] === 200 && is_array($credentialJson) && !array_key_exists('password', $credentialJson[0] ?? []),
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 180));
$apiCredentialJar = [];
$r = http($port2, 'POST', '/api/credentials', null, $apiCredentialJar,
    '{"name":"API Password probe","password":"api-secret-pass"}', 'application/json', ['Accept: application/json']);
$apiCredentialDb = $credentialDb->prepare('SELECT password FROM credentials WHERE name = ?');
$apiCredentialDb->execute(['API Password probe']);
$apiCredentialPassword = (string) $apiCredentialDb->fetchColumn();
ok('H-02 API CRUD stores a hashed password and omits it from JSON',
    $r['status'] === 201
        && password_verify('api-secret-pass', $apiCredentialPassword)
        && !array_key_exists('password', json_decode($r['body'], true) ?: []),
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 180));

// 1.1 negative: a resource model with NO $fillable/$rules must fail loudly, never write.
file_put_contents(
    $proj2 . '/app/Models/Probeno.php',
    "<?php\n\nnamespace App\\Models;\n\nuse Widdx\\Model;\n\nclass Probeno extends Model\n{\n    protected ?string \$table = 'probenos';\n}\n"
);
file_put_contents($proj2 . '/routes/web.php', "\nresource('probenos')->public();\n", FILE_APPEND);
// Allow opcache.revalidate_freq to notice the appended route without
// replacing the running server process (its PID owns the session test fixture).
stop_server($pid2, $port2);
$pid2 = start_server($proj2, $port2);
// web group is on by default since 1.3 — reuse a session-valid token.
$tokP2 = grab_csrf($port2, '/notes/create', $jar2);
$r = http($port2, 'POST', '/probenos', ['csrf_token' => $tokP2, 'name' => 'sneak', 'is_admin' => '1'], $jar2);
ok('1.1 no-fillable resource POST fails loudly with guidance', $r['status'] === 500 && stripos($r['body'], 'fillable') !== false, substr($r['body'], 0, 200));

// 1.8 safe uploads + storageRoutes: content-derived extension, nosniff, no traversal.
file_put_contents(
    $proj2 . '/routes/web.php',
    "\nstorageRoutes();\npost('/it-upload', function () {\n    \$p = request()->file('doc')->store('public/it18');\n    return \\Widdx\\Response::json(['p' => \$p]);\n});\n",
    FILE_APPEND
);
usleep(2_100_000);
$b18 = 'widdx18-boundary';
$gif18 = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$mp18 = "--{$b18}\r\nContent-Disposition: form-data; name=\"csrf_token\"\r\n\r\n{$tokP2}\r\n"
    . "--{$b18}\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"evil.php\"\r\nContent-Type: application/x-httpd-php\r\n\r\n{$gif18}\r\n--{$b18}--\r\n";
$r = http($port2, 'POST', '/it-upload', null, $jar2, $mp18, 'multipart/form-data; boundary=' . $b18);
$json18 = json_decode($r['body'], true);
$p18 = (string) ($json18['p'] ?? '');
ok(
    '1.8 upload stored with content-derived .gif (client claimed .php)',
    $r['status'] === 200 && $p18 !== '' && str_ends_with($p18, '.gif') && !str_contains($p18, '.php'),
    substr($r['body'], 0, 200)
);
$r = http($port2, 'GET', '/storage/' . substr($p18, strlen('public/')), null, $jar2);
ok(
    '1.8 storageRoutes serves with real Content-Type + nosniff',
    $r['status'] === 200 && stripos((string) ($r['headers']['content-type'] ?? ''), 'image/gif') === 0
        && ($r['headers']['x-content-type-options'] ?? '') === 'nosniff',
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 250)
);
$r = http($port2, 'GET', '/storage/../../composer.json', null, $jar2);
ok('1.8 storageRoutes refuses path traversal', $r['status'] === 404, 'status=' . $r['status']);
$it18Files = glob($proj2 . '/storage/app/public/it18/*') ?: [];
foreach ($it18Files as $ff) {
    if (preg_match('/\.php$/i', $ff)) {
        fwrite(STDERR, 'SECURITY: php-extension upload reached disk: ' . $ff . "\n");
    }
}
ok('1.8 no .php file ever reached the disk', array_filter($it18Files, fn($f) => preg_match('/\.php$/i', $f)) === []);

// 1.2 fail-closed authorization: no policy + no ->public() + no controller
// opt-in = DENY. Guests → 302 /login (web) / 401 JSON (api); members → 403.
file_put_contents(
    $proj2 . '/app/Models/Vault.php',
    "<?php\n\nnamespace App\\Models;\n\nuse Widdx\\Model;\n\nclass Vault extends Model\n{\n    protected ?string \$table = 'vaults';\n    protected array \$fillable = ['secret'];\n}\n"
);
file_put_contents(
    $proj2 . '/app/Models/Secret.php',
    "<?php\n\nnamespace App\\Models;\n\nuse Widdx\\Model;\n\nclass Secret extends Model\n{\n    protected ?string \$table = 'secrets';\n    protected array \$fillable = ['secret'];\n}\n"
);
file_put_contents(
    $proj2 . '/app/Controllers/PublicOpenController.php',
    "<?php\n\nnamespace App\\Controllers;\n\nuse App\\Models\\Vault;\nuse Widdx\\CrudController;\n\nclass PublicOpenController extends CrudController\n{\n    protected string \$model = Vault::class;\n    protected bool \$public = true;\n}\n"
);
file_put_contents($proj2 . '/routes/web.php', "\n" . <<<'PHP'
get('/mkvault', function () {
    // 2.7: Schema builder + prepared insert — portable across sqlite/mysql/pgsql
    \Widdx\Schema::create('vaults', function (\Widdx\Blueprint $t) {
        $t->id();
        $t->text('secret');
    });
    \Widdx\Schema::create('secrets', function (\Widdx\Blueprint $t) {
        $t->id();
        $t->text('secret');
    });
    $st = db()->pdo()->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $st->execute(['Gate Bot', 'gate@widdx.test', password_hash('gatepass1', PASSWORD_DEFAULT)]);
    return \Widdx\Response::json(['ok' => true]);
});
post('/glogin', fn() => \Widdx\Response::json(['ok' => \Widdx\Auth::attempt(request()->only(['email', 'password']))]));
post('/glogout', function () { \Widdx\Auth::logout(); return \Widdx\Response::json(['ok' => true]); });
get('/authstate', fn() => \Widdx\Response::json(['id' => \Widdx\Auth::id()]));
resource('vaults');
apiResource('secrets');
resource('publicopens', \App\Controllers\PublicOpenController::class);
PHP, FILE_APPEND);
// Route registration is cached by the long-running PHP server's OPcache.
// Restart instead of sleeping: validate_timestamps may be disabled in CI.
stop_server($pid2, $port2);
$pid2 = start_server($proj2, $port2);
$r = http($port2, 'GET', '/mkvault', null, $jar2);
ok('1.2 probes registered', $r['status'] === 200, 'status=' . $r['status']);

$r = http($port2, 'GET', '/vaults', null, $jar2);
ok(
    '1.2 guest + no policy + no ->public() = 302 to /login',
    $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), '/login'),
    'status=' . $r['status'] . ' loc=' . (string) ($r['headers']['location'] ?? '')
);
$r = http($port2, 'GET', '/api/secrets', null, $jar2);
ok('1.2 API guest = 401 JSON (not open)', $r['status'] === 401 && str_contains($r['body'], 'Unauthenticated'), 'status=' . $r['status']);

$r = http($port2, 'POST', '/glogin', ['csrf_token' => $tokP2, 'email' => 'gate@widdx.test', 'password' => 'gatepass1'], $jar2);
ok('1.2 login probe works', $r['status'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true, substr($r['body'], 0, 120));
$r = http($port2, 'GET', '/vaults', null, $jar2);
ok('1.2 member + no policy = 403 (fail closed)', $r['status'] === 403, 'status=' . $r['status']);
http($port2, 'POST', '/glogout', ['csrf_token' => grab_csrf($port2, '/notes/create', $jar2)], $jar2);

$policyDir2 = $proj2 . '/app/Policies';
if (!is_dir($policyDir2)) {
    mkdir($policyDir2, 0775, true);
}
// Strict-typed policy: called with a null guest it would 500 — must deny cleanly.
file_put_contents($policyDir2 . '/VaultPolicy.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Policies;
class VaultPolicy
{
    public function viewAny(\App\Models\User $user): bool
    {
        return true;
    }
}
PHP);
$r = http($port2, 'GET', '/vaults', null, $jar2);
ok('1.2 guest vs strict-typed policy = 302, never 500', $r['status'] === 302, 'status=' . $r['status']);

file_put_contents($policyDir2 . '/VaultPolicy.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Policies;
class VaultPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }
}
PHP);
$r = http($port2, 'GET', '/vaults', null, $jar2);
ok('1.2 policy allow overrides default-deny (even guest)', $r['status'] === 200 && str_contains($r['body'], 'Vaults'), 'status=' . $r['status']);
@unlink($policyDir2 . '/VaultPolicy.php');
$r = http($port2, 'GET', '/vaults', null, $jar2);
ok('1.2 policy removed = fail closed again', $r['status'] === 302, 'status=' . $r['status']);

$r = http($port2, 'GET', '/publicopens', null, $jar2);
ok('1.2 controller \$public = true opens guest access', $r['status'] === 200, 'status=' . $r['status']);

file_put_contents($proj2 . '/routes/web.php', "\nresource('secrets')->public();\n", FILE_APPEND);
stop_server($pid2, $port2);
$pid2 = start_server($proj2, $port2);
$r = http($port2, 'GET', '/secrets', null, $jar2);
ok('1.2 resource()->public() opens the whole group', $r['status'] === 200, 'status=' . $r['status']);

// 1.3 default web group: security headers + CSRF everywhere, /api exempt,
// JSON clients get JSON errors, authRoutes throttles credential posts.
$r = http($port2, 'GET', '/notes', null, $jar2);
ok(
    '1.3 SecurityHeaders auto-applied on web',
    ($r['headers']['x-frame-options'] ?? '') === 'SAMEORIGIN'
        && ($r['headers']['x-content-type-options'] ?? '') === 'nosniff'
        && ($r['headers']['referrer-policy'] ?? '') === 'strict-origin-when-cross-origin',
    json_encode($r['headers'])
);
$r = http($port2, 'POST', '/notes', ['title' => 'no token'], $jar2);
ok('1.3 POST without token = 419 with zero registration', $r['status'] === 419, 'status=' . $r['status']);
$tok13 = grab_csrf($port2, '/notes/create', $jar2);
ok('1.3 zero-code forms carry the token automatically', strlen($tok13) >= 32, 'token=' . $tok13);
$r = http($port2, 'POST', '/notes', ['csrf_token' => $tok13, 'title' => 'CSRF OK note'], $jar2);
ok('1.3 POST with token passes (302)', $r['status'] === 302, 'status=' . $r['status']);
$r = http($port2, 'POST', '/notes', null, $jar2, json_encode(['title' => 'x']), 'application/json');
ok('1.3 JSON client gets 419 as JSON not HTML', $r['status'] === 419 && str_contains($r['body'], 'CSRF token mismatch'), substr($r['body'], 0, 120));
$r = http($port2, 'POST', '/notes', ['csrf_token[]' => 'x', 'title' => 'arr'], $jar2);
ok('1.3 array csrf_token is 419, never a warning/500', $r['status'] === 419, 'status=' . $r['status']);
$jarApiGuest = [];
$r = http($port2, 'POST', '/api/secrets', ['secret' => 's'], $jarApiGuest);
ok('1.3 stateless API guest reaches the 401 auth gate (not 419)', $r['status'] === 401, 'status=' . $r['status']);

file_put_contents($proj2 . '/routes/web.php', "\nauthRoutes();\n", FILE_APPEND);
stop_server($pid2, $port2);
$pid2 = start_server($proj2, $port2);
$r = http($port2, 'GET', '/login', null, $jar2);
$tokL = preg_match('#name="csrf_token" value="([^"]+)"#', $r['body'], $mL) === 1 ? $mL[1] : '';
ok('1.3 login page renders with token', $r['status'] === 200 && strlen($tokL) >= 32, 'status=' . $r['status']);
$codes13 = [];
for ($i13 = 0; $i13 < 6; $i13++) {
    $rl = http($port2, 'POST', '/login', ['csrf_token' => $tokL, 'email' => 'nobody@widdx.test', 'password' => 'wrongpass'], $jar2);
    $codes13[] = $rl['status'];
}
ok('1.3 authRoutes throttles /login (5/min → 6th is 429)', $codes13 === [302, 302, 302, 302, 302, 429], json_encode($codes13));

// 1.3b: JSON callers always get machine-readable errors — no HTML, no redirect.
$r = http($port2, 'GET', '/vaults', null, $jar2, null, null, ['Accept: application/json']);
ok('1.3b guest + Accept json at auth gate = 401 JSON (not 302)', $r['status'] === 401 && str_contains($r['body'], 'error'), 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));
$r = http($port2, 'GET', '/api/does-not-exist', null, $jar2);
ok('1.3b /api 404 renders as JSON', $r['status'] === 404 && str_contains($r['body'], 'error'), 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));
// After 'make:auth' ran in this project (line 874) the users resource is no longer
// ->public() — that is the DEPLOY-01 C contract — so /users/create now 302s for this
// logged-out jar. /login is the form that is public by construction in this app.
$tokV = grab_csrf($port, '/login', $jar);
$r = http($port, 'POST', '/it-validate', null, $jar, json_encode(['name' => 'a']), 'application/json', ['X-CSRF-TOKEN: ' . $tokV]);
$d13b = json_decode($r['body'], true);
ok('1.3b validation over JSON = 422 {message, errors}', $r['status'] === 422 && isset($d13b['errors']['name']), 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 150));

// 1.5: debug is OFF unless .env says otherwise — no trace leaks.
// (/it-boom is registered up-front, before the server started — see note there.)
$r = http($port2, 'GET', '/it-boom', null, $jar2);
ok('1.5 installed with APP_DEBUG=1 → technical page shown', $r['status'] === 500 && str_contains($r['body'], 'SECRET-TRACE-15'), 'status=' . $r['status']);
$envRaw = (string) file_get_contents($proj2 . '/.env');
$envKept = array_values(array_filter(explode("\n", $envRaw), fn($l) => !str_starts_with(trim($l), 'APP_DEBUG')));
file_put_contents($proj2 . '/.env', implode("\n", $envKept) . "\n");
$r = http($port2, 'GET', '/it-boom', null, $jar2);
ok('1.5 APP_DEBUG unset → friendly 500, no trace leak', $r['status'] === 500 && !str_contains($r['body'], 'SECRET-TRACE-15') && !str_contains($r['body'], 'Technical Details'), substr($r['body'], 0, 120));
$r = http($port2, 'GET', '/it-boom', null, $jar2, null, null, ['Accept: application/json']);
ok('1.5 JSON 500 hides the message when debug off', $r['status'] === 500 && str_contains($r['body'], 'Internal Server Error') && !str_contains($r['body'], 'SECRET-TRACE-15'), substr($r['body'], 0, 150));

// 2.3: JSON body parse + bearer token over real HTTP. This browser-like
// client has a session cookie, so it also sends the CSRF token.
$r = http($port2, 'GET', '/api/it-csrf-token', null, $jar2);
$csrfApi23 = (string) (json_decode($r['body'], true)['token'] ?? '');
$r = http($port2, 'POST', '/api/it-json', null, $jar2, '{"name":"Ada","age":30}', 'application/json',
    ['Authorization: Bearer tok-2-3', 'X-CSRF-TOKEN: ' . $csrfApi23]);
$d23 = json_decode($r['body'], true);
ok('2.3 JSON body parses into input()/json() over HTTP', $r['status'] === 200 && ($d23['name'] ?? null) === 'Ada' && ($d23['age'] ?? null) === 30, 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 150));
ok('2.3 bearerToken() reads Authorization header', ($d23['bearer'] ?? null) === 'tok-2-3', substr($r['body'], 0, 150));

// H-01: CSRF is required for an authenticated session-cookie API client.
$r = http($port2, 'GET', '/api/it-csrf', null, $jar2);
$readCsrf = json_decode($r['body'], true);
ok('H-01 GET API request needs no CSRF and does not mutate', $r['status'] === 200 && ($readCsrf['mutated'] ?? true) === false,
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));
$r = http($port2, 'GET', '/api/it-session-login', null, $jar2);
ok('H-01 session login fixture establishes an authenticated browser session', $r['status'] === 200, 'status=' . $r['status']);
$r = http($port2, 'POST', '/api/it-csrf', ['value' => 'no-token'], $jar2);
$jH01 = json_decode($r['body'], true);
ok('H-01 API session write without CSRF token returns JSON 419',
    $r['status'] === 419 && ($jH01['error'] ?? '') === 'CSRF token mismatch.',
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 160));
$r = http($port2, 'GET', '/api/it-csrf-token', null, $jar2);
$csrfH01 = (string) (json_decode($r['body'], true)['token'] ?? '');
$r = http($port2, 'POST', '/api/it-csrf', null, $jar2, '{"value":"valid"}', 'application/json',
    ['X-CSRF-TOKEN: ' . $csrfH01, 'Accept: application/json']);
ok('H-01 API session write with valid CSRF header succeeds',
    $r['status'] === 200 && str_contains($r['body'], '"mutated":true'),
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 160));
foreach (['PUT', 'PATCH', 'DELETE'] as $methodH01) {
    $r = http($port2, $methodH01, '/api/it-csrf', ['value' => 'no-token'], $jar2);
    ok('H-01 ' . $methodH01 . ' API session write without CSRF returns 419', $r['status'] === 419,
        'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));
}
$r = http($port2, 'POST', '/api/it-csrf', ['_method' => 'DELETE', 'value' => 'no-token'], $jar2);
ok('H-01 POST method-spoofed DELETE requires CSRF', $r['status'] === 419, 'status=' . $r['status']);
$r = http($port2, 'POST', '/api/it-csrf', ['_token' => $csrfH01], $jar2);
ok('H-01 _token form field is accepted', $r['status'] === 200 && str_contains($r['body'], '"mutated":true'),
    'status=' . $r['status']);
$r = http($port2, 'POST', '/api/it-csrf', null, $jar2, '{"value":"invalid"}', 'application/json',
    ['X-CSRF-TOKEN: invalid-token', 'Accept: application/json']);
ok('H-01 invalid CSRF token is rejected', $r['status'] === 419, 'status=' . $r['status']);
$jarOtherH01 = [];
$r = http($port2, 'GET', '/api/it-session-login', null, $jarOtherH01);
$r = http($port2, 'GET', '/api/it-csrf-token', null, $jarOtherH01);
$otherTokenH01 = (string) (json_decode($r['body'], true)['token'] ?? '');
$r = http($port2, 'POST', '/api/it-csrf', null, $jar2, '{"value":"wrong-session"}', 'application/json',
    ['X-CSRF-TOKEN: ' . $otherTokenH01, 'Accept: application/json']);
ok('H-01 token from another authenticated session is rejected', $r['status'] === 419, 'status=' . $r['status']);

// A guest can still have a PHP session cookie after requesting a CSRF token.
// Cookie-bearing API writes must require CSRF even before login; Auth::guest()
// must not create a bypass for public API endpoints.
$jarGuestSessionH01 = [];
$r = http($port2, 'GET', '/api/it-csrf-token', null, $jarGuestSessionH01);
$guestSessionTokenH01 = (string) (json_decode($r['body'], true)['token'] ?? '');
ok('H-01 guest can obtain a session-bound CSRF token', $r['status'] === 200 && strlen($guestSessionTokenH01) >= 32,
    'status=' . $r['status'] . ' tokenLength=' . strlen($guestSessionTokenH01));
$r = http($port2, 'POST', '/api/it-csrf', ['value' => 'guest-no-token'], $jarGuestSessionH01);
ok('H-01 guest session-cookie API write without CSRF is rejected', $r['status'] === 419,
    'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));

$r = http($port2, 'POST', '/api/it-json', null, $jar2, '{not valid json', 'application/json',
    ['X-CSRF-TOKEN: ' . $csrfApi23]);
ok('2.3 malformed JSON body is a clean 400 (not 500, not silent empty)', $r['status'] === 400, 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 150));

// 2.4 HEAD / OPTIONS over real HTTP against the /notes resource.
$r = http($port2, 'HEAD', '/notes', null, $jar2);
ok('2.4 HEAD returns 200 with an empty body', $r['status'] === 200 && $r['body'] === '', 'status=' . $r['status'] . ' bodylen=' . strlen($r['body']));
$r = http($port2, 'OPTIONS', '/notes', null, $jar2);
$allowH24 = (string) ($r['headers']['allow'] ?? '');
ok('2.4 OPTIONS returns 204 + Allow listing GET', $r['status'] === 204 && stripos($allowH24, 'GET') !== false, 'status=' . $r['status'] . ' allow=' . $allowH24);

// 2.6: migrations are transactional — a failing migration must roll back its
// own DDL and must NOT be recorded, while earlier migrations stay applied.
// MySQL commits DDL implicitly (a real, documented engine limitation), so the
// rollback assertion only applies to the transactional-DDL drivers.
$saved2 = getenv('DB_CONNECTION');
$drv2 = $saved2 !== false && $saved2 !== '' ? $saved2 : 'sqlite';
file_put_contents(
    $proj2 . '/database/migrations/9999_12_31_000000_it_fail.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn new class {\n    public function up(\\PDO \$pdo): void\n    {\n        \\Widdx\\Schema::create('it_rollback_probe', function (\\Widdx\\Blueprint \$t) { \$t->id(); }, \$pdo);\n        throw new \\RuntimeException('intentional 2.6 failure');\n    }\n    public function down(\\PDO \$pdo): void {}\n};\n"
);
[$code, $out] = shell('cd ' . escapeshellarg($proj2) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
$probePdo = project_pdo($proj2 . '/host.sqlite'); // 2.7: honours DB_CONNECTION
$probeTable = db_has_table($probePdo, 'it_rollback_probe') ? 1 : 0;
$notRecorded = (int) $probePdo->query("SELECT COUNT(*) FROM migrations WHERE migration='9999_12_31_000000_it_fail'")->fetchColumn();
$usersIntact = db_has_table($probePdo, 'users') ? 1 : 0;
$txDDL = in_array($drv2, ['sqlite', 'pgsql'], true);
ok('2.6 failing migration is rolled back (its table is absent)', $txDDL ? $probeTable === 0 : true, 'driver=' . $drv2 . ' probe=' . $probeTable . ' out=' . substr($out, 0, 120));
ok('2.6 failing migration is NOT recorded', $notRecorded === 0, 'recorded=' . $notRecorded);
ok('2.6 earlier migrations stay applied (users intact)', $usersIntact === 1, 'users=' . $usersIntact);
@unlink($proj2 . '/database/migrations/9999_12_31_000000_it_fail.php');
if ($drv2 !== 'sqlite') {
    // leave no trace for later stages that reuse the same database
    $probePdo->exec('DROP TABLE IF EXISTS it_rollback_probe');
}

stop_server($pid2, $port2);

// 1.10: a LOCKED project whose .env went missing must still refuse 404.
[$code, $out] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new lockonly');
ok('widdx new lockonly exits 0', $code === 0, $out);
ok('2.4 scaffold ships core/Route.php + CorsMiddleware + config/cors.php',
    is_file($TMP . '/lockonly/core/Route.php')
    && is_file($TMP . '/lockonly/core/CorsMiddleware.php')
    && is_file($TMP . '/lockonly/config/cors.php'));
@mkdir($TMP . '/lockonly/storage/app', 0775, true);
file_put_contents($TMP . '/lockonly/storage/app/installed.lock', 'pre-seeded');
$port5 = pick_port();
$pid5 = start_server($TMP . '/lockonly', $port5);
$r = http($port5, 'GET', '/install.php');
ok('1.10 lock without .env still refuses (404, not re-installable)', $r['status'] === 404, 'status=' . $r['status']);
stop_server($pid5, $port5);

// ------------------------------------------------------------------ summary

echo "\n===== Integration: {$pass} passed, {$fail} failed =====\n";
exit($fail > 0 ? 1 : 0);

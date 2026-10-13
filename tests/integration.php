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
$TMP = sys_get_temp_dir() . '/widdx-it-' . getmypid() . '-' . random_int(1000, 9999);
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
    if (str_contains($TMP, '/widdx-it-') && is_dir($TMP)) {
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

function start_server(string $proj, int $port): int
{
    @mkdir($proj . '/storage/sessions', 0777, true);
    $cmd = 'nohup ' . escapeshellarg(PHP_BINARY)
        . ' -d session.save_path=' . escapeshellarg($proj . '/storage/sessions')
        . " -S 127.0.0.1:{$port} -t " . escapeshellarg($proj . '/public')
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

function stop_server(int $pid): void
{
    @exec('kill ' . (int) $pid . ' 2>/dev/null');
    usleep(200000);
}

// ------------------------------------------------------- 1. scaffold project

sec('Scaffold: php widdx new app');

[$code, $out] = shell('cd ' . escapeshellarg($TMP) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/widdx') . ' new app');
ok('widdx new exits 0', $code === 0, $out);
$proj = $TMP . '/app';

// Bring the demo app files from the framework repo into the scaffold.
copy($ROOT . '/app/Controllers/UserController.php', $proj . '/app/Controllers/UserController.php');
copy($ROOT . '/app/Models/User.php', $proj . '/app/Models/User.php');
shell('cp -r ' . escapeshellarg($ROOT . '/views/users') . ' ' . escapeshellarg($proj . '/views/'));

// Routes: full users resource + probe routes used by the scenarios below.
$probes = <<<'ROUTES'

// ===== integration probes (appended by tests/integration.php) =====
use Widdx\Auth;
use Widdx\Response;

resource('users', \App\Controllers\UserController::class);

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
ROUTES;
file_put_contents($proj . '/routes/web.php', $probes . "\n", FILE_APPEND);

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('migrate exits 0', $code === 0, $out);
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx db:seed');
ok('db:seed exits 0', $code === 0, $out);

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

$r = http($port, 'POST', '/users', ['name' => 'It Test', 'email' => 'it@test.local']);
ok('POST /users redirects', $r['status'] === 302, "status={$r['status']}");
$r = http($port, 'GET', '/users', null, $jar);
ok('created user visible in list', str_contains($r['body'], 'It Test'));

$id = null;
if (preg_match('#/users/(\d+)"[^>]*>\s*It Test#', $r['body'], $m)) {
    $id = (int) $m[1];
}
ok('extract new user id', $id !== null, 'id not found in list markup');

if ($id !== null) {
    $r = http($port, 'GET', '/users/' . $id);
    ok('GET show page 200', $r['status'] === 200 && str_contains($r['body'], 'It Test'), "status={$r['status']}");

    $r = http($port, 'POST', '/users/' . $id, ['_method' => 'PUT', 'name' => 'Renamed', 'email' => 'it@test.local']);
    ok('_method=PUT spoof redirects', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/users/' . $id);
    ok('update applied', str_contains($r['body'], 'Renamed'));

    $r = http($port, 'POST', '/users/' . $id, ['_method' => 'DELETE']);
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
$r = http($port, 'POST', '/users', ['name' => '', 'email' => 'not-an-email'], $jar);
ok('invalid POST redirects back', $r['status'] === 302, "status={$r['status']}");
$r = http($port, 'GET', '/users/create', null, $jar);
ok('validation error flashed', str_contains($r['body'], 'valid email'), substr($r['body'], 0, 120));
ok('old input repopulated', str_contains($r['body'], 'value="not-an-email"'));

// --------------------------------------------- 4. rules, columns, events, auth

sec('Validation rules (JSON probes)');

$r = http($port, 'POST', '/it-validate', ['name' => 'Sam', 'email' => 'fresh@test.local', 'password' => 'secret1', 'password_confirmation' => 'secret1']);
$data = json_decode($r['body'], true);
ok('valid payload passes', $r['status'] === 200 && ($data['ok'] ?? false) === true, $r['body']);

$r = http($port, 'POST', '/it-validate', ['name' => 'Sam', 'email' => 'admin@example.com', 'password' => 'secret1', 'password_confirmation' => 'secret1']);
$data = json_decode($r['body'], true);
ok('unique: rejects seeded email', $r['status'] === 422 && str_contains($data['errors']['email'][0] ?? '', 'already been taken'), $r['body']);

$r = http($port, 'POST', '/it-validate', ['name' => 'Sam', 'email' => 'fresh@test.local', 'password' => 'aaa111', 'password_confirmation' => 'bbb222']);
$data = json_decode($r['body'], true);
ok('confirmed: mismatch caught', str_contains($data['errors']['password'][0] ?? '', 'confirmation does not match'), $r['body']);

$r = http($port, 'POST', '/it-validate', ['name' => 'ab', 'email' => 'fresh@test.local', 'password' => 'secret1', 'password_confirmation' => 'secret1']);
$data = json_decode($r['body'], true);
ok('min:3 caught', str_contains($data['errors']['name'][0] ?? '', 'at least 3'), $r['body']);

$r = http($port, 'POST', '/it-validate-custom', []);
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

$r = http($port, 'POST', '/it-login', ['email' => 'admin@example.com', 'password' => 'wrong'], $jar);
$data = json_decode($r['body'], true);
ok('bad credentials rejected', ($data['ok'] ?? true) === false, $r['body']);

$r = http($port, 'POST', '/it-login', ['email' => 'admin@example.com', 'password' => 'password'], $jar);
$data = json_decode($r['body'], true);
ok('attempt() logs in', ($data['ok'] ?? false) === true && (int) ($data['id'] ?? 0) > 0, $r['body']);

$r = http($port, 'GET', '/it-auth-check', null, $jar);
$data = json_decode($r['body'], true);
ok('session persists login', ($data['check'] ?? false) === true, $r['body']);

$r = http($port, 'POST', '/it-logout', [], $jar);
$r = http($port, 'GET', '/it-auth-check', null, $jar);
$data = json_decode($r['body'], true);
ok('logout clears session', ($data['check'] ?? true) === false, $r['body']);

// ------------------------------------------------------------ 5. CSRF stage

sec('CSRF middleware (enabled mid-flight, no restart)');

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
    ok('valid token accepted', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/users', null, $jar);
    ok('csrf-protected create worked', str_contains($r['body'], 'Csrf User'));

    $r = http($port, 'POST', '/users', ['csrf_token' => 'deadbeefdeadbeef', 'name' => 'X', 'email' => 'x@test.local'], $jar);
    ok('bogus token is 419', $r['status'] === 419, "status={$r['status']}");
}

// ---------------------------------------------------- 6. security headers

sec('SecurityHeaders middleware');

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
stop_server($pid);
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

[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx make:crud Post title:string body:text? views:integer?');
ok('make:crud exits 0', $code === 0, $out);
ok('Post model generated with NO controller', is_file($proj . '/app/Models/Post.php') && !is_file($proj . '/app/Controllers/PostController.php'));
ok('route appended automatically', str_contains((string) file_get_contents($proj . '/routes/web.php'), "resource('posts');"));

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

if ($token !== null) {
    $r = http($port, 'POST', '/posts', ['csrf_token' => $token, 'title' => 'Hello Zero', 'body' => 'nothing'], $jar);
    ok('zero-code store redirects', $r['status'] === 302, "status={$r['status']}");
    $r = http($port, 'GET', '/posts', null, $jar);
    ok('built-in list shows the new record', str_contains($r['body'], 'Hello Zero'));

    $postId = null;
    if (preg_match('#/posts/(\d+)"[^>]*>View|/posts/(\d+)#', $r['body'], $m)) {
        $postId = (int) ($m[1] ?: $m[2]);
    }
    if ($postId) {
        $r = http($port, 'GET', '/posts/' . $postId, null, $jar);
        ok('built-in show page works', $r['status'] === 200 && str_contains($r['body'], 'Hello Zero'), "status={$r['status']}");
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
    $pdb = new PDO('sqlite:' . $proj . '/database.sqlite');
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
    $pdb2 = new PDO('sqlite:' . $proj . '/database.sqlite');
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

// live-append probes (routes re-required per request — no server restart)
file_put_contents($proj . '/routes/web.php', "\n" . <<<'PHP'
\Widdx\Request::setTrustedProxies(['127.0.0.1']);
get('/it-ip', fn() => Response::json(['ip' => request()->ip()]));
PHP, FILE_APPEND);
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
ok('latest books migration carries REFERENCES authors(id)', count($mig) > 0 && str_contains((string) file_get_contents($mig[0]), 'REFERENCES authors(id)'));
[$code, $out] = shell('cd ' . escapeshellarg($proj) . ' && ' . escapeshellarg(PHP_BINARY) . ' widdx migrate');
ok('fk migrations apply', $code === 0, $out);

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
if ($aid !== null) {
    $tok = $csrf('/books/create');
    $r = http($port, 'POST', '/books', ['csrf_token' => (string) $tok, 'title' => 'The Enchanted Night', 'author_id' => (string) $aid], $jar);
    ok('valid fk value stored', $r['status'] === 302, "status={$r['status']}");
}
$r = http($port, 'GET', '/books', null, $jar);
ok('exists-rule rejected dangling fk (Ghost absent)', !str_contains($r['body'], 'Ghost'));
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

// ------------------------------------------------------------------ summary

echo "\n===== Integration: {$pass} passed, {$fail} failed =====\n";
exit($fail > 0 ? 1 : 0);

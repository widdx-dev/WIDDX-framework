<?php

declare(strict_types=1);

/**
 * One-shot web installer — for developers deploying to shared hosting
 * WITHOUT terminal access. Open /install.php in the browser, fill the
 * form, done: .env is created, migrations run, and this file locks
 * itself forever (404 afterwards). Delete it if you like.
 *
 * It refuses to touch an existing .env — see Widdx\Installer.
 */

define('WIDDX_BASE_PATH', dirname(__DIR__));

$autoload = WIDDX_BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

// Same fallback autoloader as index.php (composer-less hosts).
if (!class_exists('Widdx\App')) {
    spl_autoload_register(function ($class) {
        $prefixes = [
            'Widdx\\' => WIDDX_BASE_PATH . '/core/',
            'App\\' => WIDDX_BASE_PATH . '/app/',
            'Psr\\' => WIDDX_BASE_PATH . '/polyfill/',
        ];
        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        }
    });
    require WIDDX_BASE_PATH . '/core/helpers.php';
}

use Widdx\Installer;
use Widdx\Session;

$base = WIDDX_BASE_PATH;

// Locked forever after a successful install — one-shot endpoint.
// (Checked FIRST: a stale lock must 404 even when .env went missing after.)
if (Installer::installed($base)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This project is already installed. Delete storage/app/installed.lock only if you know what you are doing.\n");
}

// Installer CSRF: its own session token, independent of the app.
Session::start();
$iform = Session::get('_installer_token');
if (!is_string($iform) || $iform === '') {
    $iform = bin2hex(random_bytes(16));
    Session::put('_installer_token', $iform);
}
Installer::installKey($base); // create on first GET; value is NEVER rendered

$errors = [];
$done = null;
$old = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'install');
    $old = $_POST;
    try {
        // 1) CSRF: constant-time compare against the session token.
        $sent = $_POST['installer_token'] ?? null;
        if (!is_string($sent) || !hash_equals($iform, $sent)) {
            throw new RuntimeException('انتهت صلاحية جلسة التنصيب (CSRF) — حدِّث الصفحة وأعد المحاولة.');
        }
        // 2) Proof of possession: the key is only readable over FTP/SSH.
        if (!Installer::keyMatches($base, $_POST['install_key'] ?? null)) {
            throw new RuntimeException('مفتاح التنصيب غير صحيح. افتح ملف storage/app/install.key عبر FTP/SSH وانسخ محتواه هنا.');
        }

        if ($action === 'lock_only') {
            if (!Installer::configured($base)) {
                throw new RuntimeException('لا يوجد .env بعد — أكمل التنصيب أولًا.');
            }
            Installer::lock($base);
            $done = 'تم القفل. install.php أصبح الآن مغلقًا (404).';
            if (is_file(__FILE__)) {
                if (!@unlink(__FILE__)) {
                    $done .= ' <strong>احذف install.php يدويًا الآن.</strong>';
                }
            }
        } elseif (Installer::configured($base)) {
            // Existing config (CLI flow or manual .env) — never overwrite it.
            throw new RuntimeException('.env موجود مسبقًا؛ لن يتم overriding له. إن كان كل شيء جاهزًا اضغط "قفل فقط" بالأسفل، أو احذف .env لإعادة التنصيب.');
        } else {
            $v = array_map(static fn ($x) => is_string($x) ? trim($x) : '', $_POST);
            $appUrl = (string) ($v['APP_URL'] ?? '');
            $urlParts = $appUrl !== '' ? parse_url($appUrl) : false;
            if (!is_array($urlParts)
                || filter_var($appUrl, FILTER_VALIDATE_URL) === false
                || !isset($urlParts['scheme'], $urlParts['host'])
                || !in_array(strtolower((string) $urlParts['scheme']), ['http', 'https'], true)
                || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
                throw new RuntimeException('رابط الموقع الأساسي مطلوب. أدخل رابطًا كاملًا يبدأ بـ https:// أو http://، دون بيانات دخول أو query أو fragment.');
            }
            if (($v['DB_CONNECTION'] ?? 'sqlite') === 'sqlite') {
                // The sqlite path lives in its own field so the (hidden)
                // server fields cannot override it with an empty value.
                $sp = (string) ($v['DB_SQLITE_PATH'] ?? '');
                $v['DB_DATABASE'] = $sp !== '' ? $sp : './database.sqlite';
            } else {
                foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $req) {
                    if (($v[$req] ?? '') === '') {
                        throw new RuntimeException("الحقل {$req} مطلوب.");
                    }
                }
            }
            $err = Installer::testConnection($base, $v);
            if ($err !== null) {
                throw new RuntimeException('تعذّر الاتصال بقاعدة البيانات: ' . $err);
            }
            Installer::writeEnv($base, $v);
            $executed = Installer::finish($base);
            $n = count($executed);
            $done = "تم التنصيب! نفِّذت {$n} migration، وأُنشئ .env، وقُفل المثبّت. اذهب إلى <a href=\"/\">الرئيسية</a>.";
            // Best-effort self-removal (ignore hosts that block unlink here).
            if (is_file(__FILE__)) {
                if (!@unlink(__FILE__)) {
                    $done .= ' <strong>احذف install.php يدويًا الآن — لم يتمكن المثبّت من حذفه بنفسه.</strong>';
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$checks = Installer::requirements($base);
$ready = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $ready = false;
        break;
    }
}
$configured = Installer::configured($base);
$val = static fn (string $k, string $d = '') => e((string) ($old[$k] ?? $d));
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تنصيب Widdx</title>
<style>
body{font-family:system-ui,Tahoma,sans-serif;max-width:720px;margin:32px auto;padding:0 16px;color:#1e293b;background:#f8fafc}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:20px}
h1{margin-top:0}
label{display:block;margin:12px 0 4px;font-weight:600}
input,select{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;box-sizing:border-box}
.btn{display:inline-block;background:#4f46e5;color:#fff;padding:10px 24px;border-radius:8px;border:0;cursor:pointer;font-size:15px;margin-top:16px}
.err{background:#fee2e2;border:1px solid #fca5a5;padding:10px 14px;border-radius:8px;margin:8px 0;color:#991b1b}
.ok{background:#dcfce7;border:1px solid #86efac;padding:10px 14px;border-radius:8px;margin:8px 0;color:#166534}
li{margin:4px 0}
.bad{color:#b91c1c}.good{color:#15803d}
.hint{font-size:13px;color:#64748b}
</style>
</head>
<body>
<div class="card">
<h1>تنصيب Widdx 🚀</h1>
<?php if ($done !== null): ?>
    <div class="ok"><?= $done ?></div>
<?php else: ?>

<?php if (!$ready): ?>
    <div class="err">البيئة غير جاهزة — أصلح البنود الحمراء أولًا ثم حدِّث الصفحة:</div>
<?php endif; ?>
<ul>
<?php foreach ($checks as $c): ?>
    <li class="<?= $c['ok'] ? 'good' : 'bad' ?>"><?= $c['ok'] ? '✔' : '✖' ?> <?= e($c['label']) ?>
        <?php if (!$c['ok']): ?><div class="hint"><?= e($c['hint']) ?></div><?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>

<?php if ($errors): foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; endif; ?>

<?php if ($configured):
    // .env exists but the installer is NOT locked yet — offer locking.
    // Key + token are required here too, so this button cannot be fired
    // by a drive-by POST.
?>
    <p>.env موجود بالفعل (ربما ضبطته عبر الطرفية). يمكنك قفل المثبّت فقط:</p>
    <form method="post">
    <input type="hidden" name="action" value="lock_only">
    <input type="hidden" name="installer_token" value="<?= e($iform) ?>">
    <label>مفتاح التنصيب <span class="hint">افتح ملف <code>storage/app/install.key</code> عبر FTP/SSH وانسخ محتواه هنا</span>
    <input type="text" name="install_key" value="" autocomplete="off"></label>
    <button class="btn" type="submit">قفل المثبّت فقط</button></form>
<?php elseif ($ready): ?>
<form method="post">
<input type="hidden" name="action" value="install">
<input type="hidden" name="installer_token" value="<?= e($iform) ?>">
<label>مفتاح التنصيب <span class="hint">إثبات الملكية: افتح ملف <code>storage/app/install.key</code> عبر FTP/SSH وانسخ محتواه هنا — لا يظهر أبدًا في هذه الصفحة</span>
<input type="text" name="install_key" value="" autocomplete="off"></label>
<label>نوع قاعدة البيانات
<select name="DB_CONNECTION">
    <option value="sqlite" <?= $val('DB_CONNECTION') === 'sqlite' || $old === [] ? 'selected' : '' ?>>SQLite (الأسهل — بلا إعداد)</option>
    <option value="mysql" <?= $val('DB_CONNECTION') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
    <option value="pgsql" <?= $val('DB_CONNECTION') === 'pgsql' ? 'selected' : '' ?>>PostgreSQL</option>
</select></label>

<div data-db="sqlite">
<label>مسار ملف SQLite <span class="hint">نسبي من جذر المشروع، مثل ./database.sqlite</span>
<input name="DB_SQLITE_PATH" value="<?= $val('DB_SQLITE_PATH', './database.sqlite') ?>"></label>
</div>

<div data-db="server">
<label>DB_HOST <input name="DB_HOST" value="<?= $val('DB_HOST', 'localhost') ?>"></label>
<label>DB_PORT <span class="hint">(اتركه فارغًا للافتراضي)</span> <input name="DB_PORT" value="<?= $val('DB_PORT') ?>"></label>
<label>اسم القاعدة (أنشئها من cPanel أولًا) <input name="DB_DATABASE" value="<?= $val('DB_DATABASE') ?>"></label>
<label>المستخدم <input name="DB_USERNAME" value="<?= $val('DB_USERNAME') ?>"></label>
<label>كلمة المرور <input type="password" name="DB_PASSWORD" value=""></label>
</div>

<label>الرابط الأساسي للموقع <span class="hint">مطلوب لروابط التحقق وإعادة تعيين كلمة المرور، مثل https://example.com</span>
<input name="APP_URL" type="url" required placeholder="https://example.com" value="<?= $val('APP_URL') ?>"></label>
<label>لغة الواجهة
<select name="APP_LOCALE">
    <option value="ar" <?= ($val('APP_LOCALE', 'ar') === 'ar') ? 'selected' : '' ?>>العربية (RTL)</option>
    <option value="en" <?= $val('APP_LOCALE') === 'en' ? 'selected' : '' ?>>English (LTR)</option>
</select></label>
<label style="font-weight:400"><input type="checkbox" name="APP_DEBUG" value="1" style="width:auto" <?= !empty($old['APP_DEBUG']) ? 'checked' : '' ?>> وضع التصحيح (APP_DEBUG)</label>
<p style="color:#b91c1c;font-size:.85rem;margin:4px 0 12px">⚠ تحذير: وضع التصحيح يعرض للزوار تفاصيل الأخطاء ومسارات الملفات الداخلية — مناسب للتجربة المحلية فقط. اتركه معطّلًا في أي موقع فعلي (الافتراضي في الإطار: OFF).</p>

<button class="btn" type="submit">تنصيب الآن</button>
<p class="hint">سيُنشئ هذا النموذج ملف .env، ويشغّل كل migrations المعلّقة، ثم يُقفل نفسه نهائيًا.</p>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
<script>
(function () {
  var sel = document.querySelector('[name=DB_CONNECTION]');
  if (!sel) return;
  var toggle = function () {
    var lite = sel.value === 'sqlite';
    document.querySelector('[data-db=sqlite]').style.display = lite ? '' : 'none';
    document.querySelector('[data-db=server]').style.display = lite ? 'none' : '';
  };
  sel.addEventListener('change', toggle);
  toggle();
})();
</script>
</body>
</html>

<?php /** @var string $title */ ?>
<!DOCTYPE html>
<html <?= lang_attrs() ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Widdx') ?></title>
<style>
body{font-family:system-ui,Tahoma,sans-serif;max-width:860px;margin:32px auto;padding:0 16px;color:#1e293b;background:#f8fafc}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:20px}
a{color:#4f46e5}
.btn{display:inline-block;background:#4f46e5;color:#fff;padding:8px 18px;border-radius:8px;text-decoration:none;border:0;cursor:pointer;font-size:15px}
.btn.secondary{background:#e2e8f0;color:#1e293b}
.btn.danger{background:#dc2626}
table{width:100%;border-collapse:collapse}
th,td{text-align:start;padding:10px 8px;border-bottom:1px solid #e2e8f0}
label{display:block;margin:12px 0 4px;font-weight:600}
input,select,textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;box-sizing:border-box}
.flash{background:#dcfce7;border:1px solid #86efac;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.error{background:#fee2e2;border:1px solid #fca5a5;padding:8px 14px;border-radius:8px;margin:8px 0;color:#991b1b}
</style>
</head>
<body>
<?php if (session('success')): ?><div class="flash"><?= e(session('success')) ?></div><?php endif; ?>

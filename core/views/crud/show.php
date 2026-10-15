<?php
/** Zero-code detail view. @var mixed $item @var array $columns @var array $fields @var string $routeBase @var string $title */
include __DIR__ . '/layout-top.php';
$types = [];
foreach ($fields ?? [] as $f) {
    $types[$f['name']] = $f['type'];
}
?>
<div class="card">
<h1><?= e($title) ?> #<?= e((string) $item->id) ?></h1>
<table>
<?php foreach (($columns ?? []) as $c): $v = $item->{$c} ?? null; ?>
<tr><th style="width:35%"><?= e(ucwords(str_replace('_', ' ', $c))) ?></th><td>
<?php if (($types[$c] ?? null) === 'checkbox'): ?>
<span style="color:<?= $v ? '#16a34a' : '#94a3b8' ?>"><?= $v ? '✓ yes' : '✗ no' ?></span>
<?php elseif ($v === null || $v === ''): ?>
<span style="color:#cbd5e1">—</span>
<?php else: ?><?= nl2br(e((string) $v)) ?><?php endif; ?>
</td></tr>
<?php endforeach; ?>
<tr><th>Created</th><td><?= e((string) ($item->created_at ?? '—')) ?></td></tr>
</table>
<p style="margin-top:16px">
<a class="btn" href="<?= e($routeBase) ?>/<?= e((string) $item->id) ?>/edit">Edit</a>
<a class="btn secondary" href="<?= e($routeBase) ?>">Back to list</a>
</p>
</div>
<?php include __DIR__ . '/layout-bottom.php'; ?>

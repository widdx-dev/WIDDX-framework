<?php
/** Zero-code list view. @var iterable $items @var array $columns @var string $routeBase @var string $title */
include __DIR__ . '/layout-top.php';
$columns = $columns ?? [];
$types = [];
foreach ($fields ?? [] as $f) {
    $types[$f['name']] = $f['type'];
}
?>
<div class="card">
<h1><?= e($title ?? 'Items') ?></h1>
<a class="btn" href="<?= e($routeBase) ?>/create">+ New</a>
<?php if (empty($items) || count($items) === 0): ?>
<p style="color:#64748b">No records yet.</p>
<?php else: ?>
<table>
<thead><tr><?php foreach ($columns as $c): ?><th><?= e(ucwords(str_replace('_', ' ', $c))) ?></th><?php endforeach; ?><th></th></tr></thead>
<tbody>
<?php foreach ($items as $item): ?>
<tr>
<?php foreach ($columns as $c): $v = $item->{$c} ?? null; ?>
<td><?php if (($types[$c] ?? null) === 'checkbox'): ?>
<span style="color:<?= $v ? '#16a34a' : '#94a3b8' ?>"><?= $v ? '✓ yes' : '✗ no' ?></span>
<?php elseif ($v === null || $v === ''): ?>
<span style="color:#cbd5e1">—</span>
<?php else: ?><?= e((string) $v) ?><?php endif; ?></td>
<?php endforeach; ?>
<td style="white-space:nowrap;text-align:right">
<a href="<?= e($routeBase) ?>/<?= e((string) $item->id) ?>">View</a> ·
<a href="<?= e($routeBase) ?>/<?= e((string) $item->id) ?>/edit">Edit</a> ·
<form method="POST" action="<?= e($routeBase) ?>/<?= e((string) $item->id) ?>" style="display:inline" onsubmit="return confirm('Delete this record?')">
<?= csrf_field() ?><input type="hidden" name="_method" value="DELETE">
<button class="btn danger" style="padding:4px 12px;font-size:13px">Delete</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>
<?php include __DIR__ . '/layout-bottom.php'; ?>

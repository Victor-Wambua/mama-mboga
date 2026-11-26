<?php
$layout('layouts/owner', ['title' => 'Market list', 'nav' => 'shopping', 'script' => 'shopping']);
$total = count($rows);
$pct = $total === 0 ? 0 : (int) (floor(($boughtCount / $total) * 10) * 10);
?>
<div class="row between">
  <div><h1>Market shopping list</h1><p class="muted"><?= $e($dateLabel) ?>. Totals of every order still waiting to be packed.</p></div>
  <button class="btn ghost noprint" type="button" data-print>Print</button>
</div>
<?php if ($rows === []): ?>
<section class="card empty"><h2>Nothing to buy</h2><p class="muted">No orders are waiting to be packed. Orders that are already out for delivery or cancelled are not included.</p></section>
<?php else: ?>
<div class="stack noprint">
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $e($total) ?>" aria-valuenow="<?= $e($boughtCount) ?>" aria-label="Items bought"><i class="p<?= $e($pct) ?>" data-progress></i></div>
  <p class="small muted"><span data-bought-count><?= $e($boughtCount) ?></span> of <?= $e($total) ?> bought</p>
</div>
<div class="stack">
<?php foreach ($rows as $row): $line = $row['line']; ?>
  <form method="post" action="/owner/shopping/check" class="card shop-row<?= $when($row['bought'], ' done') ?>" data-row>
    <?= $csrf() ?>
    <input type="hidden" name="item_id" value="<?= $e($line->itemId) ?>">
    <input type="hidden" name="unit" value="<?= $e($line->unit->value) ?>">
    <input type="hidden" name="bought" value="<?= $e($row['bought'] ? '0' : '1') ?>" data-bought>
    <div class="names"><span class="big num"><?= $e($line->quantity->format($line->unit)) ?></span> <strong><?= $e($line->nameEn) ?></strong> <span class="muted"><?= $e($line->nameSw) ?></span>
      <br><span class="small muted">for <?= $e($line->orderCount) ?> <?= $e($line->orderCount === 1 ? 'order' : 'orders') ?></span></div>
    <button class="btn sm<?= $when(!$row['bought'], ' ghost') ?> noprint" type="submit" data-label><?= $e($row['bought'] ? 'Bought' : 'Mark bought') ?></button>
  </form>
<?php endforeach; ?>
</div>
<?php endif; ?>

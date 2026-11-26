<?php $layout('layouts/owner', ['title' => 'Run sheet', 'nav' => 'run', 'script' => 'shopping']); ?>
<div class="row between">
  <div><h1>Delivery run sheet</h1><p class="muted"><?= $e($dateLabel) ?>, in slot order.</p></div>
  <button class="btn ghost noprint" type="button" data-print>Print</button>
</div>
<?php if ($groups === [] && $pickups === []): ?>
<section class="card empty"><h2>No deliveries today</h2><p class="muted">When orders come in they are listed here by delivery window.</p></section>
<?php endif; ?>
<div class="stack-lg">
<?php foreach ($groups as $g): ?>
  <section class="stack" aria-label="<?= $e($g['slot']->label()) ?>">
    <h2><?= $e($g['slot']->label()) ?> <span class="muted small"><?= $e(count($g['orders'])) ?> stops</span></h2>
<?php foreach ($g['orders'] as $order): if ($order->fulfilment->value === 'pickup') { continue; } ?>
    <article class="card">
      <div class="row between"><strong><?= $e($order->customerName) ?></strong><span class="chip <?= $e($order->status->value) ?>"><?= $e($order->status->label($order->fulfilment)) ?></span></div>
      <p class="row"><span><?= $e($order->address) ?></span><a class="num" href="tel:+<?= $e($order->customerPhone->e164) ?>"><?= $e($order->customerPhone->local()) ?></a></p>
      <p class="small"><?php foreach ($order->lines as $i => $line): ?><?= $e($i > 0 ? ', ' : '') ?><?= $e($line->quantity->decimal()) ?> <?= $e($line->unit->label()) ?> <?= $e($line->nameEn) ?><?php endforeach; ?></p>
      <p class="row between"><strong class="num"><?= $money($order->total()->amount) ?></strong>
        <span class="tag<?= $when($order->isDeni(), ' special') ?>"><?= $e($order->isDeni() ? 'Deni: do not collect' : 'Collect payment') ?></span></p>
<?php if ($order->note !== ''): ?><p class="small">Note: <?= $e($order->note) ?></p><?php endif; ?>
    </article>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php if ($pickups !== []): ?>
  <section class="stack"><h2>Pickups at the shop</h2>
<?php foreach ($pickups as $order): ?>
    <article class="card row between"><span><strong><?= $e($order->customerName) ?></strong> <span class="muted num"><?= $e($order->customerPhone->local()) ?></span></span><span class="chip <?= $e($order->status->value) ?>"><?= $e($order->status->label($order->fulfilment)) ?></span><strong class="num"><?= $money($order->total()->amount) ?></strong></article>
<?php endforeach; ?>
  </section>
<?php endif; ?>
</div>

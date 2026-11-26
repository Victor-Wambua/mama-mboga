<?php $layout('layouts/public', ['title' => 'Order ' . $order->reference, 'noindex' => true]); ?>
<section class="hero">
  <span class="date">Order received</span>
  <h1><?= $e($order->reference) ?></h1>
  <p>Asante, <?= $e($order->customerName) ?>. The shop has your order and will start packing it.</p>
</section>

<section class="card stack">
  <h2>What you ordered</h2>
  <ul class="plain">
<?php foreach ($order->lines as $line): ?>
    <li class="row between"><span><?= $e($line->nameSw) ?> <span class="muted">(<?= $e($line->nameEn) ?>)</span><br><span class="small muted"><?= $e($line->quantity->format($line->unit)) ?> at <?= $money($line->unitPrice->amount) ?></span></span><strong class="num"><?= $money($line->total()->amount) ?></strong></li>
<?php endforeach; ?>
  </ul>
  <div class="total-line"><span>Total</span><span class="num"><?= $money($order->total()->amount) ?></span></div>
  <p class="muted">
<?php if ($order->fulfilment->value === 'delivery'): ?>
    Delivery <?= $e($slot?->label() ?? 'today') ?> to <?= $e($order->address) ?>.
<?php else: ?>
    Pick up at the shop.
<?php endif; ?>
    <?= $e($order->isDeni() ? 'Payment: on your deni.' : 'Pay on delivery, cash or M-Pesa.') ?>
  </p>
</section>

<section class="card stack">
  <h2>Send it on WhatsApp</h2>
  <p>This opens WhatsApp with your order already typed. Press send in WhatsApp to message the shop. It is optional: your order is already saved.</p>
  <a class="btn block" href="<?= $e($whatsappUrl) ?>" rel="noopener">Open WhatsApp</a>
</section>
<p><a href="/">Back to today's prices</a></p>

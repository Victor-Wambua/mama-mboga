<?php
use MamaMboga\Domain\Order\OrderStatus;
$slotLabel = $slots[$order->slotId ?? 0] ?? null;
?>
<article class="card order">
  <h3>
    <a href="/owner/orders/<?= $e($order->id) ?>"><?= $e($order->reference) ?></a>
    <span class="chip <?= $e($order->status->value) ?>"><?= $e($order->status->label($order->fulfilment)) ?></span>
  </h3>
  <p class="row"><strong><?= $e($order->customerName) ?></strong><span class="muted num"><?= $e($order->customerPhone->local()) ?></span>
<?php if ($order->source === 'whatsapp'): ?><span class="tag">WhatsApp</span><?php endif; ?>
<?php if ($order->isDeni()): ?><span class="tag special">Deni</span><?php endif; ?></p>
  <p class="small muted">
<?php if ($order->fulfilment->value === 'pickup'): ?>Pickup
<?php else: ?><?= $e($slotLabel?->label() ?? 'Delivery') ?>, <?= $e($order->address) ?>
<?php endif; ?></p>
  <ul>
<?php foreach ($order->lines as $line): ?>
    <li><?= $e($line->quantity->format($line->unit)) ?> <?= $e($line->nameEn) ?> <span class="muted">(<?= $e($line->nameSw) ?>)</span></li>
<?php endforeach; ?>
  </ul>
<?php if ($order->note !== ''): ?><p class="small">Note: <?= $e($order->note) ?></p><?php endif; ?>
  <div class="row between"><strong class="num"><?= $money($order->total()->amount) ?></strong>
<?php if ($order->settledVia !== null): ?><span class="small muted">Settled: <?= $e($order->settledVia) ?></span><?php endif; ?></div>
<?php if (!$order->status->isFinal()): ?>
  <div class="actions noprint">
<?php foreach ($order->status->next() as $to):
    $label = match ($to) {
        OrderStatus::Packing => 'Start packing',
        OrderStatus::Out => $order->fulfilment->value === 'pickup' ? 'Ready for pickup' : 'Out for delivery',
        OrderStatus::Delivered => $order->fulfilment->value === 'pickup' ? 'Collected' : 'Delivered',
        default => 'Cancel',
    }; ?>
    <form method="post" action="/owner/orders/<?= $e($order->id) ?>/status">
      <?= $csrf() ?>
      <input type="hidden" name="status" value="<?= $e($to->value) ?>">
      <input type="hidden" name="back" value="<?= $e($path) ?>">
<?php if ($to === OrderStatus::Delivered && !$order->isDeni()): ?>
      <select class="input auto" name="paid_with" aria-label="Paid with"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select>
<?php endif; ?>
      <button class="btn sm<?= $when($to === OrderStatus::Cancelled, ' danger') ?><?= $when($to !== OrderStatus::Cancelled && $to !== OrderStatus::Delivered, ' ghost') ?>" type="submit"><?= $e($label) ?></button>
    </form>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</article>

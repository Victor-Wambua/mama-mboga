<?php $layout('layouts/owner', ['title' => 'Order ' . $order->reference, 'nav' => 'orders']); ?>
<p><a href="/owner">&larr; All orders</a></p>
<div class="orders">
  <?= $partial('partials/order-card', ['order' => $order, 'slots' => $slot === null ? [] : [(int) $slot->id => $slot]]) ?>
  <section class="card stack">
    <h2>Tell the customer</h2>
    <p class="muted">Opens WhatsApp to <?= $e($order->customerPhone->local()) ?> with a short status note. You press send.</p>
    <a class="btn block" href="<?= $e($customerWhatsapp) ?>" rel="noopener">Message <?= $e($order->customerName) ?></a>
    <p class="small muted">Placed <?= $e($order->createdAt->format('D j M, g:ia')) ?> via <?= $e($order->source === 'whatsapp' ? 'a pasted WhatsApp message' : 'the shop front') ?>.</p>
  </section>
</div>

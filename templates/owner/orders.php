<?php $layout('layouts/owner', ['title' => 'Orders', 'nav' => 'orders']); ?>
<div class="row between">
  <h1><?= $e($isToday ? "Today's orders" : 'Orders for ' . $date) ?></h1>
  <a class="btn sm" href="/owner/paste">Paste a WhatsApp order</a>
</div>
<nav class="filters" aria-label="Filter by status">
  <a href="/owner<?= $e($isToday ? '' : '?date=' . $date) ?>"<?= $raw($filter === null ? ' aria-current="true"' : '') ?>>All <span class="num"><?= $e(array_sum($counts)) ?></span></a>
<?php foreach (['received' => 'Received', 'packing' => 'Packing', 'out' => 'Out', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $key => $label): ?>
  <a href="/owner?status=<?= $e($key) ?><?= $e($isToday ? '' : '&date=' . $date) ?>"<?= $raw($filter?->value === $key ? ' aria-current="true"' : '') ?>><?= $e($label) ?> <span class="num"><?= $e($counts[$key]) ?></span></a>
<?php endforeach; ?>
</nav>
<?php if ($orders === []): ?>
<section class="empty card">
  <h2>No orders here yet</h2>
  <p class="muted">Orders from the shop front and pasted WhatsApp messages land here.</p>
</section>
<?php else: ?>
<div class="orders">
<?php foreach ($orders as $order): ?>
  <?= $partial('partials/order-card', ['order' => $order, 'slots' => $slots]) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>

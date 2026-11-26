<?php $layout('layouts/public', ['title' => 'Statement for ' . $customer->name, 'noindex' => true]); ?>
<section class="hero">
  <span class="date">Deni statement</span>
  <h1><?= $e($customer->name) ?></h1>
  <p class="muted">Everything you have taken on credit from <?= $e($shopName) ?>, and everything you have paid.</p>
</section>
<section class="stack-lg">
  <div class="kpis">
    <div class="kpi hi"><b class="num"><?= $money($balance->amount) ?></b><span>You owe</span></div>
    <div class="kpi"><b class="num"><?= $money($customer->creditLimit->amount) ?></b><span>Your credit limit</span></div>
  </div>
<?php if ($balance->isPositive()): ?>
  <div class="card stack"><h2>How old is it</h2><?= $partial('partials/aging', ['aging' => $aging]) ?></div>
<?php endif; ?>
  <div class="card stack"><h2>Entries, newest first</h2><?= $partial('partials/ledger-table', ['rows' => $rows]) ?></div>
  <p class="small muted">Pay by M-Pesa or cash and tell the shop. Entries are never edited: a mistake is fixed with a new entry, so this list is always a true record. Something looks wrong? Message the shop.</p>
</section>

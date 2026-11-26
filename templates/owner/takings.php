<?php $layout('layouts/owner', ['title' => 'Takings', 'nav' => 'takings']); ?>
<div class="row between">
  <div><h1>Daily takings</h1><p class="muted"><?= $e($dateLabel) ?></p></div>
  <form method="get" action="/owner/takings" class="row noprint">
    <label class="sr" for="d">Date</label><input class="input auto" id="d" type="date" name="date" value="<?= $e($date) ?>">
    <button class="btn sm ghost" type="submit">Show</button>
  </form>
</div>
<div class="kpis">
  <div class="kpi hi"><b class="num"><?= $money($t->totalReceived()->amount) ?></b><span>Money received</span></div>
  <div class="kpi"><b class="num"><?= $money($t->sales->amount) ?></b><span>Sales delivered</span></div>
  <div class="kpi"><b class="num"><?= $money($t->onDeni->amount) ?></b><span>Put on deni</span></div>
  <div class="kpi"><b class="num"><?= $e($t->delivered) ?></b><span>Orders delivered</span></div>
</div>
<div class="card">
  <h2>Where the money is</h2>
  <div class="table-wrap"><table>
    <tbody>
      <tr><td>Cash at the door</td><td class="r num"><?= $money($t->cashAtDoor->amount) ?></td></tr>
      <tr><td>M-Pesa at the door</td><td class="r num"><?= $money($t->mpesaAtDoor->amount) ?></td></tr>
      <tr><td>Old deni paid in cash</td><td class="r num"><?= $money($t->debtCashCollected->amount) ?></td></tr>
      <tr><td>Old deni paid by M-Pesa</td><td class="r num"><?= $money($t->debtMpesaCollected->amount) ?></td></tr>
      <tr><th scope="row">Cash in hand</th><td class="r num"><strong><?= $money($t->cashInHand()->amount) ?></strong></td></tr>
      <tr><th scope="row">M-Pesa received</th><td class="r num"><strong><?= $money($t->mpesaReceived()->amount) ?></strong></td></tr>
    </tbody></table></div>
</div>
<p class="muted small"><?= $e($t->open) ?> <?= $e($t->open === 1 ? 'order is' : 'orders are') ?> still open and <?= $e($t->cancelled) ?> cancelled. Sales are counted when an order is marked delivered.</p>

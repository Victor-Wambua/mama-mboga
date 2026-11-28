<?php $layout('layouts/owner', ['title' => $customer->name, 'nav' => 'deni']); ?>
<p><a href="/owner/deni">&larr; Deni book</a></p>
<div class="row between"><div><h1><?= $e($customer->name) ?></h1><p class="muted num"><?= $e($customer->phone->local()) ?></p></div></div>
<div class="kpis">
  <div class="kpi hi"><b class="num"><?= $money($balance->amount) ?></b><span>Owes now</span></div>
  <div class="kpi"><b class="num"><?= $money($customer->creditLimit->amount) ?></b><span>Credit limit</span></div>
  <div class="kpi"><b class="num"><?= $money($available->amount) ?></b><span>Room left</span></div>
</div>
<section class="card stack"><h2>How old is it</h2><?= $partial('partials/aging', ['aging' => $aging]) ?></section>

<?php if ($balance->isPositive()): ?>
<section class="card stack">
  <h2>Remind politely</h2>
  <p class="muted">Opens WhatsApp with a message ready to send. You press send.</p>
  <div class="row"><a class="btn" href="<?= $e($reminderSw) ?>" rel="noopener">Reminder in Swahili</a><a class="btn ghost" href="<?= $e($reminderEn) ?>" rel="noopener">In English</a></div>
  <p class="small muted">Statement link the customer can open without logging in (valid 30 days):</p>
  <p class="copybox"><?= $e($statementUrl) ?></p>
</section>
<?php endif; ?>

<?= $partial('partials/prompts', ['customer' => $customer, 'balance' => $balance, 'prompts' => $prompts, 'collectEnabled' => $collectEnabled]) ?>

<section class="card stack">
  <h2>Record a payment</h2>
  <form method="post" action="/owner/deni/<?= $e($customer->id) ?>/payment" class="stack">
    <?= $csrf() ?>
    <div class="edit-row">
      <label class="field"><span>Amount (KSh)</span><input class="input num" name="amount" inputmode="numeric" required></label>
      <label class="field"><span>Paid by</span><select class="input" name="method"><option value="mpesa">M-Pesa</option><option value="cash">Cash</option></select></label>
      <label class="field"><span>M-Pesa code <span class="hint">cash gets a receipt number</span></span><input class="input" name="receipt" maxlength="12" autocapitalize="characters" placeholder="SGH1A2B3C4"></label>
      <label class="field"><span>Note <span class="hint">optional</span></span><input class="input" name="note" maxlength="100"></label>
    </div>
    <button class="btn" type="submit">Record payment</button>
  </form>
</section>

<section class="card stack">
  <h2>Ledger</h2>
  <?= $partial('partials/ledger-table', ['rows' => $rows]) ?>
</section>

<details class="card">
  <summary>Other entries and limit</summary>
  <div class="stack-lg">
    <form method="post" action="/owner/deni/<?= $e($customer->id) ?>/charge" class="stack">
      <?= $csrf() ?><h3>Add an old balance or charge</h3>
      <div class="edit-row"><label class="field"><span>Amount (KSh)</span><input class="input num" name="amount" inputmode="numeric" required></label>
        <label class="field wide"><span>What for</span><input class="input" name="note" maxlength="100" placeholder="Notebook balance from February" required></label></div>
      <button class="btn ghost" type="submit">Add charge</button>
    </form>
    <form method="post" action="/owner/deni/<?= $e($customer->id) ?>/credit-note" class="stack">
      <?= $csrf() ?><h3>Credit note (a correction or write-off)</h3>
      <div class="edit-row"><label class="field"><span>Amount (KSh)</span><input class="input num" name="amount" inputmode="numeric" required></label>
        <label class="field wide"><span>Reason</span><input class="input" name="note" maxlength="100" placeholder="Returned wilted kale" required></label></div>
      <button class="btn ghost" type="submit">Add credit note</button>
    </form>
    <form method="post" action="/owner/deni/<?= $e($customer->id) ?>/limit" class="stack">
      <?= $csrf() ?><h3>Credit limit</h3>
      <label class="field"><span>Limit (KSh, 0 means no deni)</span><input class="input num" name="limit" inputmode="numeric" value="<?= $e($customer->creditLimit->amount) ?>"></label>
      <button class="btn ghost" type="submit">Save limit</button>
    </form>
  </div>
</details>
<p class="small muted">Entries can never be edited or deleted. A mistake is corrected with a new entry, so the book stays a true record.</p>

<section class="card stack" aria-labelledby="prompt-h">
  <h2 id="prompt-h">Collect deni by prompt</h2>
<?php if (!$collectEnabled): ?>
  <p class="muted">Not set up. Set <code>GATEWAY_URL</code> and <code>GATEWAY_SECRET_KEY</code> in <code>.env</code> to send an M-Pesa prompt to <?= $e($customer->name) ?>'s phone from here.</p>
<?php elseif ($balance->isPositive()): ?>
  <p class="muted">Sends an M-Pesa prompt to <?= $e($customer->name) ?>'s own number. The book is only written when the gateway reports the payment succeeded.</p>
  <form method="post" action="/owner/deni/<?= $e($customer->id) ?>/collect" class="stack">
    <?= $csrf() ?>
    <label class="field"><span>Amount (KSh) <span class="hint">leave blank for the whole <?= $money($balance->amount) ?>; never more than is owed</span></span><input class="input num" name="amount" inputmode="numeric" placeholder="<?= $e($balance->amount) ?>" max="<?= $e($balance->amount) ?>"></label>
    <button class="btn" type="submit">Send M-Pesa prompt</button>
  </form>
<?php else: ?>
  <p class="muted"><?= $e($customer->name) ?> owes nothing, so there is nothing to collect.</p>
<?php endif; ?>
<?php if ($prompts !== []): ?>
  <h3>Prompts sent</h3>
  <ul class="plain">
<?php foreach ($prompts as $p): $d = $p->display(); ?>
    <li id="prompt-<?= $e($p->id) ?>">
      <strong class="num"><?= $e($p->reference) ?></strong>, <span class="num"><?= $money($p->amount) ?></span>, <?= $e($p->createdAt->format('j M H:i')) ?><br>
      <strong class="state-<?= $e($d['state']) ?>"><?= $e($d['label']) ?>.</strong> <span class="small"><?= $e($d['detail']) ?></span>
<?php if ($p->checkable()): ?>
      <form method="post" action="/owner/deni/prompts/<?= $e($p->id) ?>/check" class="inline"><?= $csrf() ?><button class="btn ghost" type="submit">Check status</button></form>
<?php endif; ?>
<?php if ($p->needsOwner()): ?>
      <form method="post" action="/owner/deni/prompts/<?= $e($p->id) ?>/review" class="stack">
        <?= $csrf() ?>
        <label class="field"><span>Note <span class="hint">optional, for example what you found in M-Pesa</span></span><input class="input" name="note" maxlength="140"></label>
        <button class="btn ghost" type="submit">I have looked at this</button>
        <span class="small muted">This only marks it seen. To put the money in the book, use Record a payment with the M-Pesa code.</span>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

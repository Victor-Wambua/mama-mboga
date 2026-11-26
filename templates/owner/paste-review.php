<?php
$layout('layouts/owner', ['title' => 'Check the order', 'nav' => 'paste']);
$flagged = count(array_filter($draft, static fn (array $d): bool => $d['review']));
?>
<p><a href="/owner/paste">&larr; Start again</a></p>
<h1>Check the order</h1>
<?php if ($errors !== []): ?>
<div class="flash error" role="alert"><ul><?php foreach ($errors as $m): ?><li><?= $e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($draft === []): ?>
<section class="card empty"><h2>No items found</h2><p class="muted">Nothing in that message matched today's price board. You can add the lines by hand after going back, or ask the customer to resend.</p></section>
<?php else: ?>
<p><?= $e(count($draft)) ?> <?= $e(count($draft) === 1 ? 'line' : 'lines') ?> read<?= $e($flagged > 0 ? ', ' . $flagged . ' to check' : ', all clear') ?>.</p>
<?php endif; ?>

<details class="card flat"><summary>Original message</summary><pre class="copybox"><?= $e($message) ?></pre></details>

<form method="post" action="/owner/paste/confirm" class="stack-lg">
  <?= $csrf() ?>
  <input type="hidden" name="message" value="<?= $e($message) ?>">
  <div class="stack">
<?php foreach ($draft as $i => $d): ?>
    <div class="card flat<?= $when($d['review'], ' flag') ?><?= $when($d['status'] === 'unmatched', ' bad') ?>">
      <input type="hidden" name="line[<?= $e($i) ?>][raw]" value="<?= $e($d['raw']) ?>">
      <p class="small muted">Read from: <?= $e($d['raw']) ?>
</p>
<?php if ($d['review']): ?><p><strong>Check this line</strong></p><?php endif; ?>
<?php foreach ($d['flags'] as $flag): ?><p class="err"><?= $e($flag) ?></p><?php endforeach; ?>
<?php if ($d['status'] === 'unmatched'): ?><p class="err">Not on today's board. Pick the item, or tick remove.</p><?php elseif ($d['status'] === 'ambiguous'): ?><p class="err">Could be more than one item. Pick the right one.</p><?php elseif ($d['status'] === 'suggested'): ?><p class="err">Only a partial match. Confirm the item.</p><?php endif; ?>
      <div class="edit-row">
        <label class="field wide"><span>Item</span>
          <select class="input" name="line[<?= $e($i) ?>][entry]">
            <option value="">Choose an item</option>
<?php foreach ($board as $s): ?>
            <option value="<?= $e($s->entryId) ?>"<?= $raw($d['entry'] === $s->entryId ? ' selected' : '') ?>><?= $e($s->nameEn) ?> / <?= $e($s->nameSw) ?> (<?= $money($s->price->amount) ?> per <?= $e($s->unit->label()) ?>)<?= $e(in_array((int) $s->entryId, $d['alternatives'], true) ? ' *' : '') ?></option>
<?php endforeach; ?>
          </select></label>
        <label class="field"><span>Quantity</span><input class="input num" name="line[<?= $e($i) ?>][qty]" inputmode="decimal" value="<?= $e($d['qty']) ?>"></label>
        <label class="choice"><input type="checkbox" name="line[<?= $e($i) ?>][drop]" value="1"> Remove</label>
      </div>
    </div>
<?php endforeach; ?>
<?php foreach ($ignored as $text): ?>
    <p class="small muted">Skipped as chat, not an order: <em><?= $e($text) ?></em></p>
<?php endforeach; ?>
  </div>

  <section class="card stack">
    <h2>Customer and delivery</h2>
<?php if ($timeHint !== null): ?><p class="flash ok">The customer asked for: <strong><?= $e($timeHint) ?></strong>. Pick the closest slot.</p><?php endif; ?>
    <div class="edit-row">
      <label class="field<?= $when(isset($errors['name']), ' bad') ?>"><span>Name</span><input class="input" name="name" maxlength="80" value="<?= $e($input['name'] ?? '') ?>" required></label>
      <label class="field<?= $when(isset($errors['phone']), ' bad') ?>"><span>Phone</span><input class="input" name="phone" type="tel" inputmode="tel" value="<?= $e($input['phone'] ?? '') ?>" placeholder="0700 000 001" required></label>
    </div>
    <div class="choices">
      <label class="choice"><input type="radio" name="fulfilment" value="delivery"<?= $raw(($input['fulfilment'] ?? 'delivery') === 'delivery' ? ' checked' : '') ?>> Delivery</label>
      <label class="choice"><input type="radio" name="fulfilment" value="pickup"<?= $raw(($input['fulfilment'] ?? '') === 'pickup' ? ' checked' : '') ?>> Pickup</label>
    </div>
    <label class="field"><span>Delivery slot</span>
      <select class="input" name="slot">
        <option value="">Choose a slot (not needed for pickup)</option>
<?php foreach ($slots as $row): $slot = $row['slot']; ?>
        <option value="<?= $e($slot->id) ?>"<?= $raw(!$row['open'] ? ' disabled' : '') ?><?= $raw(($input['slot'] ?? '') === (string) $slot->id ? ' selected' : '') ?>><?= $e($slot->label()) ?> (<?= $e($row['open'] ? $row['left'] . ' left' : 'closed') ?>)</option>
<?php endforeach; ?>
      </select></label>
    <label class="field"><span>Address</span><input class="input" name="address" maxlength="200" value="<?= $e($input['address'] ?? '') ?>"></label>
    <label class="choice"><input type="checkbox" name="payment" value="deni"> <span>Put it on deni <span class="small muted">(needs a credit limit)</span></span></label>
    <button class="btn block" type="submit">Confirm and save order</button>
  </section>
</form>

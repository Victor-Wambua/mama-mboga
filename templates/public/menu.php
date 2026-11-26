<?php
$layout('layouts/public', ['title' => "Today's prices", 'script' => 'basket']);
$bad = static fn (array $errors, string $key): string => isset($errors[$key]) ? ' bad' : '';
$oldQty = is_array($old['qty'] ?? null) ? $old['qty'] : [];
$fulfilment = $old['fulfilment'] ?? 'delivery';
?>
<section class="hero">
  <span class="date"><?= $e($dateLabel) ?></span>
  <h1>Today's prices</h1>
  <p class="muted"><?= $e($shopTagline) ?></p>
</section>

<?php if ($errors !== []): ?>
<div class="flash error" role="alert">
  <strong>Please check:</strong>
  <ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($board === []): ?>
<section class="empty card">
  <h2>Today's list is not up yet</h2>
  <p class="muted">The shop sets prices every morning after the market. Check back soon.</p>
</section>
<?php else: ?>
<form method="post" action="/order" data-basket>
  <?= $csrf() ?>
  <ul class="items">
<?php foreach ($board as $s): $id = (int) $s->entryId; ?>
    <li class="card item<?= $when($s->isSoldOut(), ' soldout') ?>">
      <div class="names"><strong><?= $e($s->nameSw) ?></strong><span class="muted"><?= $e($s->nameEn) ?></span></div>
      <div class="price num"><?= $money($s->price->amount) ?> <small>/ <?= $e($s->unit->label()) ?></small></div>
      <div class="meta">
<?php if ($s->special): ?><span class="tag special">Special<?= $when($s->specialNote !== '', ': ' . $s->specialNote) ?></span><?php endif; ?>
<?php if ($s->isSoldOut()): ?>
        <span class="tag">Sold out</span>
<?php else: ?>
<?php if ($s->available->milli <= 5000): ?><span class="tag low">Only <?= $e($qty($s->available->milli)) ?> left</span><?php endif; ?>
        <span class="grow"></span>
        <span class="stepper"><input class="qty-input" type="number" inputmode="decimal" min="0" max="<?= $e($qty($s->available->milli)) ?>" step="<?= $e($qty($s->unit->increment())) ?>" name="qty[<?= $e($id) ?>]" value="<?= $e($oldQty[$id] ?? '') ?>" placeholder="0" aria-label="How many <?= $e($s->unit->label()) ?> of <?= $e($s->nameEn) ?>" data-price="<?= $e($s->price->amount) ?>" data-step="<?= $e($s->unit->step()) ?>" data-name="<?= $e($s->nameEn) ?>"></span>
<?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>

  <section id="details" class="card stack" aria-labelledby="details-h">
    <h2 id="details-h">Your order</h2>
    <div class="total-line"><span>Total</span><span class="num" id="form-total">KSh 0</span></div>

    <div class="field<?= $e($bad($errors, 'name')) ?>">
      <label for="name">Your name</label>
      <input class="input" id="name" name="name" autocomplete="name" required minlength="2" maxlength="80" value="<?= $e($old['name'] ?? '') ?>">
    </div>
    <div class="field<?= $e($bad($errors, 'phone')) ?>">
      <label for="phone">Phone number <span class="hint">M-Pesa or WhatsApp number</span></label>
      <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0700 000 001" required value="<?= $e($old['phone'] ?? '') ?>">
    </div>

    <fieldset class="field">
      <legend class="sr">Delivery or pickup</legend>
      <div class="choices">
        <label class="choice"><input type="radio" name="fulfilment" value="delivery"<?= $raw($fulfilment === 'delivery' ? ' checked' : '') ?>> Deliver to me</label>
        <label class="choice"><input type="radio" name="fulfilment" value="pickup"<?= $raw($fulfilment === 'pickup' ? ' checked' : '') ?>> I will pick up at the shop</label>
      </div>
    </fieldset>

    <div class="stack" data-delivery-only>
      <div class="field<?= $e($bad($errors, 'slot')) ?>">
        <span>Delivery time today</span>
        <div class="choices">
<?php foreach ($slots as $row): $slot = $row['slot']; ?>
          <label class="choice<?= $when(!$row['open'], ' off') ?>"><input type="radio" name="slot" value="<?= $e($slot->id) ?>"<?= $raw(!$row['open'] ? ' disabled' : '') ?><?= $raw(($old['slot'] ?? '') === (string) $slot->id ? ' checked' : '') ?>>
            <span class="grow"><?= $e($slot->label()) ?></span>
            <span class="small muted"><?= $e($row['open'] ? $row['left'] . ' left' : ($row['left'] === 0 ? 'Full' : 'Closed')) ?></span></label>
<?php endforeach; ?>
        </div>
      </div>
      <div class="field<?= $e($bad($errors, 'address')) ?>">
        <label for="address">Where to? <span class="hint">estate, road, landmark</span></label>
        <input class="input" id="address" name="address" autocomplete="street-address" maxlength="200" value="<?= $e($old['address'] ?? '') ?>">
      </div>
    </div>

    <fieldset class="field">
      <legend class="sr">How you will pay</legend>
      <div class="choices">
        <label class="choice"><input type="radio" name="payment" value="pay"<?= $raw(($old['payment'] ?? 'pay') !== 'deni' ? ' checked' : '') ?>> <span>Pay on delivery (cash or M-Pesa)</span></label>
        <label class="choice"><input type="radio" name="payment" value="deni"<?= $raw(($old['payment'] ?? '') === 'deni' ? ' checked' : '') ?>> <span>Put it on my deni <span class="small muted">(regular customers)</span></span></label>
      </div>
      <?php if (isset($errors['payment'])): ?><p class="err"><?= $e($errors['payment']) ?></p><?php endif; ?>
    </fieldset>

    <div class="field">
      <label for="note">Note for the shop <span class="hint">optional</span></label>
      <input class="input" id="note" name="note" maxlength="200" value="<?= $e($old['note'] ?? '') ?>">
    </div>

    <button class="btn block accent" type="submit">Place order</button>
    <p class="small muted">After you place the order we show you a WhatsApp button so you can send it to the shop. The shop gets your order either way.</p>
  </section>

  <div class="bar" id="basket-bar" hidden>
    <div class="wrap">
      <div><strong class="num" id="basket-total">KSh 0</strong><br><span class="small" id="basket-count"></span></div>
      <a class="btn sm" href="#details">Checkout</a>
    </div>
  </div>
</form>
<?php endif; ?>

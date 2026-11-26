<div class="table-wrap">
<table class="ledger">
  <thead><tr><th>Date</th><th>What</th><th class="r">Amount</th><th class="r">Balance</th></tr></thead>
  <tbody>
<?php foreach ($rows as $row): $entry = $row['entry']; ?>
    <tr>
      <td class="num"><?= $e($entry->occurredAt->format('j M')) ?></td>
      <td><?= $e($entry->type->label()) ?><?= $when($entry->receiptCode !== null, ' ' . ($entry->receiptCode ?? '')) ?><?php if ($entry->method === 'mpesa'): ?> <span class="tag">M-Pesa</span><?php elseif ($entry->method === 'cash'): ?> <span class="tag">Cash</span><?php endif; ?><?php if ($entry->note !== ''): ?><br><span class="small muted"><?= $e($entry->note) ?></span><?php endif; ?></td>
      <td class="r num <?= $e($entry->type->increasesBalance() ? 'out' : 'in') ?>"><?= $e($entry->type->increasesBalance() ? '+' : '-') ?><?= $money($entry->amount->amount) ?></td>
      <td class="r num"><?= $money($row['balance']->amount) ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="4" class="muted">Nothing has been recorded yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

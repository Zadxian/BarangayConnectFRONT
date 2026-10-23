<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <h2>Requests by status</h2>
  <?php if (!$requests): ?><p>No request data yet.</p><?php else: ?>
  <table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>
    <?php foreach ($requests as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<section class="card">
  <h2>Incidents by type</h2>
  <?php if (!$incidents): ?><p>No incident data yet.</p><?php else: ?>
  <table><thead><tr><th>Incident type</th><th>Count</th></tr></thead><tbody>
    <?php foreach ($incidents as $row): ?><tr><td><?= e($row['incident_type']) ?></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<section class="card">
  <h2>Monthly document requests (past 12 months)</h2>
  <?php if (!$monthly): ?><p>No requests have been submitted in the last 12 months.</p><?php else: ?>
  <table><thead><tr><th>Month</th><th>Requests</th></tr></thead><tbody>
    <?php foreach ($monthly as $row): ?><tr><td><?= e($row['month']) ?></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<section class="card">
  <h2>Payments</h2>
  <?php if (!$payments): ?><p>No payments have been recorded.</p><?php else: ?>
  <table><thead><tr><th>Provider</th><th>Status</th><th>Transactions</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($payments as $row): ?><tr><td><?= e($row['provider']) ?></td><td><?= e($row['status']) ?></td><td><?= e($row['total']) ?></td><td>₱<?= e(number_format((float)$row['amount'], 2)) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<button class="btn" type="button" onclick="window.print()">Print report</button>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

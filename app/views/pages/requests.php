<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <p class="muted">Check the status and payment state of your applications.</p>
  <a class="btn" href="<?= site_url('resident/requests/new') ?>">New document request</a>
  <?php if (!$requests): ?>
    <p style="margin-top:16px">You have not submitted any document requests yet.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Reference</th><th>Document</th><th>Purpose</th><th>Status</th><th>Payment</th><th>Fee</th><th>Staff remarks</th><th>Submitted</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td><?= e($request['reference_no']) ?></td>
          <td><?= e($request['service_name']) ?></td>
          <td><?= e($request['purpose']) ?></td>
          <td><?= e($request['status']) ?></td>
          <td><?= e($request['payment_status']) ?></td>
          <td>₱<?= e(number_format((float)$request['fee'], 2)) ?>
            <?php if ((float)$request['fee'] > 0 && $request['payment_status'] !== 'Paid' && $request['status'] !== 'Rejected'): ?>
              <form method="post" action="<?= site_url('resident/requests/' . $request['id'] . '/payment') ?>">
                <?php csrf_field(); ?>
                <button class="btn" type="submit">Pay online</button>
              </form>
            <?php endif; ?>
          </td>
          <td><?= e($request['staff_remarks'] ?: '—') ?></td>
          <td><?= e($request['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

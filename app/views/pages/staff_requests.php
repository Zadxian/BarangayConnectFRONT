<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <?php if (!$requests): ?>
    <p>No document requests have been submitted.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Reference and resident</th><th>Document and purpose</th><th>Valid ID</th><th>Payment</th><th>Status / review</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td>
            <strong><?= e($request['reference_no']) ?></strong><br>
            <?= e($request['full_name'] ?: $request['email']) ?><br>
            <span class="muted"><?= e($request['email']) ?> · <?= e($request['contact'] ?: 'No phone') ?></span>
          </td>
          <td><?= e($request['service_name']) ?><br><?= e($request['purpose']) ?><br><span class="muted">Submitted <?= e($request['created_at']) ?></span></td>
          <td>
            <?php if ($request['valid_id_path']): ?>
              <a href="<?= site_url('staff/files/ids/' . basename($request['valid_id_path'])) ?>">Download valid ID</a>
            <?php endif; ?>
          </td>
          <td>₱<?= e(number_format((float)$request['fee'], 2)) ?><br><?= e($request['payment_status']) ?>
            <?php if ($request['payment_status'] !== 'Paid'): ?>
              <br><label><input form="request-<?= e($request['id']) ?>" type="checkbox" name="payment_action" value="paid"> Confirm received in person</label>
            <?php endif; ?>
          </td>
          <td>
            <form id="request-<?= e($request['id']) ?>" method="post" action="<?= site_url('staff/requests/' . $request['id']) ?>">
              <?php csrf_field(); ?>
              <label for="status-<?= e($request['id']) ?>">Status</label>
              <select id="status-<?= e($request['id']) ?>" name="status" required>
                <?php foreach (['Under Review', 'Approved', 'Rejected', 'Ready for Pickup', 'Released'] as $status): ?>
                  <option value="<?= e($status) ?>"<?= $request['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
              </select>
              <label for="remarks-<?= e($request['id']) ?>">Remarks</label>
              <textarea id="remarks-<?= e($request['id']) ?>" name="staff_remarks"><?= e($request['staff_remarks']) ?></textarea>
              <button class="btn" type="submit">Save review</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

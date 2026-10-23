<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <?php if (!$incidents): ?>
    <p>No incident reports have been filed.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Report</th><th>Details</th><th>Evidence</th><th>Location</th><th>Case management</th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $incident): ?>
        <tr>
          <td><strong><?= e($incident['reference_no']) ?></strong><br><?= e($incident['incident_type']) ?><br><?= e($incident['incident_date']) ?><br><?= e($incident['reporter']) ?><br><?= e($incident['contact'] ?: 'No phone') ?></td>
          <td><?= e($incident['description']) ?><br><span class="muted">Filed <?= e($incident['created_at']) ?></span></td>
          <td>
            <?php if ($incident['evidence_path']): ?>
              <a href="<?= site_url('staff/files/evidence/' . basename($incident['evidence_path'])) ?>">Download evidence</a>
            <?php endif; ?>
          </td>
          <td>
            <?= e($incident['location']) ?>
            <?php if ($incident['latitude'] !== null && $incident['longitude'] !== null): ?>
              <br><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e($incident['latitude']) ?>,<?= e($incident['longitude']) ?>">Open map</a>
            <?php endif; ?>
            <?php if ($incident['next_hearing']): ?><br>Hearing: <?= e($incident['next_hearing']) ?><?php endif; ?>
          </td>
          <td>
            <form method="post" action="<?= site_url('staff/incidents/' . $incident['id']) ?>">
              <?php csrf_field(); ?>
              <label>Status</label>
              <select name="status" required>
                <?php foreach (['Under Review', 'Hearing Scheduled', 'Resolved', 'Dismissed'] as $status): ?>
                  <option value="<?= e($status) ?>"<?= $incident['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
              </select>
              <label>Staff remarks</label>
              <textarea name="staff_remarks"><?= e($incident['staff_remarks']) ?></textarea>
              <button class="btn" type="submit">Save case</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

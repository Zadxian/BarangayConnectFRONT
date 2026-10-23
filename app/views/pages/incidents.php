<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <p class="muted">Your reports and any upcoming mediation hearing.</p>
  <a class="btn" href="<?= site_url('resident/incidents/new') ?>">File an incident report</a>
  <?php if (!$incidents): ?>
    <p style="margin-top:16px">You have not filed any incident reports yet.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Reference</th><th>Type</th><th>Date</th><th>Location</th><th>Status</th><th>Next hearing</th><th>Staff remarks</th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $incident): ?>
        <tr>
          <td><?= e($incident['reference_no']) ?></td>
          <td><?= e($incident['incident_type']) ?></td>
          <td><?= e($incident['incident_date']) ?></td>
          <td><?= e($incident['location']) ?></td>
          <td><?= e($incident['status']) ?></td>
          <td><?= e($incident['next_hearing'] ?: '—') ?></td>
          <td><?= e($incident['staff_remarks'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

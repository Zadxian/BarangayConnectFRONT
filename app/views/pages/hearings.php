<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <h2>Schedule a hearing</h2>
  <form method="post" action="<?= site_url('staff/hearings') ?>" style="max-width:620px">
    <?php csrf_field(); ?>
    <label for="incident_id">Incident</label>
    <select id="incident_id" name="incident_id" required>
      <option value="">Choose an open case</option>
      <?php foreach ($incidents as $incident): ?>
        <option value="<?= e($incident['id']) ?>"><?= e($incident['reference_no'] . ' · ' . $incident['incident_type'] . ' · ' . $incident['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="scheduled_at">Date and time</label>
    <input id="scheduled_at" name="scheduled_at" type="datetime-local" required>
    <label for="location">Location</label>
    <input id="location" name="location" required>
    <label for="notes">Notice / notes</label>
    <textarea id="notes" name="notes"></textarea>
    <button class="btn" type="submit">Schedule and notify resident</button>
  </form>
</section>
<section class="card">
  <h2>Hearing schedule and outcomes</h2>
  <?php if (!$hearings): ?>
    <p>No hearings are scheduled.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Incident / resident</th><th>Location and notice</th><th>Status / outcome</th></tr></thead>
      <tbody>
      <?php foreach ($hearings as $hearing): ?>
        <tr>
          <td><?= e($hearing['scheduled_at']) ?></td>
          <td><?= e($hearing['reference_no']) ?><br><?= e($hearing['full_name']) ?></td>
          <td><?= e($hearing['location']) ?><br><?= e($hearing['notes']) ?></td>
          <td>
            <form method="post" action="<?= site_url('staff/hearings/' . $hearing['id']) ?>">
              <?php csrf_field(); ?>
              <select name="status">
                <?php foreach (['Scheduled', 'Completed', 'Cancelled'] as $status): ?>
                  <option value="<?= e($status) ?>"<?= $hearing['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
              </select>
              <textarea name="outcome" placeholder="Outcome / notes"><?= e($hearing['outcome']) ?></textarea>
              <button class="btn" type="submit">Update hearing</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

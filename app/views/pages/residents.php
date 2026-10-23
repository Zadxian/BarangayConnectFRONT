<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <form method="get" action="<?= site_url('staff/residents') ?>" style="max-width:560px">
    <label for="q">Search residents</label>
    <input id="q" name="q" value="<?= e($query) ?>" placeholder="Name, email, or phone">
    <button class="btn" type="submit">Search</button>
  </form>
  <table>
    <thead><tr><th>Resident</th><th>Contact</th><th>Address</th><th>Account</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($residents as $resident): ?>
      <tr>
        <td><?= e($resident['full_name'] ?: '—') ?></td>
        <td><?= e($resident['email']) ?><br><?= e($resident['contact'] ?: '—') ?></td>
        <td><?= e($resident['address'] ?: '—') ?></td>
        <td><?= (int)$resident['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
        <td><?= e($resident['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

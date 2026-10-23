<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <h2>Create a staff or administrator account</h2>
  <form method="post" action="<?= site_url('admin/users') ?>" style="max-width:620px">
    <?php csrf_field(); ?>
    <label for="full_name">Full name</label><input id="full_name" name="full_name" required>
    <label for="email">Email</label><input id="email" name="email" type="email" required>
    <label for="role">Role</label>
    <select id="role" name="role" required><option value="moderator">Staff</option><option value="admin">Administrator</option></select>
    <label for="password">Temporary password (at least 12 characters)</label><input id="password" name="password" type="password" minlength="12" maxlength="72" required>
    <button class="btn" type="submit">Create account</button>
  </form>
</section>
<section class="card">
  <h2>Accounts</h2>
  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Manage</th></tr></thead>
    <tbody>
    <?php foreach ($users as $user): ?>
      <tr>
        <td><?= e($user['full_name'] ?: $user['username']) ?></td>
        <td><?= e($user['email']) ?></td>
        <td><?= e($user['role'] === 'moderator' ? 'Staff' : ucfirst($user['role'])) ?></td>
        <td><?= (int)$user['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
        <td><?= e($user['created_at']) ?></td>
        <td>
          <form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/status') ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="is_active" value="<?= (int)$user['is_active'] === 1 ? '0' : '1' ?>">
            <button class="btn alt" type="submit"><?= (int)$user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

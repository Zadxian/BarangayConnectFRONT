<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card" style="max-width:620px">
  <h1><?= e($title) ?></h1>
  <?php if ($admin_exists): ?>
    <p>Administrator setup is already complete. Use the administrator account to manage the system.</p>
  <?php elseif (!$setup_enabled): ?>
    <p>To protect the first administrator account, set a long, random <code>ADMIN_SETUP_TOKEN</code> in your local <code>.env</code> file, restart the server, then reload this page.</p>
  <?php else: ?>
    <p class="muted">This one-time setup is available only until the first administrator account is created. Do not share your setup token.</p>
    <form method="post" action="<?= site_url('setup') ?>">
      <?php csrf_field(); ?>
      <label for="setup_token">Setup token</label><input id="setup_token" name="setup_token" type="password" required>
      <label for="full_name">Full name</label><input id="full_name" name="full_name" required>
      <label for="email">Email</label><input id="email" name="email" type="email" required>
      <label for="password">Password (at least 12 characters)</label><input id="password" name="password" type="password" minlength="12" maxlength="72" required>
      <button class="btn" type="submit">Create administrator</button>
    </form>
  <?php endif; ?>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

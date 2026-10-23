<?php include APP_DIR . 'views/partials/header.php'; ?>
<div class="card">
  <h1>Barangay services, online.</h1>
  <p class="muted">Request clearances and certificates, track their status, and file incident reports without queuing at the barangay hall.</p>
  <a class="btn" href="<?= site_url('register') ?>">Create an account</a>
  <a class="btn alt" href="<?= site_url('login') ?>">Login</a>
</div>
<div class="grid">
  <a class="stat" href="<?= site_url('services') ?>"><b>📄</b>Request a document</a>
  <a class="stat" href="<?= site_url('resident/incidents/new') ?>"><b>🚨</b>File an incident report</a>
  <a class="stat" href="<?= site_url('track') ?>"><b>🔎</b>Track a request</a>
</div>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

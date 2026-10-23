<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1>Tracking result</h1>
  <?php if ($result): ?>
    <p><strong><?= e($result['record_type']) ?></strong> · Reference <?= e($result['reference_no']) ?></p>
    <p>Status: <span class="tag"><?= e($result['status']) ?></span></p>
    <p class="muted">Submitted <?= e($result['created_at']) ?></p>
  <?php else: ?>
    <p>No matching record was found. Check the reference number and try again.</p>
  <?php endif; ?>
  <a class="btn alt" href="<?= site_url('track') ?>">Track another</a>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

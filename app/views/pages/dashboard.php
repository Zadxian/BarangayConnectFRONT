<?php include APP_DIR . 'views/partials/header.php'; ?>
<div class="card"><h1><?= e($title) ?></h1><p class="muted">Overview of your BarangayConnect activity.</p></div>
<div class="grid">
  <?php foreach ($cards as $c): ?>
    <a class="stat" href="<?= site_url($c[2]) ?>"><b><?= e($c[1]) ?></b><?= e($c[0]) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!empty($notifications)): ?>
  <section class="card" style="margin-top:16px">
    <h2>Recent notifications</h2>
    <ul class="plain">
      <?php foreach ($notifications as $notification): ?>
        <li><?= e($notification['message']) ?> <span class="muted">(<?= e($notification['channel']) ?> · <?= e($notification['status']) ?> · <?= e($notification['created_at']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

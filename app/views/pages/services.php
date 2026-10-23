<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1>Barangay services</h1>
  <p class="muted">Available documents, fees, and the requirements to apply.</p>
</section>
<div class="grid">
  <?php foreach ($services as $service): ?>
    <article class="card">
      <h2><?= e($service['name']) ?></h2>
      <p><?= e($service['description']) ?></p>
      <p><strong>Fee:</strong> ₱<?= e(number_format((float)$service['fee'], 2)) ?></p>
      <p><strong>Requirements:</strong> <?= e($service['requirements']) ?></p>
      <a class="btn" href="<?= site_url('resident/requests/new') ?>">Apply</a>
    </article>
  <?php endforeach; ?>
</div>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

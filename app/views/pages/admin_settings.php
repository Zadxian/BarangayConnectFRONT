<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card">
  <h1><?= e($title) ?></h1>
  <h2>Barangay information</h2>
  <form method="post" action="<?= site_url('admin/settings') ?>" style="max-width:620px">
    <?php csrf_field(); ?>
    <label for="barangay_name">Barangay name</label><input id="barangay_name" name="barangay_name" value="<?= e($settings['barangay_name'] ?? '') ?>" required>
    <label for="office_hours">Office hours</label><input id="office_hours" name="office_hours" value="<?= e($settings['office_hours'] ?? '') ?>" required>
    <label for="office_contact">Office contact</label><input id="office_contact" name="office_contact" value="<?= e($settings['office_contact'] ?? '') ?>" required>
    <button class="btn" type="submit">Save information</button>
  </form>
</section>
<section class="card">
  <h2>Add a document service</h2>
  <form method="post" action="<?= site_url('admin/services') ?>" style="max-width:620px">
    <?php csrf_field(); ?>
    <label for="service_name">Service name</label><input id="service_name" name="name" required>
    <label for="service_description">Description</label><textarea id="service_description" name="description"></textarea>
    <label for="service_fee">Fee (PHP)</label><input id="service_fee" name="fee" type="number" min="0" step="0.01" required>
    <label for="service_requirements">Requirements</label><textarea id="service_requirements" name="requirements"></textarea>
    <button class="btn" type="submit">Add service</button>
  </form>
</section>
<section class="card">
  <h2>Document services</h2>
  <?php foreach ($services as $service): ?>
    <form method="post" action="<?= site_url('admin/services/' . $service['id']) ?>" style="border-top:1px solid var(--line);padding:16px 0">
      <?php csrf_field(); ?>
      <label>Service name</label><input name="name" value="<?= e($service['name']) ?>" required>
      <label>Description</label><textarea name="description"><?= e($service['description']) ?></textarea>
      <label>Fee (PHP)</label><input name="fee" type="number" min="0" step="0.01" value="<?= e($service['fee']) ?>" required>
      <label>Requirements</label><textarea name="requirements"><?= e($service['requirements']) ?></textarea>
      <label>Availability</label>
      <select name="is_active"><option value="1"<?= (int)$service['is_active'] === 1 ? ' selected' : '' ?>>Available</option><option value="0"<?= (int)$service['is_active'] === 0 ? ' selected' : '' ?>>Unavailable</option></select>
      <button class="btn" type="submit">Save service</button>
    </form>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>Add an incident type</h2>
  <form method="post" action="<?= site_url('admin/incident-types') ?>" style="max-width:620px">
    <?php csrf_field(); ?>
    <label for="incident_name">Incident type</label><input id="incident_name" name="name" required>
    <button class="btn" type="submit">Add incident type</button>
  </form>
  <ul class="plain"><?php foreach ($incident_types as $type): ?><li><?= e($type['name']) ?><?= (int)$type['is_active'] === 1 ? '' : ' (inactive)' ?></li><?php endforeach; ?></ul>
</section>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

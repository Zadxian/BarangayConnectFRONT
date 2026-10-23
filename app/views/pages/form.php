<?php include APP_DIR . 'views/partials/header.php'; ?>
<div class="card" style="max-width:560px">
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= e($intro ?? '') ?></p>
  <form method="post" action="<?= site_url($action ?? '') ?>"<?= in_array('file', array_column($fields, 2), true) ? ' enctype="multipart/form-data"' : '' ?>>
    <?php csrf_field(); ?>
    <?php foreach ($fields as $f): [$label, $name, $type] = $f; ?>
      <label for="<?= e($name) ?>"><?= e($label) ?></label>
      <?php if ($type === 'select'): ?>
        <select id="<?= e($name) ?>" name="<?= e($name) ?>"<?= ($f[4] ?? true) ? ' required' : '' ?>>
          <option value="">Choose one</option>
          <?php foreach (($f[3] ?? []) as $key => $opt): ?>
            <?php
              $value = is_array($opt) ? $opt['value'] : (is_int($key) ? $opt : $key);
              $label = is_array($opt) ? $opt['label'] : $opt;
            ?>
            <option value="<?= e($value) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($type === 'textarea'): ?>
        <textarea id="<?= e($name) ?>" name="<?= e($name) ?>"<?= ($f[4] ?? true) ? ' required' : '' ?>><?= e($values[$name] ?? '') ?></textarea>
      <?php elseif ($type === 'checkbox'): ?>
        <input id="<?= e($name) ?>" name="<?= e($name) ?>" type="checkbox" value="1"<?= !empty($values[$name]) ? ' checked' : '' ?> style="width:auto"<?= ($f[4] ?? true) ? ' required' : '' ?>>
      <?php else: ?>
        <input id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"<?= $type === 'file' ? ' accept=".pdf,.jpg,.jpeg,.png"' : '' ?><?= ($f[4] ?? true) ? ' required' : '' ?><?= $type === 'file' ? '' : ' value="' . e($values[$name] ?? '') . '"' ?>>
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn" type="submit"><?= e($button ?? 'Submit') ?></button>
  </form>
  <?php if (!empty($google_oauth_enabled)): ?>
    <a class="btn alt" href="<?= site_url('auth/google') ?>">Continue with Google</a>
  <?php endif; ?>
  <?php if (!empty($footer)): ?>
    <p class="muted" style="margin-top:16px"><?= e($footer[0]) ?> <a href="<?= site_url($footer[2]) ?>"><?= e($footer[1]) ?></a></p>
  <?php endif; ?>
</div>
<?php include APP_DIR . 'views/partials/footer.php'; ?>

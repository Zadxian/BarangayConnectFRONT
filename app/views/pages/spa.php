<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123c34">
    <title>BarangayConnect</title>
    <?php foreach (($entry['css'] ?? []) as $stylesheet): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(site_url('build/' . $stylesheet), ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</head>
<body>
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars(site_url('build/' . $entry['file']), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>

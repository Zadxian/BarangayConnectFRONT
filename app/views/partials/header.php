<?php
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$path    = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$segment = explode('/', $path)[0] ?? '';
$area    = in_array($segment, ['resident', 'staff', 'admin'], true) ? $segment : 'public';

$menus = [
    'public' => [
        ['Home', ''], ['Services', 'services'], ['About', 'about'], ['Track', 'track'],
    ],
    'resident' => [
        ['Dashboard', 'resident'], ['My Requests', 'resident/requests'], ['New Request', 'resident/requests/new'],
        ['My Incidents', 'resident/incidents'], ['File Incident', 'resident/incidents/new'], ['Profile', 'resident/profile'],
    ],
    'staff' => [
        ['Dashboard', 'staff'], ['Requests', 'staff/requests'], ['Incident Cases', 'staff/incidents'],
        ['Hearings', 'staff/hearings'], ['Residents', 'staff/residents'], ['Reports', 'staff/reports'],
    ],
    'admin' => [
        ['Users', 'admin/users'], ['Settings', 'admin/settings'],
    ],
];
$is_active = function ($uri) use ($path) { return trim($uri, '/') === $path; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Page') ?> | <?= e($app_name ?? 'BarangayConnect') ?></title>
<style>
  :root { --brand:#0f766e; --brand-dark:#115e59; --bg:#f1f5f4; --ink:#1f2937; --muted:#6b7280; --line:#d9e2e0; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Segoe UI, Arial, sans-serif; background:var(--bg); color:var(--ink); line-height:1.5; }
  a { color:var(--brand); text-decoration:none; }
  .topbar { background:var(--brand); color:#fff; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; padding:12px 24px; }
  .topbar .brand { font-weight:700; font-size:1.15rem; color:#fff; }
  .areas a { color:#d1fae5; margin-left:16px; font-size:.92rem; }
  .areas a.on { color:#fff; font-weight:700; border-bottom:2px solid #fff; }
  .layout { display:flex; max-width:1100px; margin:24px auto; gap:24px; padding:0 16px; }
  .side { width:210px; flex-shrink:0; background:#fff; border:1px solid var(--line); border-radius:10px; padding:10px; height:fit-content; }
  .side a { display:block; padding:9px 12px; border-radius:6px; color:var(--ink); font-size:.93rem; }
  .side a:hover { background:#ecfdf5; }
  .side a.active { background:var(--brand); color:#fff; }
  main { flex:1; min-width:0; }
  .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:24px; margin-bottom:16px; }
  h1 { font-size:1.5rem; margin-bottom:6px; } h2 { font-size:1.1rem; margin-bottom:8px; }
  .muted { color:var(--muted); }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; }
  .stat { display:block; background:#fff; border:1px solid var(--line); border-radius:10px; padding:18px; color:var(--ink); }
  .stat b { display:block; font-size:1.8rem; color:var(--brand); }
  label { display:block; font-weight:600; margin:14px 0 4px; font-size:.92rem; }
  input, select, textarea { width:100%; padding:10px; border:1px solid #c7d2d0; border-radius:6px; font:inherit; }
  textarea { min-height:100px; }
  .btn { display:inline-block; margin-top:18px; background:var(--brand); color:#fff; border:0; padding:10px 20px; border-radius:6px; font:inherit; cursor:pointer; }
  .btn:hover { background:var(--brand-dark); }
  .btn.alt { background:#fff; color:var(--brand); border:1px solid var(--brand); margin-left:8px; }
  .topbar form { display:inline; margin-left:12px; }
  .topbar button { background:transparent; color:#fff; border:0; font:inherit; cursor:pointer; }
  .notice { padding:12px 16px; border-radius:8px; margin-bottom:16px; background:#ecfdf5; color:#065f46; }
  .notice.error { background:#fef2f2; color:#991b1b; }
  table { width:100%; border-collapse:collapse; margin-top:12px; background:#fff; }
  th, td { padding:10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
  th { background:#f8faf9; }
  ul.plain { margin:10px 0 0 20px; }
  .tag { display:inline-block; background:#fef3c7; color:#92400e; font-size:.78rem; padding:2px 10px; border-radius:999px; margin-bottom:10px; }
  footer { text-align:center; color:var(--muted); font-size:.85rem; padding:24px; }
  @media (max-width:760px) { .layout { flex-direction:column; } .side { width:100%; display:flex; flex-wrap:wrap; } }
</style>
</head>
<body>
<div class="topbar">
  <a class="brand" href="<?= site_url('') ?>">🏘️ <?= e($app_name ?? 'BarangayConnect') ?></a>
  <div class="areas">
    <a href="<?= site_url('') ?>" class="<?= $area === 'public' ? 'on' : '' ?>">Public</a>
    <?php if (empty($current_user)): ?>
      <a href="<?= site_url('login') ?>">Login</a>
      <a href="<?= site_url('register') ?>">Register</a>
    <?php else: ?>
      <?php if ($current_user['role'] === 'user'): ?>
        <a href="<?= site_url('resident') ?>" class="<?= $area === 'resident' ? 'on' : '' ?>">Resident</a>
      <?php endif; ?>
      <?php if (in_array($current_user['role'], ['moderator', 'admin'], true)): ?>
        <a href="<?= site_url('staff') ?>" class="<?= $area === 'staff' ? 'on' : '' ?>">Staff</a>
      <?php endif; ?>
      <?php if ($current_user['role'] === 'admin'): ?>
        <a href="<?= site_url('admin/users') ?>" class="<?= $area === 'admin' ? 'on' : '' ?>">Admin</a>
      <?php endif; ?>
      <span><?= e($current_user['full_name'] ?? $current_user['email']) ?></span>
      <form method="post" action="<?= site_url('logout') ?>"><?php csrf_field(); ?><button type="submit">Logout</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="layout">
  <?php if ($area !== 'public'): ?>
  <nav class="side">
    <?php foreach ($menus[$area] as $item): ?>
      <a href="<?= site_url($item[1]) ?>" class="<?= $is_active($item[1]) ? 'active' : '' ?>"><?= e($item[0]) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <main>
  <?php if (!empty($flash)): ?>
    <div class="notice <?= e($flash['type'] === 'error' ? 'error' : '') ?>"><?= e($flash['message']) ?></div>
  <?php endif; ?>

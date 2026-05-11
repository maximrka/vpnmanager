<div class="topbar">
  <div class="logo-slot">
    <span class="logo-badge"><?= htmlspecialchars(substr($logoText, 0, 2)) ?></span>
    <div>
      <div class="logo-caption"><?= htmlspecialchars($appName) ?></div>
      <div class="logo-sub">Place your logo here</div>
    </div>
  </div>
  <div class="nav">
    <a href="/">Dashboard</a>
    <a href="/?r=profile">Profile</a>
    <a href="/?r=logout">Logout (<?= htmlspecialchars((string)$username) ?>)</a>
  </div>
</div>

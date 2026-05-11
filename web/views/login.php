<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> - Login</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="auth-wrap">
    <div class="card auth-card">
      <div class="logo-slot" style="margin-bottom: 14px;">
        <span class="logo-badge"><?= htmlspecialchars(substr($logoText, 0, 2)) ?></span>
        <div>
          <div class="logo-caption"><?= htmlspecialchars($appName) ?></div>
          <div class="logo-sub">Secure VPN Administration</div>
        </div>
      </div>

      <?php if (($mode ?? 'password') === 'totp'): ?>
        <h2>Two-Factor Verification</h2>
        <p class="help">User: <?= htmlspecialchars((string)($pendingUsername ?? '')) ?></p>
        <?php if (!empty($error)): ?><div class="flash err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="/?r=login-2fa">
          <div style="margin-bottom:14px;">
            <label>Authenticator code or backup code</label>
            <input type="text" name="code" required>
          </div>
          <button type="submit">Verify</button>
        </form>
      <?php else: ?>
        <h2>Sign in</h2>
        <?php if (!empty($error)): ?><div class="flash err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="/?r=login">
          <div style="margin-bottom:10px;">
            <label>Username</label>
            <input type="text" name="username" required>
          </div>
          <div style="margin-bottom:14px;">
            <label>Password</label>
            <input type="password" name="password" required>
          </div>
          <button type="submit">Login</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

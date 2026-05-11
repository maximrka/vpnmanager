<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> - Profile</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="layout">
    <?php include __DIR__ . '/_layout_top.php'; ?>
    <div class="main">
      <div class="card">
        <h3>Profile</h3>
        <p>User: <strong><?= htmlspecialchars($username) ?></strong></p>
        <p>2FA status: <strong><?= $enabled ? 'Enabled' : 'Disabled' ?></strong></p>
        <?php if (!empty($_SESSION['flash_ok'])): ?><div class="flash ok"><?= htmlspecialchars($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div><?php endif; ?>
        <?php if (!empty($_SESSION['flash_err'])): ?><div class="flash err"><?= htmlspecialchars($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div><?php endif; ?>
      </div>

      <?php if (!$enabled): ?>
      <div class="card">
        <h3>Enable Google Authenticator (TOTP)</h3>
        <p>1. Scan QR in Google Authenticator</p>
        <p>2. Enter current 6-digit code to confirm setup</p>
        <?php if (!empty($qrData)): ?>
          <img src="<?= htmlspecialchars((string)$qrData) ?>" alt="TOTP QR" style="max-width:220px;border:1px solid #dce4ef;border-radius:8px;padding:8px;background:#fff;">
        <?php endif; ?>
        <p><strong>Manual key:</strong> <code><?= htmlspecialchars($secret) ?></code></p>
        <form method="post" action="/?r=profile-2fa-enable">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <label>Current TOTP code</label>
          <input type="text" name="totp_code" placeholder="123456" required>
          <div style="margin-top:10px;"><button type="submit">Enable 2FA</button></div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!empty($backupCodes)): ?>
      <div class="card">
        <h3>Backup Codes</h3>
        <p>Save these codes now. Each code can be used once.</p>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
          <?php foreach ($backupCodes as $bc): ?>
            <div class="card" style="padding:10px;"><code><?= htmlspecialchars((string)$bc) ?></code></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="footer-note">
      Development: <a href="https://cityhost.ua" target="_blank" rel="noopener noreferrer">cityhost.ua</a> and
      <a href="https://vps-up.online" target="_blank" rel="noopener noreferrer">vps-up.online</a>
    </div>
  </div>
</body>
</html>

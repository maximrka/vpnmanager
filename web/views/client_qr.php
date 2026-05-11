<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> - QR</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="layout">
    <?php include __DIR__ . '/_layout_top.php'; ?>
    <div class="main">
      <div class="card">
        <h3>Client QR</h3>
        <p>Client: <strong><?= htmlspecialchars($clientName) ?></strong></p>
        <img src="<?= htmlspecialchars($qrData) ?>" alt="Client QR" style="max-width:360px;border:1px solid #dce4ef;border-radius:10px;padding:8px;background:#fff;">
      </div>
    </div>
    <div class="footer-note">
      Development: <a href="https://cityhost.ua" target="_blank" rel="noopener noreferrer">cityhost.ua</a> and
      <a href="https://vps-up.online" target="_blank" rel="noopener noreferrer">vps-up.online</a>
    </div>
  </div>
</body>
</html>

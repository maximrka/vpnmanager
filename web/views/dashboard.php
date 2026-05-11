<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> - Dashboard</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="layout">
    <?php include __DIR__ . '/_layout_top.php'; ?>

    <div class="main">
      <div class="grid">
        <div class="card">
          <h3>Backend</h3>
          <p>Selected backend: <strong><?= htmlspecialchars(strtoupper($backend)) ?></strong></p>
          <p>Service status: <span class="badge active"><?= htmlspecialchars($status) ?></span></p>
          <form method="post" action="/?r=service-action" style="display:flex; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" name="action" value="start" class="alt">Start</button>
            <button type="submit" name="action" value="stop" class="alt">Stop</button>
            <button type="submit" name="action" value="restart">Restart</button>
          </form>
        </div>
        <div class="card">
          <h3>Create Client</h3>
          <?php if (!empty($_SESSION['flash_ok'])): ?><div class="flash ok"><?= htmlspecialchars($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div><?php endif; ?>
          <?php if (!empty($_SESSION['flash_err'])): ?><div class="flash err"><?= htmlspecialchars($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div><?php endif; ?>
          <form method="post" action="/?r=clients-create">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <label>Client Name</label>
            <input type="text" name="client_name" placeholder="iphone-oleg" required>
            <div style="margin-top: 10px;"><button type="submit">Create</button></div>
          </form>
        </div>
      </div>

      <div class="card">
        <h3>Clients</h3>
        <table class="table">
          <thead>
            <tr><th>ID</th><th>Name</th><th>Internal IP</th><th>Status</th><th>Created</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $c): ?>
              <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= htmlspecialchars($c['client_name']) ?></td>
                <td><?= htmlspecialchars((string)($c['assigned_ip'] ?? '')) ?></td>
                <td><span class="badge <?= $c['status'] === 'active' ? 'active' : 'disabled' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><?= htmlspecialchars($c['created_at']) ?></td>
                <td>
                  <a href="/?r=clients-download&id=<?= (int)$c['id'] ?>">Download</a>
                  <?php if ($backend === 'wireguard'): ?>
                    | <a href="/?qr_id=<?= (int)$c['id'] ?>">QR</a>
                  <?php endif; ?>
                  | <form method="post" action="/?r=clients-toggle" style="display:inline-block; margin:0 4px;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="to" value="<?= $c['status'] === 'active' ? 'disabled' : 'active' ?>">
                    <button type="submit" class="alt"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="post" action="/?r=clients-delete" style="display:inline-block;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit">Revoke</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($selectedQr)): ?>
      <div class="card">
        <h3>Client QR</h3>
        <p>Client: <strong><?= htmlspecialchars((string)$selectedQrName) ?></strong></p>
        <img src="<?= htmlspecialchars((string)$selectedQr) ?>" alt="Client QR" style="max-width:360px;border:1px solid #dce4ef;border-radius:10px;padding:8px;background:#fff;">
        <p class="help">Some VPN apps may still ask tunnel name after scanning. Recommended name: <strong><?= htmlspecialchars((string)$selectedQrName) ?></strong></p>
      </div>
      <?php endif; ?>

      <div class="card">
        <h3>Audit (last 10)</h3>
        <table class="table">
          <thead><tr><th>Time</th><th>Action</th><th>Result</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($audit as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
                <td><?= htmlspecialchars($row['action']) ?></td>
                <td><?= htmlspecialchars($row['result']) ?></td>
                <td><?= htmlspecialchars((string)$row['ip']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="footer-note">
      Development: <a href="https://cityhost.ua" target="_blank" rel="noopener noreferrer">cityhost.ua</a> and
      <a href="https://vps-up.online" target="_blank" rel="noopener noreferrer">vps-up.online</a>
    </div>
  </div>
</body>
</html>

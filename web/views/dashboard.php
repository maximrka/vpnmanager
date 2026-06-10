<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> - Dashboard</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <?php
    $formatBytes = static function ($bytes): string {
        $value = (int)$bytes;
        if ($value <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($value, 1024));
        $power = max(0, min($power, count($units) - 1));
        $scaled = $value / (1024 ** $power);
        $precision = $power === 0 ? 0 : 2;
        return number_format($scaled, $precision, '.', ' ') . ' ' . $units[$power];
    };
    $formatMultiValue = static function (?string $value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }
        return str_replace(',', ', ', $value);
    };
    $formatDate = static function (?string $value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }
        return str_replace(' ', "\n", $value);
    };
  ?>
  <div class="layout">
    <?php include __DIR__ . '/_layout_top.php'; ?>

    <div class="main">
      <div class="grid">
        <div class="card">
          <h3>Backend</h3>
          <p>Selected backend: <strong><?= htmlspecialchars(strtoupper($backend)) ?></strong></p>
          <p>Service status: <span class="badge active" data-live-service-status><?= htmlspecialchars($status) ?></span></p>
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
        <div class="client-list" data-live-clients>
          <?php foreach ($clients as $c): ?>
            <article class="client-card" data-client-id="<?= (int)$c['id'] ?>">
              <div class="client-card__header">
                <div class="client-card__identity">
                  <div class="client-card__title-row">
                    <span class="client-card__id">#<?= (int)$c['id'] ?></span>
                    <h4 class="client-card__name"><?= htmlspecialchars($c['client_name']) ?></h4>
                    <div class="client-card__quick-links">
                      <a href="/?r=clients-download&id=<?= (int)$c['id'] ?>" class="icon-link" title="Download config" aria-label="Download config">
                        <span aria-hidden="true">↓</span>
                      </a>
                      <?php if ($backend === 'wireguard'): ?>
                        <a href="/?r=clients-qr&id=<?= (int)$c['id'] ?>" class="icon-link" title="Show QR" aria-label="Show QR" data-qr-link data-client-name="<?= htmlspecialchars($c['client_name']) ?>">
                          <span aria-hidden="true">⌁</span>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="client-card__badges">
                    <span class="badge <?= $c['status'] === 'active' ? 'active' : 'disabled' ?>" data-field="status"><?= htmlspecialchars($c['status']) ?></span>
                    <span class="badge badge-session badge-session--<?= htmlspecialchars((string)($c['session_state'] ?? 'offline')) ?>" data-field="session_state"><?= htmlspecialchars((string)($c['session_state'] ?? 'offline')) ?></span>
                  </div>
                </div>
                <div class="client-card__actions">
                  <div class="action-buttons">
                    <form method="post" action="/?r=clients-toggle">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="to" value="<?= $c['status'] === 'active' ? 'disabled' : 'active' ?>" data-toggle-target>
                      <button type="submit" class="alt" data-toggle-button><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" action="/?r=clients-delete">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button type="submit">Revoke</button>
                    </form>
                  </div>
                </div>
              </div>

              <div class="client-card__stats">
                <div class="client-stat">
                  <span class="client-stat__label">Internal IP</span>
                  <span class="client-stat__value client-stat__value--break" data-field="assigned_ip"><?= nl2br(htmlspecialchars(str_replace(',', ",\n", (string)($c['assigned_ip'] ?? '')))) ?></span>
                </div>
                <div class="client-stat">
                  <span class="client-stat__label">Last Seen</span>
                  <span class="client-stat__value client-stat__value--mono" data-field="last_seen"><?= nl2br(htmlspecialchars($formatDate((string)($c['last_seen'] ?? '')))) ?></span>
                </div>
                <div class="client-stat">
                  <span class="client-stat__label">Traffic</span>
                  <span class="client-stat__value">
                    <span class="traffic-inline">
                      <span>
                        <small>Down</small>
                        <strong data-field="rx_bytes" data-bytes="<?= (int)($c['rx_bytes'] ?? 0) ?>"><?= htmlspecialchars($formatBytes($c['rx_bytes'] ?? 0)) ?></strong>
                        <em data-field="rx_speed">0 B/s</em>
                      </span>
                      <span>
                        <small>Up</small>
                        <strong data-field="tx_bytes" data-bytes="<?= (int)($c['tx_bytes'] ?? 0) ?>"><?= htmlspecialchars($formatBytes($c['tx_bytes'] ?? 0)) ?></strong>
                        <em data-field="tx_speed">0 B/s</em>
                      </span>
                    </span>
                  </span>
                </div>
                <div class="client-stat">
                  <span class="client-stat__label">Endpoint</span>
                  <span class="client-stat__value client-stat__value--break" data-field="endpoint"><?= htmlspecialchars($formatMultiValue((string)($c['endpoint'] ?? ''))) ?></span>
                </div>
                <div class="client-stat">
                  <span class="client-stat__label">Created</span>
                  <span class="client-stat__value client-stat__value--mono"><?= nl2br(htmlspecialchars($formatDate((string)($c['created_at'] ?? '')))) ?></span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>

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
  <div class="modal" data-qr-modal hidden>
    <div class="modal__backdrop" data-qr-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
      <button type="button" class="modal__close" data-qr-close aria-label="Close">x</button>
      <h3 id="qr-modal-title">Client QR</h3>
      <p>Client: <strong data-qr-client-name>-</strong></p>
      <div class="modal__qr-wrap">
        <img src="" alt="Client QR" data-qr-image>
      </div>
      <p class="help">Scan QR in your WireGuard app. Recommended tunnel name: <strong data-qr-client-name-copy>-</strong></p>
    </div>
  </div>
  <script>
    (() => {
      const clientsRoot = document.querySelector('[data-live-clients]');
      if (!clientsRoot) return;

      const serviceBadge = document.querySelector('[data-live-service-status]');
      const qrModal = document.querySelector('[data-qr-modal]');
      const qrImage = qrModal?.querySelector('[data-qr-image]');
      const qrName = qrModal?.querySelector('[data-qr-client-name]');
      const qrNameCopy = qrModal?.querySelector('[data-qr-client-name-copy]');
      const samples = new Map();

      const formatBytes = (value) => {
        const bytes = Number(value || 0);
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const power = Math.max(0, Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1));
        const scaled = bytes / (1024 ** power);
        const precision = power === 0 ? 0 : 2;
        return `${scaled.toFixed(precision)} ${units[power]}`;
      };

      const formatDate = (value) => {
        if (!value) return '-';
        return String(value).replace(' ', '\n');
      };

      const formatMultiValue = (value) => {
        if (!value) return '-';
        return String(value).split(',').join(', ');
      };

      const formatIp = (value) => {
        if (!value) return '-';
        return String(value).split(',').join(',\n');
      };

      const setSpeed = (element, bytesPerSecond) => {
        if (!element) return;
        const value = Number(bytesPerSecond || 0);
        element.textContent = `${formatBytes(value)}/s`;
      };

      const applyBadgeState = (badge, type, value) => {
        badge.textContent = value;
        if (type === 'status') {
          badge.classList.toggle('active', value === 'active');
          badge.classList.toggle('disabled', value !== 'active');
          return;
        }
        badge.classList.remove('badge-session--online', 'badge-session--seen', 'badge-session--idle', 'badge-session--offline');
        badge.classList.add(`badge-session--${value || 'offline'}`);
      };

      const updateClient = (card, client, timestamp) => {
        const statusBadge = card.querySelector('[data-field="status"]');
        const sessionBadge = card.querySelector('[data-field="session_state"]');
        const toggleTarget = card.querySelector('[data-toggle-target]');
        const toggleButton = card.querySelector('[data-toggle-button]');
        const assignedIp = card.querySelector('[data-field="assigned_ip"]');
        const lastSeen = card.querySelector('[data-field="last_seen"]');
        const endpoint = card.querySelector('[data-field="endpoint"]');
        const rxBytes = card.querySelector('[data-field="rx_bytes"]');
        const txBytes = card.querySelector('[data-field="tx_bytes"]');
        const rxSpeed = card.querySelector('[data-field="rx_speed"]');
        const txSpeed = card.querySelector('[data-field="tx_speed"]');

        if (statusBadge) applyBadgeState(statusBadge, 'status', client.status || 'disabled');
        if (sessionBadge) applyBadgeState(sessionBadge, 'session', client.session_state || 'offline');
        if (toggleTarget) toggleTarget.value = client.status === 'active' ? 'disabled' : 'active';
        if (toggleButton) toggleButton.textContent = client.status === 'active' ? 'Disable' : 'Enable';
        if (assignedIp) assignedIp.innerHTML = formatIp(client.assigned_ip || '-').replaceAll('\n', '<br>');
        if (lastSeen) lastSeen.innerHTML = formatDate(client.last_seen || '').replaceAll('\n', '<br>');
        if (endpoint) endpoint.textContent = formatMultiValue(client.endpoint || '');
        if (rxBytes) {
          rxBytes.dataset.bytes = String(client.rx_bytes || 0);
          rxBytes.textContent = formatBytes(client.rx_bytes || 0);
        }
        if (txBytes) {
          txBytes.dataset.bytes = String(client.tx_bytes || 0);
          txBytes.textContent = formatBytes(client.tx_bytes || 0);
        }

        const clientId = String(client.id || card.getAttribute('data-client-id') || '');
        const previous = samples.get(clientId);
        const currentRx = Number(client.rx_bytes || 0);
        const currentTx = Number(client.tx_bytes || 0);
        if (previous && timestamp > previous.time) {
          const seconds = Math.max(timestamp - previous.time, 1);
          setSpeed(rxSpeed, Math.max(0, currentRx - previous.rx) / seconds);
          setSpeed(txSpeed, Math.max(0, currentTx - previous.tx) / seconds);
        } else {
          setSpeed(rxSpeed, 0);
          setSpeed(txSpeed, 0);
        }
        samples.set(clientId, { rx: currentRx, tx: currentTx, time: timestamp });
      };

      const openQrModal = (name, image) => {
        if (!qrModal || !qrImage || !qrName || !qrNameCopy) return;
        qrName.textContent = name;
        qrNameCopy.textContent = name;
        qrImage.src = image;
        qrModal.hidden = false;
        document.body.classList.add('modal-open');
      };

      const closeQrModal = () => {
        if (!qrModal || !qrImage) return;
        qrModal.hidden = true;
        qrImage.src = '';
        document.body.classList.remove('modal-open');
      };

      const poll = async () => {
        try {
          const response = await fetch('/?r=clients-live', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
          if (!response.ok) return;
          const data = await response.json();
          const timestamp = Number(data.server_time || Math.floor(Date.now() / 1000));

          if (serviceBadge && typeof data.status === 'string') {
            serviceBadge.textContent = data.status;
          }

          const clients = Array.isArray(data.clients) ? data.clients : [];
          const map = new Map(clients.map((client) => [String(client.id), client]));
          clientsRoot.querySelectorAll('[data-client-id]').forEach((card) => {
            const client = map.get(card.getAttribute('data-client-id') || '');
            if (client) {
              updateClient(card, client, timestamp);
            }
          });
        } catch (error) {
        }
      };

      clientsRoot.querySelectorAll('[data-client-id]').forEach((card) => {
        const clientId = String(card.getAttribute('data-client-id') || '');
        const rx = Number(card.querySelector('[data-field="rx_bytes"]')?.dataset.bytes || 0);
        const tx = Number(card.querySelector('[data-field="tx_bytes"]')?.dataset.bytes || 0);
        samples.set(clientId, { rx, tx, time: Math.floor(Date.now() / 1000) });
      });

      clientsRoot.addEventListener('click', async (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-qr-link]') : null;
        if (!link) return;
        event.preventDefault();
        const href = link.getAttribute('href');
        if (!href) return;
        try {
          const response = await fetch(href, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
          if (!response.ok) return;
          const data = await response.json();
          if (data && data.qr_data) {
            openQrModal(String(data.name || link.getAttribute('data-client-name') || 'client'), String(data.qr_data));
          }
        } catch (error) {
        }
      });

      qrModal?.querySelectorAll('[data-qr-close]').forEach((element) => {
        element.addEventListener('click', closeQrModal);
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeQrModal();
        }
      });

      poll();
      window.setInterval(poll, 2000);
    })();
  </script>
</body>
</html>

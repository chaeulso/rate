<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$csrf = generate_csrf_token();
$msg = '';
$msgType = 'ok';

$configPath = __DIR__ . '/bot-promosi/config.js';
$authDir = __DIR__ . '/bot-promosi/auth_info';
$serviceName = 'bot2';

function getBotConfig($path) {
    if (!file_exists($path)) return ['BOT_NUMBER' => '', 'OWNER_NUMBER' => ''];
    $content = file_get_contents($path);
    $bot = '';
    $owner = '';
    if (preg_match("/BOT_NUMBER:\s*'([^']*)'/", $content, $m)) $bot = $m[1];
    if (preg_match("/OWNER_NUMBER:\s*'([^']*)'/", $content, $m)) $owner = $m[1];
    return ['BOT_NUMBER' => $bot, 'OWNER_NUMBER' => $owner];
}

function getServiceStatus($service) {
    $out = shell_exec("sudo /usr/bin/systemctl is-active " . escapeshellarg($service) . " 2>&1");
    return trim($out);
}

$action = $_POST['action'] ?? '';
if ($action && (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token']))) {
    $action = ''; $msg = 'CSRF tidak valid.'; $msgType = 'err';
}

if ($action === 'update_numbers') {
    $newBot = preg_replace('/[^0-9]/', '', $_POST['bot_number'] ?? '');
    $newOwner = preg_replace('/[^0-9]/', '', $_POST['owner_number'] ?? '');

    if ($newBot === '' || $newOwner === '') {
        $msg = 'Nomor tidak boleh kosong.'; $msgType = 'err';
    } else {
        $content = file_get_contents($configPath);
        $content = preg_replace("/BOT_NUMBER:\s*'[^']*'/", "BOT_NUMBER: '{$newBot}'", $content);
        $content = preg_replace("/OWNER_NUMBER:\s*'[^']*'/", "OWNER_NUMBER: '{$newOwner}'", $content);
        file_put_contents($configPath, $content);
        $msg = 'Nomor berhasil diupdate. Restart bot untuk menerapkan perubahan.';
    }
}

if ($action === 'service_control') {
    $ctl = $_POST['ctl'] ?? '';
    if (in_array($ctl, ['start', 'stop', 'restart'], true)) {
        $cmdOut = shell_exec("sudo /usr/bin/systemctl {$ctl} " . escapeshellarg($serviceName) . " 2>&1");
        sleep(1);
        // Verifikasi ulang, jangan cuma asumsi berhasil
        $verifyStatus = trim(shell_exec("sudo /usr/bin/systemctl is-active " . escapeshellarg($serviceName) . " 2>&1"));
        if ($ctl === 'stop') {
            $msg = ($verifyStatus !== 'active') ? "Service berhasil di-stop." : "Gagal stop service: " . trim($cmdOut);
            $msgType = ($verifyStatus !== 'active') ? 'ok' : 'err';
        } else {
            $msg = ($verifyStatus === 'active') ? "Service berhasil di-{$ctl}." : "Gagal {$ctl} service: " . trim($cmdOut);
            $msgType = ($verifyStatus === 'active') ? 'ok' : 'err';
        }
    }
}

if ($action === 'pairing_ulang') {
    shell_exec("sudo /usr/bin/systemctl stop " . escapeshellarg($serviceName) . " 2>&1");
    sleep(1);
    shell_exec("sudo /bin/rm -rf " . escapeshellarg($authDir));
    shell_exec("sudo /usr/bin/systemctl start " . escapeshellarg($serviceName) . " 2>&1");
    $msg = 'Auth dihapus, bot direstart. Tunggu beberapa detik, kode pairing akan muncul otomatis di bawah.';
}

$botConfig = getBotConfig($configPath);
$serviceStatus = getServiceStatus($serviceName);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bot Promosi — Admin NexHost</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
body { background: #0f0f0f; color: #eaeaea; padding: 16px; }
.container { max-width: 720px; margin: 0 auto; }
.topbar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.topbar a { color: #888; text-decoration: none; font-size: 13px; }
h1 { font-size: 20px; }
.sub { color: #888; font-size: 13px; margin-bottom: 20px; }
.msg { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
.msg.err { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.msg.ok { background: #142a1a; color: #6bff8f; border: 1px solid #1f4a2a; }
.card { background: #161616; border: 1px solid #262626; border-radius: 8px; padding: 18px; margin-bottom: 14px; }
.card h2 { font-size: 15px; margin-bottom: 12px; }
.status-badge { font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 4px; display: inline-block; }
.status-badge.active { background: #142a1a; color: #6bff8f; }
.status-badge.inactive { background: #2a1414; color: #ff6b6b; }
label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; margin-top: 10px; }
input[type=text] { width: 100%; background: #0f0f0f; border: 1px solid #262626; color: #eaeaea; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
.btn { border: none; padding: 10px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; margin-top: 10px; margin-right: 8px; }
.btn-primary { background: #6bff8f; color: #0f0f0f; }
.btn-primary:hover { background: #8fffa8; }
.btn-start { background: #142a1a; color: #6bff8f; border: 1px solid #1f4a2a; }
.btn-stop { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.btn-restart { background: #2a2414; color: #ffd166; border: 1px solid #4a3f1f; }
.btn-danger { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.log-box { background: #0a0a0a; border: 1px solid #262626; border-radius: 6px; padding: 12px; font-family: 'Courier New', monospace; font-size: 11px; line-height: 1.6; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; color: #a1a1aa; }
.log-updated { font-size: 11px; color: #666; margin-top: 8px; }
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <a href="/admin.php">&#8592; Kembali</a>
  </div>
  <h1>&#129302; Bot Promosi</h1>
  <div class="sub">Kelola bot WhatsApp untuk promosi & auto join grup</div>

  <?php if ($msg): ?>
    <div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Status Service</h2>
    <span class="status-badge <?= $serviceStatus === 'active' ? 'active' : 'inactive' ?>" id="serviceStatusBadge">
      <?= $serviceStatus === 'active' ? 'ACTIVE' : strtoupper($serviceStatus) ?>
    </span>

    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="service_control">
      <input type="hidden" name="ctl" value="start">
      <button type="submit" class="btn btn-start">&#9654; Start</button>
    </form>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="service_control">
      <input type="hidden" name="ctl" value="restart">
      <button type="submit" class="btn btn-restart">&#8635; Restart</button>
    </form>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="service_control">
      <input type="hidden" name="ctl" value="stop">
      <button type="submit" class="btn btn-stop">&#9632; Stop</button>
    </form>
  </div>

  <div class="card">
    <h2>Nomor Bot & Owner</h2>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="update_numbers">
      <label>Nomor Bot (yang login WhatsApp)</label>
      <input type="text" name="bot_number" value="<?= htmlspecialchars($botConfig['BOT_NUMBER']) ?>" placeholder="628xxxxxxxxxx">
      <label>Nomor Owner (yang bisa pakai command admin)</label>
      <input type="text" name="owner_number" value="<?= htmlspecialchars($botConfig['OWNER_NUMBER']) ?>" placeholder="628xxxxxxxxxx">
      <button type="submit" class="btn btn-primary">Simpan Nomor</button>
    </form>
  </div>

  <div class="card">
    <h2>Pairing Ulang</h2>
    <div class="sub" style="margin-bottom:12px;">Menghapus sesi login lama dan memulai pairing baru. Bot akan restart otomatis, cek log di bawah untuk kode pairing.</div>
    <form method="POST" onsubmit="return confirm('Yakin mau hapus sesi login dan pairing ulang?');">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="pairing_ulang">
      <button type="submit" class="btn btn-danger">Pairing Ulang</button>
    </form>
  </div>

  <div class="card">
    <h2>Log Real-Time</h2>
    <div class="log-box" id="logBox">Memuat log...</div>
    <div class="log-updated" id="logUpdated"></div>
  </div>
</div>

<div id="pairingPopup" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#161616;border:1px solid #262626;border-radius:12px;padding:28px 24px;max-width:340px;text-align:center;">
    <div style="font-size:0.85rem;color:#888;margin-bottom:10px;">Kode Pairing WhatsApp</div>
    <div id="pairingCodeText" style="font-size:2rem;font-weight:700;letter-spacing:3px;color:#6bff8f;font-family:'Courier New',monospace;margin-bottom:16px;"></div>
    <div style="font-size:0.78rem;color:#888;margin-bottom:16px;">Masukkan kode ini di WhatsApp: Perangkat Tertaut &rarr; Tautkan dengan nomor telepon</div>
    <button onclick="document.getElementById('pairingPopup').style.display='none'" style="background:#262626;color:#eaeaea;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;">Tutup</button>
  </div>
</div>

<script>
let lastPairingCodeShown = localStorage.getItem('nexhost_last_pairing_code') || null;

function loadBotLog() {
  fetch('bot_promosi_log.php?lines=100')
    .then(r => r.json())
    .then(d => {
      const box = document.getElementById('logBox');
      const wasAtBottom = box.scrollHeight - box.scrollTop <= box.clientHeight + 30;
      box.textContent = d.log;
      if (wasAtBottom) box.scrollTop = box.scrollHeight;

      document.getElementById('logUpdated').textContent = 'Update: ' + new Date().toLocaleTimeString('id-ID');

      const badge = document.getElementById('serviceStatusBadge');
      badge.textContent = d.status === 'active' ? 'ACTIVE' : d.status.toUpperCase();
      badge.className = 'status-badge ' + (d.status === 'active' ? 'active' : 'inactive');

      // Deteksi kode pairing HANYA dari baris log paling baru (bukan scan ulang seluruh histori log),
      // dan HANYA pola teks yang eksplisit menyebut "pairing code" -- mencegah false-positive
      // dari string acak lain di log (ID grup, hash, dsb yang kebetulan formatnya mirip).
      const logLines = d.log.split('\n');
      const recentLines = logLines.slice(-15).join('\n'); // cuma 15 baris terakhir
      const pairingMatch = recentLines.match(/pairing code[:\s]+([A-Z0-9]{4}-[A-Z0-9]{4})/i);
      if (pairingMatch && pairingMatch[1] && pairingMatch[1] !== lastPairingCodeShown) {
        lastPairingCodeShown = pairingMatch[1];
        localStorage.setItem('nexhost_last_pairing_code', pairingMatch[1]);
        document.getElementById('pairingCodeText').textContent = pairingMatch[1];
        document.getElementById('pairingPopup').style.display = 'flex';
      }
    })
    .catch(() => {
      document.getElementById('logUpdated').textContent = 'Gagal memuat log';
    });
}

loadBotLog();
setInterval(loadBotLog, 2000);
</script>
</body>
</html>

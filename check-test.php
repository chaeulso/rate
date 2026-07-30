<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$csrf = generate_csrf_token();
$msg = '';
$msgType = '';

$keys_file = __DIR__ . '/data/linode_keys.json';
if (!file_exists($keys_file)) file_put_contents($keys_file, '[]', LOCK_EX);
$linode_keys = json_decode(file_get_contents($keys_file), true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $label = trim($_POST['label'] ?? '');
        $token = trim($_POST['token'] ?? '');
        if (!$label || !$token) {
            $msg = 'Label dan token wajib diisi.'; $msgType = 'error';
        } else {
            $linode_keys[] = ['id' => uniqid(), 'label' => $label, 'token' => $token, 'added' => date('Y-m-d H:i:s')];
            file_put_contents($keys_file, json_encode($linode_keys, JSON_PRETTY_PRINT), LOCK_EX);
            $msg = 'API key berhasil ditambahkan.'; $msgType = 'success';
        }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $del_id = $_POST['key_id'] ?? '';
        $linode_keys = array_values(array_filter($linode_keys, fn($k) => $k['id'] !== $del_id));
        file_put_contents($keys_file, json_encode($linode_keys, JSON_PRETTY_PRINT), LOCK_EX);
        $msg = 'API key dihapus.'; $msgType = 'success';
    }
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $edit_id = $_POST['key_id'] ?? '';
        $new_label = trim($_POST['new_label'] ?? '');
        $new_token = trim($_POST['new_token'] ?? '');
        foreach ($linode_keys as &$k) {
            if ($k['id'] === $edit_id) {
                if ($new_label) $k['label'] = $new_label;
                if ($new_token) $k['token'] = $new_token;
            }
        }
        file_put_contents($keys_file, json_encode($linode_keys, JSON_PRETTY_PRINT), LOCK_EX);
        $msg = 'API key diperbarui.'; $msgType = 'success';
    }
    header('Location: /check-test.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit;
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['type'] ?? 'success'; }

// ===== API ENDPOINTS =====
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $key_id = $_GET['key_id'] ?? '';
    $token = '';
    foreach ($linode_keys as $k) { if ($k['id'] === $key_id) { $token = $k['token']; break; } }
    if (!$token) { echo json_encode(['error' => 'Token tidak ditemukan']); exit; }

    function linode_get($token, $path) {
        $ch = curl_init('https://api.linode.com/v4' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'data' => json_decode($res, true)];
    }

    if ($_GET['api'] === 'balance') {
        $r = linode_get($token, '/account');
        if ($r['code'] !== 200) { echo json_encode(['error' => "HTTP {$r['code']}"]); exit; }
        $d = $r['data'];
        echo json_encode([
            'balance' => $d['balance'] ?? null,
            'uninvoiced_balance' => $d['uninvoiced_balance'] ?? null,
            'promotions' => $d['active_promotions'] ?? [],
            'email' => $d['email'] ?? null,
            'company' => $d['company'] ?? null,
            'active_since' => $d['active_since'] ?? null,
        ]);
        exit;
    }

    if ($_GET['api'] === 'instances') {
        $r = linode_get($token, '/linode/instances?page_size=100');
        if ($r['code'] !== 200) { echo json_encode(['error' => "HTTP {$r['code']}"]); exit; }
        $instances = $r['data']['data'] ?? [];
        $now = time();
        $result = [];
        foreach ($instances as $vps) {
            $created_ts = strtotime($vps['created']);
            $days_running = floor(($now - $created_ts) / 86400);
            $hours_running = floor(($now - $created_ts) / 3600);
            $transfer = linode_get($token, '/linode/instances/' . $vps['id'] . '/transfer');
            $transfer_data = $transfer['data'] ?? [];
            $disks = linode_get($token, '/linode/instances/' . $vps['id'] . '/disks');
            $disk_list = $disks['data']['data'] ?? [];
            $disk_used_mb = array_sum(array_column($disk_list, 'size'));
            $result[] = [
                'id' => $vps['id'], 'label' => $vps['label'], 'status' => $vps['status'],
                'region' => $vps['region'], 'type' => $vps['type'], 'image' => $vps['image'] ?? '-',
                'ipv4' => $vps['ipv4'] ?? [], 'ipv6' => $vps['ipv6'] ?? '-',
                'ram_mb' => $vps['specs']['memory'] ?? 0, 'cpu' => $vps['specs']['vcpus'] ?? 0,
                'disk_mb' => $vps['specs']['disk'] ?? 0, 'disk_used_mb' => $disk_used_mb,
                'created' => $vps['created'], 'days_running' => $days_running, 'hours_running' => $hours_running,
                'transfer_used_gb' => round(($transfer_data['bytes_in'] ?? 0) / 1073741824, 2),
                'transfer_out_gb' => round(($transfer_data['bytes_out'] ?? 0) / 1073741824, 2),
                'transfer_total_gb' => round(($transfer_data['quota'] ?? 0) / 1073741824, 1),
                'backups' => $vps['backups']['enabled'] ?? false, 'tags' => $vps['tags'] ?? [],
            ];
        }
        echo json_encode(['instances' => $result, 'count' => count($result)]);
        exit;
    }
    echo json_encode(['error' => 'Unknown api']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Manager - Admin</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
body { background:#0f0f0f; color:#eaeaea; padding:16px; }
.container { max-width:780px; margin:0 auto; }
h1 { font-size:20px; margin-bottom:4px; }
.sub { color:#888; font-size:13px; margin-bottom:20px; }
.msg { padding:10px 12px; border-radius:6px; font-size:13px; margin-bottom:16px; }
.msg.error { background:#2a1414; color:#ff6b6b; border:1px solid #4a1f1f; }
.msg.success { background:#142a1a; color:#6bff8f; border:1px solid #1f4a2a; }
.card { background:#161616; border:1px solid #262626; border-radius:10px; padding:20px; margin-bottom:16px; }
.card h3 { font-size:15px; margin-bottom:12px; color:#fff; }
input { width:100%; padding:10px 12px; background:#0f0f0f; border:1px solid #333; color:#eaeaea; border-radius:6px; font-size:13px; margin-bottom:10px; }
input:focus { outline:none; border-color:#555; }
.btn { padding:9px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:none; }
.btn-green { background:#10b981; color:#fff; }
.btn-green:hover { background:#059669; }
.btn-red { background:#2a1414; color:#ff6b6b; border:1px solid #4a1f1f; }
.btn-red:hover { background:#3a1c1c; }
.btn-blue { background:#1e3a5f; color:#60a5fa; border:1px solid #2a4a7f; }
.btn-blue:hover { background:#254a7a; }
.btn-gray { background:#222; color:#888; border:1px solid #333; }
.btn-purple { background:#2a1a4a; color:#a78bfa; border:1px solid #4a2a7a; }
.btn-purple:hover { background:#3a2a5a; }
.key-row { background:#0f0f0f; border:1px solid #222; border-radius:8px; padding:14px; margin-bottom:10px; }
.key-row .top { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.key-label { font-weight:600; font-size:14px; }
.key-token { font-size:11px; color:#555; font-family:monospace; margin-bottom:8px; word-break:break-all; }
.balance-box { background:#111; border:1px solid #1a2a1a; border-radius:6px; padding:10px 12px; font-size:13px; min-height:40px; margin-bottom:8px; }
.instances-box { margin-top:10px; }
.vps-card { background:#111; border:1px solid #222; border-radius:8px; padding:14px; margin-bottom:10px; }
.vps-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.vps-name { font-weight:700; font-size:14px; color:#fff; }
.status-badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.status-running { background:#0a2a0a; color:#6bff8f; border:1px solid #1a4a1a; }
.status-offline { background:#2a0a0a; color:#ff6b6b; border:1px solid #4a1a1a; }
.status-other { background:#2a2a0a; color:#ffd86b; border:1px solid #4a4a1a; }
.vps-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px; }
.vps-stat { background:#0a0a0a; border:1px solid #1a1a1a; border-radius:6px; padding:8px 10px; }
.vps-stat-label { font-size:10px; color:#555; margin-bottom:2px; text-transform:uppercase; letter-spacing:.5px; }
.vps-stat-val { font-size:14px; font-weight:700; color:#eaeaea; }
.vps-stat-val.green { color:#6bff8f; }
.vps-stat-val.blue { color:#60a5fa; }
.vps-stat-val.red { color:#ff6b6b; }
.days-big { font-size:22px; font-weight:800; color:#a78bfa; }
.progress-bar { height:6px; background:#1a1a1a; border-radius:3px; overflow:hidden; margin-top:4px; }
.progress-fill { height:100%; border-radius:3px; transition:width .3s; }
.ip-list { font-family:monospace; font-size:12px; color:#60a5fa; }
.btn-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.topbar { display:flex; align-items:center; justify-content:space-between; max-width:780px; margin:0 auto 4px; }
.back-btn { color:#60a5fa; text-decoration:none; font-size:13px; }
.spinner { display:inline-block; width:14px; height:14px; border:2px solid #333; border-top-color:#6bff8f; border-radius:50%; animation:spin .6s linear infinite; vertical-align:middle; margin-right:6px; }
@keyframes spin { to { transform:rotate(360deg); } }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; }
.modal-bg.show { display:flex; }
.modal { background:#161616; border:1px solid #333; border-radius:10px; padding:24px; width:100%; max-width:400px; margin:16px; }
.modal h3 { margin-bottom:16px; }
.balance-val { color:#6bff8f; font-size:18px; font-weight:700; }
</style>
</head>
<body>
<div class="topbar">
  <a href="/admin.php" class="back-btn">← Admin Panel</a>
</div>
<div class="container">
  <h1>🟢 Account Manager</h1>
  <p class="sub">Kelola API key Linode, cek saldo & monitoring VPS real-time</p>

  <?php if ($msg): ?>
  <div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3>➕ Tambah API Key Linode</h3>
    <form method="POST" action="/check-test.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <input type="text" name="label" placeholder="Label (contoh: Linode SG1)" required>
      <input type="text" name="token" placeholder="Token Linode" required>
      <button type="submit" class="btn btn-green">Simpan API Key</button>
    </form>
  </div>

  <?php if (empty($linode_keys)): ?>
  <div class="card"><p style="color:#888;font-size:13px;text-align:center;">Belum ada API key.</p></div>
  <?php else: foreach ($linode_keys as $k): ?>
  <div class="key-row" id="row-<?= $k['id'] ?>">
    <div class="top">
      <span class="key-label">🔑 <?= htmlspecialchars($k['label']) ?></span>
      <span style="font-size:11px;color:#555;"><?= htmlspecialchars($k['added'] ?? '') ?></span>
    </div>
    <div class="key-token"><?= htmlspecialchars(substr($k['token'],0,12)) ?>...<?= htmlspecialchars(substr($k['token'],-4)) ?></div>
    <div class="balance-box" id="balance-<?= $k['id'] ?>">
      <span style="color:#555;">Tekan tombol untuk refresh data</span>
    </div>
    <div class="instances-box" id="instances-<?= $k['id'] ?>"></div>
    <div class="btn-actions">
      <button class="btn btn-green" onclick="cekSaldo('<?= $k['id'] ?>')">💰 Cek Saldo</button>
      <button class="btn btn-purple" onclick="cekInstances('<?= $k['id'] ?>')">🖥️ Cek VPS</button>
      <button class="btn btn-green" onclick="cekAll('<?= $k['id'] ?>')">🔄 Cek Semua</button>
      <button class="btn btn-blue" onclick="openEdit('<?= $k['id'] ?>','<?= htmlspecialchars(addslashes($k['label'])) ?>')">✏️ Edit</button>
      <form method="POST" action="/check-test.php" onsubmit="return confirm('Hapus API key ini?');" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
        <button type="submit" class="btn btn-red">🗑️ Hapus</button>
      </form>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<div class="modal-bg" id="editModal">
  <div class="modal">
    <h3>✏️ Edit API Key</h3>
    <form method="POST" action="/check-test.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="key_id" id="editKeyId">
      <input type="text" name="new_label" id="editLabel" placeholder="Label baru">
      <input type="text" name="new_token" placeholder="Token baru (kosongkan jika tidak diubah)">
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" class="btn btn-green">Simpan</button>
        <button type="button" class="btn btn-gray" onclick="closeEdit()">Batal</button>
      </div>
    </form>
  </div>
</div>
<script>
async function cekSaldo(keyId) {
  const box = document.getElementById('balance-' + keyId);
  box.innerHTML = '<span class="spinner"></span> Mengambil saldo...';
  try {
    const res = await fetch('/check-test.php?api=balance&key_id=' + keyId);
    const data = await res.json();
    if (data.error) { box.innerHTML = '<span style="color:#ff6b6b;">❌ ' + data.error + '</span>'; return; }
    const since = data.active_since ? data.active_since.slice(0,10) : '-';
    const sinceTs = data.active_since ? Math.floor((Date.now() - new Date(data.active_since)) / 86400000) : '-';
    box.innerHTML = `
      <div style="margin-bottom:6px;font-size:12px;color:#888;">📧 <b style="color:#eaeaea;">${data.email||'-'}</b>${data.company ? ' · '+data.company : ''}</div>
      <div style="margin-bottom:4px;">💵 Saldo: <span class="balance-val">$${parseFloat(data.balance||0).toFixed(2)}</span>
        <span style="color:#888;font-size:12px;margin-left:8px;">Uninvoiced: $${parseFloat(data.uninvoiced_balance||0).toFixed(2)}</span></div>
      <div style="font-size:11px;color:#555;">📅 Akun aktif sejak: ${since} (${sinceTs} hari lalu)</div>
      ${(data.promotions||[]).map(p => `
        <div style="margin-top:8px;padding:8px 10px;background:#0a1a0a;border:1px solid #1a4a1a;border-radius:6px;">
          <div style="font-size:11px;color:#888;">🎁 Promo Kredit</div>
          <div style="color:#6bff8f;font-weight:700;font-size:16px;">$${parseFloat(p.credit_remaining).toFixed(2)} tersisa</div>
          <div style="font-size:11px;color:#888;">Expired: ${(p.expire_dt||'').slice(0,10)} | ${p.summary||''}</div>
        </div>`).join('')}
    `;
  } catch(e) { box.innerHTML = '<span style="color:#ff6b6b;">❌ ' + e.message + '</span>'; }
}

async function cekInstances(keyId) {
  const box = document.getElementById('instances-' + keyId);
  box.innerHTML = '<div style="padding:10px;color:#888;"><span class="spinner"></span> Mengambil data VPS...</div>';
  try {
    const res = await fetch('/check-test.php?api=instances&key_id=' + keyId);
    const data = await res.json();
    if (data.error) { box.innerHTML = '<div style="color:#ff6b6b;padding:10px;">❌ ' + data.error + '</div>'; return; }
    if (!data.instances.length) { box.innerHTML = '<div style="color:#888;padding:10px;">Tidak ada VPS ditemukan.</div>'; return; }
    let html = `<div style="font-size:12px;color:#888;margin-bottom:8px;">Total: <b style="color:#eaeaea;">${data.count} VPS</b></div>`;
    for (const v of data.instances) {
      const statusClass = v.status === 'running' ? 'status-running' : (v.status === 'offline' ? 'status-offline' : 'status-other');
      const statusIcon = v.status === 'running' ? '🟢' : (v.status === 'offline' ? '🔴' : '🟡');
      const ramGb = (v.ram_mb / 1024).toFixed(1);
      const diskGb = (v.disk_mb / 1024).toFixed(0);
      const diskUsedGb = (v.disk_used_mb / 1024).toFixed(1);
      const diskPct = v.disk_mb > 0 ? Math.min(100, Math.round(v.disk_used_mb / v.disk_mb * 100)) : 0;
      const transferPct = v.transfer_total_gb > 0 ? Math.min(100, Math.round((v.transfer_used_gb + v.transfer_out_gb) / v.transfer_total_gb * 100)) : 0;
      const transferColor = transferPct > 80 ? '#ff6b6b' : transferPct > 50 ? '#ffd86b' : '#6bff8f';
      const diskColor = diskPct > 80 ? '#ff6b6b' : diskPct > 60 ? '#ffd86b' : '#60a5fa';
      const daysColor = v.days_running > 25 ? '#ff6b6b' : v.days_running > 15 ? '#ffd86b' : '#a78bfa';
      const ips = (v.ipv4||[]).join(', ') || '-';
      const createdDate = v.created ? v.created.slice(0,10) : '-';
      html += `
        <div class="vps-card">
          <div class="vps-header">
            <span class="vps-name">${statusIcon} ${v.label}</span>
            <span class="status-badge ${statusClass}">${v.status.toUpperCase()}</span>
          </div>
          <div class="vps-grid">
            <div class="vps-stat">
              <div class="vps-stat-label">⏱️ Hari Berjalan</div>
              <div class="days-big" style="color:${daysColor};">${v.days_running}</div>
              <div style="font-size:10px;color:#555;">hari (${v.hours_running} jam) · sejak ${createdDate}</div>
            </div>
            <div class="vps-stat">
              <div class="vps-stat-label">📍 Region</div>
              <div class="vps-stat-val blue">${v.region}</div>
              <div style="font-size:10px;color:#555;">${v.type}</div>
            </div>
            <div class="vps-stat">
              <div class="vps-stat-label">🧠 RAM</div>
              <div class="vps-stat-val green">${ramGb} GB</div>
              <div style="font-size:10px;color:#555;">${v.cpu} vCPU</div>
            </div>
            <div class="vps-stat">
              <div class="vps-stat-label">💾 Disk</div>
              <div class="vps-stat-val" style="color:${diskColor};">${diskUsedGb} / ${diskGb} GB</div>
              <div class="progress-bar"><div class="progress-fill" style="width:${diskPct}%;background:${diskColor};"></div></div>
              <div style="font-size:10px;color:#555;">${diskPct}% terpakai</div>
            </div>
            <div class="vps-stat">
              <div class="vps-stat-label">🌐 Transfer Bulan Ini</div>
              <div class="vps-stat-val" style="color:${transferColor};">${(v.transfer_used_gb + v.transfer_out_gb).toFixed(2)} / ${v.transfer_total_gb} GB</div>
              <div class="progress-bar"><div class="progress-fill" style="width:${transferPct}%;background:${transferColor};"></div></div>
              <div style="font-size:10px;color:#555;">In: ${v.transfer_used_gb}GB · Out: ${v.transfer_out_gb}GB</div>
            </div>
            <div class="vps-stat">
              <div class="vps-stat-label">🔒 Backup</div>
              <div class="vps-stat-val ${v.backups ? 'green' : 'red'}">${v.backups ? '✅ Aktif' : '❌ Nonaktif'}</div>
              <div style="font-size:10px;color:#555;">${v.image||'-'}</div>
            </div>
          </div>
          <div style="font-size:12px;color:#555;margin-top:4px;">
            🌐 IP: <span class="ip-list">${ips}</span>
            ${v.tags.length ? ' · 🏷️ '+v.tags.join(', ') : ''}
          </div>
        </div>`;
    }
    box.innerHTML = html;
  } catch(e) { box.innerHTML = '<div style="color:#ff6b6b;padding:10px;">❌ ' + e.message + '</div>'; }
}

async function cekAll(keyId) {
  await Promise.all([cekSaldo(keyId), cekInstances(keyId)]);
}

function openEdit(id, label) {
  document.getElementById('editKeyId').value = id;
  document.getElementById('editLabel').value = label;
  document.getElementById('editModal').classList.add('show');
}
function closeEdit() { document.getElementById('editModal').classList.remove('show'); }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEdit(); });
</script>
</body>
</html>

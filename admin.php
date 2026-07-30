<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/server_requests.php';
require_once __DIR__ . '/includes/ptero_api.php';

// Ambil daftar node real-time dari Pterodactyl API
$pteroNodes = [];
$nodesResult = ptero_get('/api/application/nodes?per_page=100');
if ($nodesResult['http_code'] === 200 && !empty($nodesResult['data']['data'])) {
    foreach ($nodesResult['data']['data'] as $n) {
        $pteroNodes[] = [
            'id' => $n['attributes']['id'],
            'name' => $n['attributes']['name'],
            'location_id' => $n['attributes']['location_id'],
            'memory' => $n['attributes']['memory'],
            'disk' => $n['attributes']['disk'],
        ];
    }
}
require_admin();

$eggData = require __DIR__ . '/includes/egg_data.php';
$requests = array_reverse(get_all_requests());
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
$csrf = generate_csrf_token();

$maint_file = __DIR__ . '/data/maintenance.json';
$maint_data = json_decode(@file_get_contents($maint_file), true) ?? ['enabled'=>false,'ends_at'=>0,'message'=>''];
$maint_active = !empty($maint_data['enabled']) && ($maint_data['ends_at'] === 0 || $maint_data['ends_at'] > time());

$flags_file = __DIR__ . '/data/feature_flags.json';
$flags = json_decode(file_get_contents($flags_file), true) ?? ['wa_otp_register'=>true,'wa_otp_login'=>true];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_sessions'])) {
    verify_csrf();
    $output = [];
    exec('find /var/lib/php/sessions -name "sess_*" -type f 2>/dev/null', $output);
    $count = count($output);
    exec('sudo /usr/local/bin/clear_php_sessions.sh 2>/dev/null');
    $tok_file = __DIR__ . '/data/remember_tokens.json';
    file_put_contents($tok_file, '{}', LOCK_EX);
    header('Location: /admin.php?success=Berhasil+hapus+' . $count . '+session+dan+semua+remember+token.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_maintenance'])) {
    verify_csrf();
    $maint_file = __DIR__ . '/data/maintenance.json';
    $maint = json_decode(file_get_contents($maint_file), true) ?? [];
    $enabled = ($_POST['maint_enabled'] ?? '0') === '1';
    $days    = max(0, intval($_POST['maint_days']   ?? 0));
    $hours   = max(0, intval($_POST['maint_hours']  ?? 0));
    $minutes = max(0, intval($_POST['maint_minutes'] ?? 0));
    $msg     = trim($_POST['maint_message'] ?? 'Sistem sedang maintenance.');
    $duration = ($days * 86400) + ($hours * 3600) + ($minutes * 60);
    $ends_at  = $enabled && $duration > 0 ? time() + $duration : 0;
    $maint = ['enabled' => $enabled, 'ends_at' => $ends_at, 'message' => $msg ?: 'Sistem sedang maintenance.'];
    file_put_contents($maint_file, json_encode($maint, JSON_PRETTY_PRINT), LOCK_EX);
    header('Location: /admin.php?success=Maintenance+diperbarui');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_flag'])) {
    verify_csrf();
    $flag = $_POST['toggle_flag'];
    if (in_array($flag, ['wa_otp_register', 'wa_otp_login'])) {
        $flags[$flag] = !($flags[$flag] ?? true);
        file_put_contents($flags_file, json_encode($flags, JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: /admin.php?success=Flag+diperbarui');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel - NexHost Bot Panel</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
body { background: #0f0f0f; color: #eaeaea; padding: 16px; }
.container { max-width: 720px; margin: 0 auto; }
h1 { font-size: 20px; margin-bottom: 4px; }
.sub { color: #888; font-size: 13px; margin-bottom: 20px; }
.msg { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
.msg.error { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.msg.success { background: #142a1a; color: #6bff8f; border: 1px solid #1f4a2a; }

.req-card { background: #161616; border: 1px solid #262626; border-radius: 8px; padding: 18px; margin-bottom: 14px; }
.req-card .top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.req-card h3 { font-size: 15px; }
.status { font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 4px; }
.status.pending { background: #2a2414; color: #ffd166; }
.status.confirmed { background: #142a1a; color: #6bff8f; }
.status.rejected { background: #2a1414; color: #ff6b6b; }

.detail { font-size: 13px; color: #ccc; line-height: 1.6; margin-bottom: 12px; }
.detail b { color: #fff; }

.actions { display: flex; gap: 8px; }
.actions form { flex: 1; }
.btn { width: 100%; border: none; padding: 10px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-confirm { background: #6bff8f; color: #0f0f0f; }
.btn-confirm:hover { background: #8fffa8; }
.btn-reject { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.btn-reject:hover { background: #3a1c1c; }

.empty { color: #888; font-size: 13px; text-align: center; padding: 30px; }

.topbar { display: flex; align-items: center; justify-content: space-between; max-width: 720px; margin: 0 auto 4px; padding: 4px 0; }
.topbar h1 { font-size: 20px; }
.hamburger { width: 38px; height: 38px; display: flex; flex-direction: column; justify-content: center; gap: 6px; cursor: pointer; padding: 8px; background: transparent; border: none; }
.hamburger span { display: block; height: 2px; width: 100%; background: #eaeaea; border-radius: 2px; transition: 0.3s ease; }
.hamburger.open span:nth-child(1) { transform: translateY(8px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-8px) rotate(-45deg); }

.overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 95; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
.overlay.show { opacity: 1; pointer-events: auto; }

.sidebar { width: 260px; background: #161616; border-right: 1px solid #262626; position: fixed; top: 0; bottom: 0; left: 0; z-index: 100; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); padding: 20px 16px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }
.sidebar.open { transform: translateX(0); }
.sidebar-header { font-size: 16px; font-weight: 700; color: #fff; padding: 0 8px 16px; border-bottom: 1px solid #262626; margin-bottom: 8px; }
.nav-group-label { font-size: 11px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 12px 6px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; color: #ccc; text-decoration: none; font-size: 14px; font-weight: 500; transition: 0.2s; }
.nav-item:hover { background: #1f1f1f; color: #fff; }
.nav-item.nav-danger { color: #ff6b6b; }
.nav-item.nav-danger:hover { background: #2a1414; }
.nav-divider { height: 1px; background: #262626; margin: 8px 0; }

.flags-section { max-width: 700px; margin: 32px auto; padding: 0 4px 40px; }
.flags-box { background: #161616; border: 1px solid #262626; border-radius: 12px; padding: 24px; }
.flags-box h2 { font-size: 17px; font-weight: 700; margin-bottom: 6px; color: #eaeaea; }
.flags-box .flags-sub { color: #888; font-size: 13px; margin-bottom: 20px; }
.flag-row { background: #0f0f0f; border: 1px solid #262626; border-radius: 8px; padding: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.flag-row .flag-title { font-weight: 600; font-size: 14px; color: #eaeaea; }
.flag-row .flag-desc { font-size: 12px; color: #888; margin-top: 4px; }
.flag-btn { padding: 8px 20px; border-radius: 6px; border: 1px solid transparent; font-size: 13px; font-weight: 600; cursor: pointer; }
.flag-btn.on { background: #142a1a; color: #6bff8f; border-color: #1f4a2a; }
.flag-btn.off { background: #222; color: #888; border-color: #333; }
.danger-block { margin-top: 20px; padding-top: 20px; border-top: 1px solid #262626; }
.danger-block .title { font-weight: 600; font-size: 14px; color: #eaeaea; margin-bottom: 4px; }
.danger-block .desc { font-size: 12px; color: #888; margin-bottom: 12px; }
.btn-danger { padding: 10px 24px; border-radius: 6px; border: 1px solid #4a1f1f; background: #2a1414; color: #ff6b6b; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-danger:hover { background: #3a1c1c; }
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">Menu Admin</div>

  <div class="nav-group-label">Navigasi</div>
  <a href="/dashboard.php" class="nav-item">Kembali ke Dashboard</a>

  <div class="nav-group-label">Server &amp; Node</div>
  <a href="/upload_node_admin.php" class="nav-item">Upload Nodes</a>
  <a href="/database_node.php" class="nav-item">Database Nodes</a>

  <div class="nav-group-label">User &amp; Akun</div>
  <a href="/set_role.php" class="nav-item">Set Role User</a>
  <a href="/set_prem_user.php" class="nav-item">Kelola Premium User</a>

  <div class="nav-group-label">Keuangan</div>
  <a href="/admin_dana_kaget.php" class="nav-item">Dana Kaget</a>
  <a href="/check-test.php" class="nav-item">Linode Manager</a>

  <div class="nav-group-label">Sistem</div>
  <a href="#maintenance-section" class="nav-item" onclick="toggleMenu()">Maintenance Mode</a>
  <a href="#feature-flags" class="nav-item" onclick="toggleMenu()">Fitur Toggle</a>
  <a href="/spark_log.php" class="nav-item">SPARK Inject Log</a>
  <a href="/broadcast.php" class="nav-item">Broadcast WA</a>
  <a href="/bot_promosi.php" class="nav-item">Bot Promosi</a>

  <div class="nav-divider"></div>
  <a href="/logout.php" class="nav-item nav-danger">Logout</a>
</aside>

<div class="topbar">
  <h1>Admin Panel</h1>
  <button class="hamburger" id="hamburger" onclick="toggleMenu()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</div>

<div class="container">
    <p class="sub">Kelola request server bot dari user</p>

    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div style="margin-bottom:20px;">
        <input type="text" id="searchEmail" placeholder="Cari user berdasarkan email..." oninput="filterRequests()" style="width:100%;padding:10px 14px;background:#161616;border:1px solid #262626;color:#eaeaea;border-radius:8px;font-size:14px;">
    </div>

    <?php if (empty($requests)): ?>
        <div class="empty">Belum ada request server.</div>
    <?php else: foreach ($requests as $r): ?>
        <div class="req-card">
            <div class="top">
                <h3><?= htmlspecialchars($r['server_name']) ?></h3>
                <span class="status <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>
            </div>
            <div class="detail">
                User: <b><?= htmlspecialchars($r['username']) ?></b> (<?= htmlspecialchars($r['email']) ?>)<br>
                Egg: <b><?= htmlspecialchars($eggData[$r['egg_type']]['label'] ?? $r['egg_type']) ?></b> — <?= htmlspecialchars($r['docker_image']) ?><br>
                Startup file: <b><?= htmlspecialchars($r['startup_var']) ?></b><br>
                RAM: <b><?= htmlspecialchars($r['ram']) ?>MB</b> | Disk: <b><?= htmlspecialchars($r['disk']) ?>MB</b> | CPU: <b><?= htmlspecialchars($r['cpu']) ?>%</b> | Port: <b><?= htmlspecialchars($r['port']) ?></b><br>
                Diminta: <?= htmlspecialchars($r['created_at']) ?>
                <?php if (isset($r['note'])): ?><br>Catatan: <?= htmlspecialchars($r['note']) ?><?php endif; ?>
            </div>

            <?php if ($r['status'] === 'pending'): ?>
            <div style="margin-bottom:10px;">
                <label style="font-size:12px;color:#888;display:block;margin-bottom:6px;">Pilih Node (Pterodactyl):</label>
                <select id="node-select-<?= htmlspecialchars($r['id']) ?>" style="width:100%;padding:10px 12px;background:#0f0f0f;border:1px solid #333;color:#eaeaea;border-radius:6px;font-size:13px;">
                    <?php if (empty($pteroNodes)): ?>
                        <option value="">⚠️ Gagal ambil daftar node dari Ptero</option>
                    <?php else: foreach ($pteroNodes as $n): ?>
                        <option value="<?= htmlspecialchars($n['id']) ?>">
                            <?= htmlspecialchars($n['name']) ?> (ID: <?= htmlspecialchars($n['id']) ?>) — <?= round($n['memory']/1024,1) ?>GB RAM
                        </option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
            <div class="actions">
                <form method="POST" action="admin_action.php" onsubmit="document.getElementById('node-hidden-<?= htmlspecialchars($r['id']) ?>').value = document.getElementById('node-select-<?= htmlspecialchars($r['id']) ?>').value; return confirm('Konfirmasi dan buat server di node ini sekarang?');">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($r['id']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="node_id" id="node-hidden-<?= htmlspecialchars($r['id']) ?>" value="">
                    <button type="submit" class="btn btn-confirm">Konfirmasi &amp; Buat Server</button>
                </form>
                <form method="POST" action="admin_action.php" onsubmit="return confirm('Tolak request ini?');">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($r['id']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn btn-reject">Tolak</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="flags-section" id="maintenance-section">
  <div class="flags-box">
    <h2>🔧 Maintenance Mode</h2>
    <p class="flags-sub">Aktifkan maintenance untuk mengunci form pembuatan server di dashboard user.</p>
    <form method="POST" action="/admin.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="set_maintenance" value="1">

      <div class="flag-row" style="flex-direction:column;align-items:flex-start;gap:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
          <div>
            <div class="flag-title">Status Maintenance</div>
            <div class="flag-desc">Saat aktif, user tidak bisa membuat server baru.</div>
          </div>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="maint_enabled" value="1" <?= $maint_active ? 'checked' : '' ?> style="width:16px;height:16px;">
            <span style="font-size:13px;color:<?= $maint_active ? '#f59e0b' : '#888' ?>;"><?= $maint_active ? 'Aktif' : 'Nonaktif' ?></span>
          </label>
        </div>

        <div style="width:100%;">
          <div class="flag-desc" style="margin-bottom:8px;">Durasi maintenance (dari sekarang):</div>
          <div style="display:flex;gap:8px;">
            <div style="flex:1;">
              <label style="font-size:11px;color:#888;display:block;margin-bottom:4px;">Hari</label>
              <input type="number" name="maint_days" min="0" value="0" style="width:100%;padding:8px;background:#0a0a0a;border:1px solid #333;color:#eaeaea;border-radius:6px;font-size:14px;text-align:center;">
            </div>
            <div style="flex:1;">
              <label style="font-size:11px;color:#888;display:block;margin-bottom:4px;">Jam</label>
              <input type="number" name="maint_hours" min="0" max="23" value="0" style="width:100%;padding:8px;background:#0a0a0a;border:1px solid #333;color:#eaeaea;border-radius:6px;font-size:14px;text-align:center;">
            </div>
            <div style="flex:1;">
              <label style="font-size:11px;color:#888;display:block;margin-bottom:4px;">Menit</label>
              <input type="number" name="maint_minutes" min="0" max="59" value="0" style="width:100%;padding:8px;background:#0a0a0a;border:1px solid #333;color:#eaeaea;border-radius:6px;font-size:14px;text-align:center;">
            </div>
          </div>
          <div class="flag-desc" style="margin-top:6px;">Kosongkan semua (0) = maintenance tanpa batas waktu.</div>
        </div>

        <?php if ($maint_data['ends_at'] > 0): ?>
        <div style="font-size:12px;color:#f59e0b;">
          ⏱ Selesai: <?= date('d M Y H:i', $maint_data['ends_at']) ?> WIB
        </div>
        <?php endif; ?>

        <div style="width:100%;">
          <label style="font-size:11px;color:#888;display:block;margin-bottom:4px;">Pesan untuk user</label>
          <input type="text" name="maint_message" value="<?= htmlspecialchars($maint_data['message'] ?? '') ?>" placeholder="Sistem sedang maintenance..." style="width:100%;padding:8px 12px;background:#0a0a0a;border:1px solid #333;color:#eaeaea;border-radius:6px;font-size:13px;">
        </div>

        <button type="submit" style="padding:10px 24px;border-radius:6px;border:none;background:#f59e0b;color:#0a0a0a;font-size:13px;font-weight:700;cursor:pointer;">Simpan Maintenance</button>
      </div>
    </form>
  </div>
</div>

<div class="flags-section" id="feature-flags">
  <div class="flags-box">
    <h2>Toggle Fitur WA OTP</h2>
    <p class="flags-sub">Aktifkan atau nonaktifkan verifikasi nomor WhatsApp saat Register dan Login.</p>

    <div class="flag-row">
      <div>
        <div class="flag-title">WA OTP — Register</div>
        <div class="flag-desc">Wajibkan verifikasi nomor WhatsApp saat pendaftaran akun baru.</div>
      </div>
      <form method="POST" action="/admin.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="toggle_flag" value="wa_otp_register">
        <button type="submit" class="flag-btn <?= ($flags['wa_otp_register'] ?? true) ? 'on' : 'off' ?>">
          <?= ($flags['wa_otp_register'] ?? true) ? 'Aktif' : 'Nonaktif' ?>
        </button>
      </form>
    </div>

    <div class="flag-row">
      <div>
        <div class="flag-title">WA OTP — Login</div>
        <div class="flag-desc">Wajibkan verifikasi nomor WhatsApp saat login untuk akun yang belum punya nomor WA.</div>
      </div>
      <form method="POST" action="/admin.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="toggle_flag" value="wa_otp_login">
        <button type="submit" class="flag-btn <?= ($flags['wa_otp_login'] ?? true) ? 'on' : 'off' ?>">
          <?= ($flags['wa_otp_login'] ?? true) ? 'Aktif' : 'Nonaktif' ?>
        </button>
      </form>
    </div>

    <div class="danger-block">
      <div class="title">Hapus Semua Session</div>
      <div class="desc">Semua user (kecuali kamu) akan logout otomatis dan harus login ulang. Remember token juga dihapus.</div>
      <form method="POST" action="/admin.php" onsubmit="return confirm('Yakin? Semua user akan di-logout sekarang.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="clear_sessions" value="1">
        <button type="submit" class="btn-danger">Logout Semua User</button>
      </form>
    </div>
  </div>
</div>

<script>
function filterRequests() {
    const q = document.getElementById('searchEmail').value.toLowerCase();
    document.querySelectorAll('.req-card').forEach(card => {
        const text = card.querySelector('.detail').textContent.toLowerCase();
        card.style.display = text.includes(q) ? '' : 'none';
    });
}
function toggleMenu() {
  document.getElementById('hamburger').classList.toggle('open');
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
if (window.location.search) {
  history.replaceState({}, document.title, window.location.pathname);
}
</script>
</body>
</html>

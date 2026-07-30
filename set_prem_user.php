<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ptero_api.php';
require_once __DIR__ . '/includes/server_requests.php';
require_admin();

$csrf = generate_csrf_token();
$error = '';
$success = '';

function get_all_users_data() {
    $file = __DIR__ . '/data/users.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

function save_all_users_data($users) {
    $file = __DIR__ . '/data/users.json';
    $tmp = $file . '.tmp';
    $fp = fopen($tmp, 'w');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode(array_values($users), JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    rename($tmp, $file);
}

function get_user_servers($ptero_id) {
    if (!$ptero_id) return [];
    return ptero_get_servers_by_user($ptero_id);
}
function verify_csrf_token($token) {
    return !empty($token) && $token === ($_SESSION['csrf_token'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        $email = strtolower(trim($_POST['email'] ?? ''));
        $users = get_all_users_data();
        $idx = null;
        foreach ($users as $i => $u) {
            if (strtolower($u['email']) === $email) { $idx = $i; break; }
        }

        if ($idx === null) {
            $error = 'User tidak ditemukan.';
        } else {
            if ($action === 'set_premium') {
                $expDate = $_POST['exp_date'] ?? '';
                $expTime = $_POST['exp_time'] ?? '23:59:59';
                if (!$expDate) { $error = 'Tanggal expired wajib diisi.'; }
                else {
                    $ts = strtotime($expDate . ' ' . $expTime);
                    if (!$ts || $ts < time()) { $error = 'Tanggal expired harus di masa depan.'; }
                    else {
                        $users[$idx]['role'] = 'premium';
                        $users[$idx]['premium_expires'] = date('c', $ts);
                        save_all_users_data($users);
                        $success = "User {$users[$idx]['username']} berhasil di-set Premium sampai " . date('d M Y H:i', $ts) . '.';
                    }
                }
            } elseif ($action === 'set_member') {
                $users[$idx]['role'] = 'member';
                unset($users[$idx]['premium_expires']);
                save_all_users_data($users);
                $success = "User {$users[$idx]['username']} di-downgrade ke Member.";
            } elseif ($action === 'set_admin') {
                $users[$idx]['role'] = 'admin';
                unset($users[$idx]['premium_expires']);
                save_all_users_data($users);
                $success = "User {$users[$idx]['username']} di-set Admin.";
            } elseif ($action === 'delete_servers') {
                $pteroId = $users[$idx]['ptero_id'] ?? null;
                if (!$pteroId) { $error = 'User belum punya akun Pterodactyl.'; }
                else {
                    $servers = get_user_servers($pteroId);
                    $deleted = 0;
                    foreach ($servers as $srv) {
                        $sid = $srv['id'] ?? null;
                        if ($sid) { ptero_delete_server($sid); $deleted++; }
                    }
                    // Hapus juga dari server_requests.json
                    $reqs = get_all_requests();
                    $reqs = array_values(array_filter($reqs, fn($r) => $r['email'] !== $email));
                    $tmp = __DIR__ . '/data/server_requests.json.tmp';
                    file_put_contents($tmp, json_encode($reqs, JSON_PRETTY_PRINT));
                    rename($tmp, __DIR__ . '/data/server_requests.json');
                    $success = "Berhasil hapus {$deleted} server milik {$users[$idx]['username']}.";
                }
            } elseif ($action === 'delete_user') {
                $uname = $users[$idx]['username'];
                // Hapus server dulu
                $pteroId = $users[$idx]['ptero_id'] ?? null;
                if ($pteroId) {
                    $servers = get_user_servers($pteroId);
                    foreach ($servers as $srv) { if ($srv['id'] ?? null) ptero_delete_server($srv['id']); }
                    // Hapus ptero user
                    ptero_delete('/api/application/users/' . $pteroId);
                }
                // Hapus requests
                $reqs = array_values(array_filter(get_all_requests(), fn($r) => $r['email'] !== $email));
                file_put_contents(__DIR__ . '/data/server_requests.json', json_encode($reqs, JSON_PRETTY_PRINT));
                // Hapus user
                unset($users[$idx]);
                save_all_users_data($users);
                $success = "User {$uname} dan semua datanya berhasil dihapus.";
            }
        }
    }
}

$users = get_all_users_data();
usort($users, fn($a,$b) => strcmp($a['role']??'',$b['role']??''));
$roleOrder = ['owner'=>0,'admin'=>1,'premium'=>2,'member'=>3];
usort($users, fn($a,$b) => ($roleOrder[$a['role']??'member']??3) <=> ($roleOrder[$b['role']??'member']??3));
$search = strtolower(trim($_GET['search'] ?? ''));
if ($search) {
    $users = array_filter($users, function($u) use ($search) {
        return str_contains(strtolower($u['email'] ?? ''), $search) ||
               str_contains(strtolower($u['username'] ?? ''), $search);
    });
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kelola User — NexHost Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
body{background:#0f0f0f;color:#eaeaea;padding:16px;}
.container{max-width:800px;margin:0 auto;}
h1{font-size:20px;margin-bottom:4px;}
.sub{color:#888;font-size:13px;margin-bottom:20px;}
.msg{padding:10px 12px;border-radius:6px;font-size:13px;margin-bottom:16px;}
.msg.error{background:#2a1414;color:#ff6b6b;border:1px solid #4a1f1f;}
.msg.success{background:#142a1a;color:#6bff8f;border:1px solid #1f4a2a;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:800px;margin:0 auto 4px;padding:4px 0;}
.hamburger{width:38px;height:38px;display:flex;flex-direction:column;justify-content:center;gap:6px;cursor:pointer;padding:8px;background:transparent;border:none;}
.hamburger span{display:block;height:2px;width:100%;background:#eaeaea;border-radius:2px;transition:0.3s ease;}
.hamburger.open span:nth-child(1){transform:translateY(8px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;}
.hamburger.open span:nth-child(3){transform:translateY(-8px) rotate(-45deg);}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:95;opacity:0;pointer-events:none;transition:opacity 0.3s;}
.overlay.show{opacity:1;pointer-events:auto;}
.sidebar{width:260px;background:#161616;border-right:1px solid #262626;position:fixed;top:0;bottom:0;left:0;z-index:100;transform:translateX(-100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);padding:20px 16px;display:flex;flex-direction:column;gap:8px;}
.sidebar.open{transform:translateX(0);}
.sidebar-header{font-size:16px;font-weight:700;color:#fff;padding:0 8px 16px;border-bottom:1px solid #262626;margin-bottom:12px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;color:#ccc;text-decoration:none;font-size:14px;font-weight:500;transition:0.2s;}
.nav-item:hover{background:#1f1f1f;color:#fff;}
.nav-item.nav-danger{color:#ff6b6b;}
.nav-item.nav-danger:hover{background:#2a1414;}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;}
th{text-align:left;padding:10px 12px;background:#161616;color:#888;font-weight:600;border-bottom:1px solid #262626;}
td{padding:10px 12px;border-bottom:1px solid #1a1a1a;vertical-align:middle;}
tr:hover td{background:#141414;}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;}
.badge-owner{background:rgba(248,113,113,0.15);color:#f87171;}
.badge-admin{background:rgba(96,165,250,0.15);color:#60a5fa;}
.badge-premium{background:rgba(251,191,36,0.15);color:#fbbf24;}
.badge-member{background:rgba(161,161,170,0.15);color:#a1a1aa;}
.btn-sm{padding:5px 10px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;border:none;white-space:nowrap;}
.btn-prem{background:#fbbf24;color:#000;}
.btn-member{background:#374151;color:#e5e7eb;}
.btn-admin{background:#3b82f6;color:#fff;}
.btn-del-server{background:#7c3aed;color:#fff;}
.btn-del-user{background:#ef4444;color:#fff;}
.exp{font-size:11px;color:#888;}
.exp.soon{color:#f87171;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal{background:#161616;border:1px solid #262626;border-radius:12px;padding:24px;width:90%;max-width:420px;}
.modal h3{font-size:16px;margin-bottom:16px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;color:#888;margin-bottom:6px;}
.form-control{width:100%;background:#0f0f0f;border:1px solid #333;color:#eaeaea;padding:10px 12px;border-radius:6px;font-size:13px;}
.form-control:focus{outline:none;border-color:#3b82f6;}
.modal-actions{display:flex;gap:8px;margin-top:16px;}
.btn-primary{background:#3b82f6;color:#fff;padding:10px 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;flex:1;}
.btn-cancel{background:#1f1f1f;color:#ccc;padding:10px 16px;border:1px solid #333;border-radius:6px;font-size:13px;cursor:pointer;flex:1;}
.quick-btns{display:flex;gap:4px;flex-wrap:wrap;}
.quick-btn{padding:4px 8px;background:#1f1f1f;border:1px solid #333;color:#ccc;border-radius:4px;font-size:11px;cursor:pointer;}
.quick-btn:hover{background:#2a2a2a;}
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">Menu</div>
  <a href="/dashboard.php" class="nav-item">&larr; Kembali ke Dashboard</a>
  <a href="/admin.php" class="nav-item">Request Server</a>
  <a href="/set_role.php" class="nav-item">Set Role User</a>
  <a href="/set_prem_user.php" class="nav-item">Kelola Premium User</a>
  <a href="/logout.php" class="nav-item nav-danger">Logout</a>
</aside>

<div class="topbar">
  <h1>Kelola User</h1>
  <button class="hamburger" id="hamburger" onclick="toggleMenu()"><span></span><span></span><span></span></button>
</div>

<div class="container">
  <p class="sub">Set premium, role, hapus server & user</p>

  <form method="GET" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
    <input type="text" name="search" placeholder="Cari email atau username..."
      value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
      class="form-control" style="flex:1;min-width:200px;max-width:400px;">
    <button type="submit" class="btn-sm btn-admin" style="padding:8px 14px;">Cari</button>
    <?php if (!empty($_GET['search'])): ?>
    <a href="/set_prem_user.php" class="btn-sm btn-member" style="padding:8px 14px;text-decoration:none;line-height:1.6;">Reset</a>
    <?php endif; ?>
  </form>

  <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>User</th>
        <th>Role</th>
        <th>Expired</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
      $role = $u['role'] ?? 'member';
      $exp = $u['premium_expires'] ?? null;
      $expTs = $exp ? strtotime($exp) : null;
      $daysLeft = $expTs ? (int)(($expTs - time()) / 86400) : null;
      $isSoon = $daysLeft !== null && $daysLeft <= 3;
    ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= htmlspecialchars($u['username']) ?></div>
          <div style="font-size:11px;color:#666;"><?= htmlspecialchars($u['email']) ?></div>
          <?php if (!empty($u['wa_number'])): ?>
          <div style="font-size:11px;color:#4ade80;">+<?= htmlspecialchars($u['wa_number']) ?></div>
          <?php else: ?>
          <div style="font-size:11px;color:#555;">— no WA</div>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-<?= $role ?>"><?= $role ?></span></td>
        <td>
          <?php if ($expTs): ?>
            <div class="exp <?= $isSoon ? 'soon' : '' ?>"><?= date('d M Y', $expTs) ?></div>
            <div class="exp"><?= $daysLeft >= 0 ? $daysLeft . ' hari lagi' : 'EXPIRED' ?></div>
          <?php else: ?>
            <span style="color:#444;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="quick-btns">
            <button class="btn-sm btn-prem" onclick="openPremModal('<?= htmlspecialchars($u['email']) ?>', '<?= htmlspecialchars($u['username']) ?>')">Premium</button>
            <button class="btn-sm btn-member" onclick="submitAction('set_member','<?= htmlspecialchars($u['email']) ?>')">Member</button>
            <?php if ($role !== 'owner'): ?>
            <button class="btn-sm btn-admin" onclick="submitAction('set_admin','<?= htmlspecialchars($u['email']) ?>')">Admin</button>
            <button class="btn-sm btn-del-server" onclick="submitAction('delete_servers','<?= htmlspecialchars($u['email']) ?>')">Hapus Server</button>
            <button class="btn-sm btn-del-user" onclick="submitAction('delete_user','<?= htmlspecialchars($u['email']) ?>')">Hapus User</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal Set Premium -->
<div class="modal-overlay" id="premModal">
  <div class="modal">
    <h3>Set Premium — <span id="modalUsername"></span></h3>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="set_premium">
      <input type="hidden" name="email" id="modalEmail">
      <div class="form-group">
        <label>Tanggal Expired</label>
        <input type="date" name="exp_date" id="expDate" class="form-control" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
        <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
          <button type="button" class="quick-btn" onclick="addDays(7)">+7 Hari</button>
          <button type="button" class="quick-btn" onclick="addDays(30)">+30 Hari</button>
          <button type="button" class="quick-btn" onclick="addDays(90)">+3 Bulan</button>
          <button type="button" class="quick-btn" onclick="addDays(365)">+1 Tahun</button>
          <button type="button" class="quick-btn" onclick="addDays(3650)">+10 Tahun</button>
          <button type="button" class="quick-btn" onclick="addDays(36500)">+100 Tahun</button>
        </div>
      </div>
      <div class="form-group">
        <label>Jam Expired (opsional)</label>
        <input type="time" name="exp_time" class="form-control" value="23:59:59">
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary">Set Premium</button>
        <button type="button" class="btn-cancel" onclick="closeModal()">Batal</button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden form untuk aksi cepat -->
<form method="POST" id="quickForm" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="action" id="quickAction">
  <input type="hidden" name="email" id="quickEmail">
</form>

<script>
function toggleMenu() {
  document.getElementById('hamburger').classList.toggle('open');
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}

function openPremModal(email, username) {
  document.getElementById('modalEmail').value = email;
  document.getElementById('modalUsername').textContent = username;
  document.getElementById('premModal').classList.add('show');
}
function closeModal() {
  document.getElementById('premModal').classList.remove('show');
}
document.getElementById('premModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

function addDays(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  document.getElementById('expDate').value = d.toISOString().split('T')[0];
}

function submitAction(action, email) {
  const labels = {
    'set_member': 'Downgrade ke Member',
    'set_admin': 'Set sebagai Admin',
    'delete_servers': 'Hapus SEMUA server user ini di Pterodactyl',
    'delete_user': 'Hapus user ini PERMANEN beserta semua servernya'
  };
  if (!confirm((labels[action] || action) + '?\n\n' + email)) return;
  document.getElementById('quickAction').value = action;
  document.getElementById('quickEmail').value = email;
  document.getElementById('quickForm').submit();
}

if (window.location.search) history.replaceState({}, document.title, window.location.pathname);
</script>
</body>
</html>

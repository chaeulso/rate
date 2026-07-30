<?php
define('NEXHOST_INTERNAL', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/upload_nodes.php';
require_admin();

$nodes = get_upload_nodes();
$csrf = generate_csrf_token();
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $key_content = '';
        if (!empty($_FILES['ssh_key_file']['tmp_name'])) {
            $key_content = file_get_contents($_FILES['ssh_key_file']['tmp_name']);
        } elseif (!empty($_POST['ssh_key'])) {
            $key_content = trim($_POST['ssh_key']);
        }

        $node = [
            'id'        => uniqid('upnode_'),
            'name'      => trim($_POST['name'] ?? ''),
            'ip'        => trim($_POST['ip'] ?? ''),
            'ssh_port'  => intval($_POST['ssh_port'] ?? 22),
            'ssh_user'  => trim($_POST['ssh_user'] ?? 'root'),
            'auth_type' => $_POST['auth_type'] ?? 'password',
            'ssh_pass'  => trim($_POST['ssh_pass'] ?? ''),
            'ssh_key'   => $key_content,
            'use_sudo'  => !empty($_POST['use_sudo']),
            'active'    => true,
            'created_at'=> date('Y-m-d H:i'),
        ];

        if (!$node['name'] || !$node['ip']) {
            header('Location: /upload_node_admin.php?error=' . urlencode('Nama dan IP wajib.'));
            exit;
        }
        $nodes[] = $node;
        save_upload_nodes($nodes);
        header('Location: /upload_node_admin.php?success=' . urlencode('Node ' . $node['name'] . ' ditambahkan!'));
        exit;
    }

    if ($action === 'delete') {
        $id = $_POST['node_id'] ?? '';
        $nodes = array_values(array_filter($nodes, fn($n) => $n['id'] !== $id));
        save_upload_nodes($nodes);
        header('Location: /upload_node_admin.php?success=Node+dihapus.');
        exit;
    }

    if ($action === 'test_connect') {
        $id = $_POST['node_id'] ?? '';
        $target = null;
        foreach ($nodes as $n) { if ($n['id'] === $id) { $target = $n; break; } }
        if (!$target) { echo json_encode(['ok'=>false,'msg'=>'Node tidak ditemukan']); exit; }
        $port = intval($target['ssh_port']);
        $user = escapeshellarg($target['ssh_user']);
        $ipraw = $target['ip'];
        if ($target['auth_type'] === 'ssh_key') {
            $tmpkey = tempnam(sys_get_temp_dir(), 'sshk_');
            file_put_contents($tmpkey, $target['ssh_key']);
            chmod($tmpkey, 0600);
            $cmd = "ssh -i " . escapeshellarg($tmpkey) . " -p $port -o StrictHostKeyChecking=no -o ConnectTimeout=3 -o BatchMode=yes {$user}@$ipraw 'echo CONNECTED' 2>&1";
            $out = shell_exec($cmd);
            unlink($tmpkey);
        } else {
            $pass = escapeshellarg($target['ssh_pass']);
            $cmd = "sshpass -p $pass ssh -p $port -o StrictHostKeyChecking=no -o ConnectTimeout=3 {$user}@$ipraw 'echo CONNECTED' 2>&1";
            $out = shell_exec($cmd);
        }
        $ok = strpos($out ?? '', 'CONNECTED') !== false;
        header('Content-Type: application/json');
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Koneksi berhasil':trim(substr($out??'Timeout',0,150))]);
        exit;
    }

    if ($action === 'toggle') {
        $id = $_POST['node_id'] ?? '';
        foreach ($nodes as &$n) {
            if ($n['id'] === $id) $n['active'] = !($n['active'] ?? true);
        }
        save_upload_nodes($nodes);
        header('Location: /upload_node_admin.php?success=Status+diperbarui.');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Node Manager — NexHost Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
body{background:#0a0a0a;color:#eaeaea;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;top:-50%;left:-20%;width:140%;height:140%;background:radial-gradient(circle at 20% 20%,rgba(59,130,246,.08),transparent 40%),radial-gradient(circle at 80% 70%,rgba(139,92,246,.06),transparent 40%);pointer-events:none;z-index:0}
header{position:sticky;top:0;z-index:100;background:rgba(10,10,10,0.75);backdrop-filter:blur(16px);border-bottom:1px solid #1a1a1a;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;animation:slideDown .4s ease}
.container{max-width:760px;margin:0 auto;padding:28px 16px 60px;position:relative;z-index:1}
h1{font-size:22px;font-weight:700;margin-bottom:4px;animation:fadeUp .5s ease}
.sub{color:#666;font-size:13px;margin-bottom:24px;animation:fadeUp .5s ease .05s both}
.card{background:#141414;border:1px solid #222;border-radius:14px;padding:20px;margin-bottom:20px;animation:fadeUp .5s ease .1s both;transition:border-color .25s ease,transform .25s ease;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(59,130,246,.5),transparent);opacity:0;transition:opacity .3s}
.card:hover{border-color:#2a2a2a}
.card:hover::before{opacity:1}
.card h2{font-size:15px;font-weight:600;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #222;color:#ccc}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;color:#888;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.form-input{width:100%;background:#0e0e0e;border:1px solid #222;color:#eaeaea;padding:10px 12px;border-radius:8px;font-size:14px;transition:border-color .2s ease,box-shadow .2s ease}
.form-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.form-row{display:flex;gap:10px}
.form-row .form-group{flex:1}
textarea.form-input{min-height:90px;font-family:monospace;font-size:12px;resize:vertical}
.btn{padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:transform .15s ease,filter .15s ease,box-shadow .15s ease}
.btn:hover{filter:brightness(1.15);transform:translateY(-1px)}
.btn:active{transform:translateY(0) scale(.97)}
.btn-primary{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;width:100%;margin-top:4px;box-shadow:0 4px 14px rgba(59,130,246,.25)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-danger{background:#2a1414;color:#ff6b6b;border:1px solid #3a1515}
.btn-toggle{background:#1a2a1a;color:#6bff8f;border:1px solid #153a1f}
.btn-toggle.off{background:#2a1a1a;color:#ff6b6b;border:1px solid #3a1515}
.node-card{background:#0e0e0e;border:1px solid #1e1e1e;border-radius:12px;padding:16px;margin-bottom:12px;transition:border-color .2s ease,transform .2s ease;animation:fadeUp .4s ease both}
.node-card:hover{border-color:#2e2e2e;transform:translateY(-2px)}
.node-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.node-name{font-size:15px;font-weight:600}
.node-badge{font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600}
.badge-active{background:#0f2a1a;color:#6bff8f}
.badge-active::before{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;background:#6bff8f;margin-right:5px;animation:pulse 1.6s infinite}
.badge-inactive{background:#2a1414;color:#ff6b6b}
.node-meta{font-size:12px;color:#666;display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;font-family:monospace}
.node-actions{display:flex;gap:8px}
.auth-section{display:none;opacity:0;transform:translateY(-6px);transition:opacity .25s ease,transform .25s ease}
.auth-section.show{display:block;opacity:1;transform:translateY(0)}
.empty{color:#555;font-size:14px;text-align:center;padding:30px}
.back-link{color:#888;font-size:13px;text-decoration:none;transition:color .2s ease}
.back-link:hover{color:#3b82f6}
.toast{position:fixed;top:16px;left:50%;transform:translateX(-50%) translateY(-20px);padding:12px 20px;border-radius:10px;font-size:13px;z-index:999;opacity:0;transition:opacity .3s ease,transform .3s ease;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.error{background:#1e0f0f;color:#ff6b6b;border:1px solid #3a1515}
.toast.success{background:#0f1e14;color:#6bff8f;border:1px solid #153a1f}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
</style>
</head>
<body>
<header>
    <div style="font-weight:700;">NexHost <span style="color:#888;font-weight:400;">Admin</span></div>
    <a href="/admin.php" class="back-link">← Admin Panel</a>
</header>
<div class="container">
    <h1>Upload Node Manager</h1>
    <p class="sub">Kelola VPS node untuk fitur Upload in Panel</p>

    <div class="card">
        <h2>Tambah Node</h2>
        <form method="POST" action="/upload_node_admin.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nama Node</label>
                    <input type="text" name="name" class="form-input" placeholder="Node SG-1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">IP VPS</label>
                    <input type="text" name="ip" class="form-input" placeholder="1.2.3.4" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">SSH Port</label>
                    <input type="number" name="ssh_port" class="form-input" value="22">
                </div>
                <div class="form-group">
                    <label class="form-label">SSH User</label>
                    <input type="text" name="ssh_user" class="form-input" value="root">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Metode Auth</label>
                <select name="auth_type" class="form-input" onchange="toggleAuth(this.value)">
                    <option value="password">Password</option>
                    <option value="ssh_key">SSH Key / Upload .pem</option>
                </select>
            </div>
            <div class="auth-section show" id="authPass">
                <div class="form-group">
                    <label class="form-label">SSH Password</label>
                    <div style="position:relative;">
                        <input type="password" name="ssh_pass" id="sshPassField" class="form-input" placeholder="Password SSH" style="padding-right:70px;">
                        <button type="button" onclick="togglePw()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;font-size:12px;cursor:pointer;padding:4px 8px;">
                            <span id="pwToggleText">Show</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="auth-section" id="authKey">
                <div class="form-group">
                    <label class="form-label">Upload file .pem</label>
                    <input type="file" name="ssh_key_file" class="form-input" accept=".pem,.key">
                </div>
                <div class="form-group">
                    <label class="form-label">Atau paste Private Key</label>
                    <textarea name="ssh_key" class="form-input" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
                </div>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                <input type="checkbox" name="use_sudo" id="useSudo" value="1" style="accent-color:#3b82f6;width:16px;height:16px;">
                <label for="useSudo" style="font-size:13px;color:#888;cursor:pointer;">Gunakan <code style="color:#60a5fa;">sudo</code> (user non-root)</label>
            </div>
            <button type="submit" class="btn btn-primary">+ Tambah Node</button>
        </form>
    </div>

    <div class="card">
        <h2>Node List (<?= count($nodes) ?>)</h2>
        <?php if (empty($nodes)): ?>
            <div class="empty">Belum ada node.</div>
        <?php else: foreach ($nodes as $i => $n): ?>
        <div class="node-card" style="animation-delay:<?= $i * 0.05 ?>s">
            <div class="node-header">
                <div class="node-name"><?= htmlspecialchars($n['name']) ?></div>
                <span class="node-badge <?= ($n['active'] ?? true) ? 'badge-active' : 'badge-inactive' ?>">
                    <?= ($n['active'] ?? true) ? 'Online' : 'Offline' ?>
                </span>
            </div>
            <div class="node-meta">
                <span><?= htmlspecialchars($n['ip']) ?>:<?= $n['ssh_port'] ?></span>
                <span><?= htmlspecialchars($n['ssh_user']) ?></span>
                <span><?= $n['auth_type'] === 'ssh_key' ? 'SSH Key' : 'Password' ?></span>
                <?php if ($n['use_sudo'] ?? false): ?><span>sudo</span><?php endif; ?>
                <span><?= $n['created_at'] ?></span>
            </div>
            <div class="node-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="node_id" value="<?= htmlspecialchars($n['id']) ?>">
                    <button type="submit" class="btn btn-sm <?= ($n['active'] ?? true) ? 'btn-toggle' : 'btn-toggle off' ?>">
                        <?= ($n['active'] ?? true) ? 'Aktif' : 'Nonaktif' ?>
                    </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus node ini?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="node_id" value="<?= htmlspecialchars($n['id']) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                </form>
                <button type="button" class="btn btn-sm btn-toggle" onclick="testConnect('<?= $n['id'] ?>', this)">Test Connect</button>
                </form>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<script>
function toggleAuth(v) {
    document.getElementById('authPass').classList.toggle('show', v === 'password');
    document.getElementById('authKey').classList.toggle('show', v === 'ssh_key');
}
const urlParams = new URLSearchParams(window.location.search);
const err = urlParams.get('error');
const ok = urlParams.get('success');
function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
}
if (err) showToast(decodeURIComponent(err), 'error');
if (ok) showToast(decodeURIComponent(ok), 'success');
function testConnect(id, btn) {
  const orig = btn.textContent;
  btn.textContent = 'Testing...';
  btn.disabled = true;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
  fd.append('action', 'test_connect');
  fd.append('node_id', id);
  fetch('/upload_node_admin.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => { alert(d.msg); btn.textContent = orig; btn.disabled = false; })
    .catch(() => { alert('Gagal test koneksi'); btn.textContent = orig; btn.disabled = false; });
}
function togglePw() {
  const f = document.getElementById('sshPassField');
  const t = document.getElementById('pwToggleText');
  if (f.type === 'password') { f.type = 'text'; t.textContent = 'Hide'; }
  else { f.type = 'password'; t.textContent = 'Show'; }
}
</script>
</body>
</html>

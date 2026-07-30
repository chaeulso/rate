<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$nodes_file = __DIR__ . '/data/db_nodes.json';
if (!file_exists($nodes_file)) file_put_contents($nodes_file, '[]', LOCK_EX);
$nodes = json_decode(file_get_contents($nodes_file), true) ?? [];

$csrf = generate_csrf_token();
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_node') {
        $node = [
            'id'         => uniqid('node_'),
            'name'       => trim($_POST['name'] ?? ''),
            'ip'         => trim($_POST['ip'] ?? ''),
            'ssh_port'   => intval($_POST['ssh_port'] ?? 22),
            'ssh_user'   => trim($_POST['ssh_user'] ?? 'root'),
            'auth_type'  => $_POST['auth_type'] ?? 'password',
            'ssh_pass'   => trim($_POST['ssh_pass'] ?? ''),
            'ssh_key'    => trim($_POST['ssh_key'] ?? ''),
            'use_sudo'   => !empty($_POST['use_sudo']),
            'max_db'     => intval($_POST['max_db'] ?? 50),
            'active'     => true,
            'created_at' => date('Y-m-d H:i'),
        ];
        if (!$node['name'] || !$node['ip']) {
            header('Location: /database_node.php?error=' . urlencode('Nama dan IP wajib diisi.'));
            exit;
        }
        $nodes[] = $node;
        file_put_contents($nodes_file, json_encode($nodes, JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: /database_node.php?success=' . urlencode('Node ' . $node['name'] . ' berhasil ditambahkan!'));
        exit;
    }

    if ($action === 'edit_node') {
        $id = $_POST['node_id'] ?? '';
        $new_max = intval($_POST['max_db'] ?? 50);
        $new_name = trim($_POST['name'] ?? '');
        foreach ($nodes as &$n) {
            if ($n['id'] === $id) {
                if ($new_name) $n['name'] = $new_name;
                $n['max_db'] = max(1, $new_max);
            }
        }
        file_put_contents($nodes_file, json_encode($nodes, JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: /database_node.php?success=Node+berhasil+diperbarui.');
        exit;
    }

    if ($action === 'delete_node') {
        $id = $_POST['node_id'] ?? '';
        $nodes = array_values(array_filter($nodes, fn($n) => $n['id'] !== $id));
        file_put_contents($nodes_file, json_encode($nodes, JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: /database_node.php?success=' . urlencode('Node berhasil dihapus.'));
        exit;
    }

    if ($action === 'toggle_node') {
        $id = $_POST['node_id'] ?? '';
        foreach ($nodes as &$n) {
            if ($n['id'] === $id) $n['active'] = !($n['active'] ?? true);
        }
        file_put_contents($nodes_file, json_encode($nodes, JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: /database_node.php?success=Status+node+diperbarui.');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Node Manager — NexHost Admin</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
body { background:#0f0f0f; color:#eaeaea; min-height:100vh; }
header { position:sticky; top:0; z-index:100; background:rgba(15,15,15,0.9); backdrop-filter:blur(12px); border-bottom:1px solid #1a1a1a; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; }
.brand { font-weight:700; font-size:16px; }
.back-link { color:#888; font-size:13px; text-decoration:none; }
.back-link:hover { color:#eaeaea; }
.container { max-width:720px; margin:0 auto; padding:28px 16px 60px; }
h1 { font-size:22px; font-weight:700; margin-bottom:4px; }
.sub { color:#666; font-size:13px; margin-bottom:24px; }
.msg { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
.msg.error { background:#1e0f0f; color:#ff6b6b; border:1px solid #3a1515; }
.msg.success { background:#0f1e14; color:#6bff8f; border:1px solid #153a1f; }
.card { background:#161616; border:1px solid #222; border-radius:12px; padding:20px; margin-bottom:20px; }
.card h2 { font-size:15px; font-weight:600; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #222; color:#ccc; }
.form-group { margin-bottom:14px; }
.form-label { display:block; font-size:12px; color:#888; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
.form-input { width:100%; background:#111; border:1px solid #222; color:#eaeaea; padding:10px 12px; border-radius:8px; font-size:14px; }
.form-input:focus { outline:none; border-color:#3a3a3a; }
.form-row { display:flex; gap:10px; }
.form-row .form-group { flex:1; }
.btn { padding:10px 18px; border:none; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
.btn-primary { background:#3b82f6; color:#fff; width:100%; margin-top:4px; }
.btn-primary:hover { background:#2563eb; }
.btn-sm { padding:6px 12px; font-size:12px; }
.btn-danger { background:#2a1414; color:#ff6b6b; border:1px solid #3a1515; }
.btn-toggle { background:#1a2a1a; color:#6bff8f; border:1px solid #153a1f; }
.btn-toggle.off { background:#2a1a1a; color:#ff6b6b; border:1px solid #3a1515; }
.node-card { background:#111; border:1px solid #1e1e1e; border-radius:10px; padding:16px; margin-bottom:12px; }
.node-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; }
.node-name { font-size:15px; font-weight:600; }
.node-badge { font-size:11px; padding:3px 8px; border-radius:4px; font-weight:600; }
.badge-active { background:#0f2a1a; color:#6bff8f; }
.badge-inactive { background:#2a1414; color:#ff6b6b; }
.node-meta { font-size:12px; color:#666; display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; font-family:monospace; }
.node-actions { display:flex; gap:8px; }
.auth-section { display:none; }
.auth-section.show { display:block; }
.empty { color:#555; font-size:14px; text-align:center; padding:30px; }
textarea.form-input { min-height:100px; font-family:monospace; font-size:12px; resize:vertical; }
.test-result { margin-top:8px; font-size:12px; padding:8px 12px; border-radius:6px; display:none; }
.test-ok { background:#0f2a1a; color:#6bff8f; border:1px solid #153a1f; }
.test-fail { background:#1e0f0f; color:#ff6b6b; border:1px solid #3a1515; }
</style>
</head>
<body>
<header>
    <div class="brand">NexHost <span style="color:#888;font-weight:400;">Admin</span></div>
    <a href="/admin.php" class="back-link">← Admin Panel</a>
</header>
<div class="container">
    <h1>🗄️ Database Node Manager</h1>
    <p class="sub">Kelola VPS node untuk provisioning database user</p>

    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card">
        <h2>➕ Tambah Node Baru</h2>
        <form method="POST" action="/database_node.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add_node">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nama Node</label>
                    <input type="text" name="name" class="form-input" placeholder="DB Node SG-1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">IP VPS</label>
                    <input type="text" name="ip" class="form-input" placeholder="1.2.3.4" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">SSH Port</label>
                    <input type="number" name="ssh_port" class="form-input" value="22" min="1" max="65535">
                </div>
                <div class="form-group">
                    <label class="form-label">SSH User</label>
                    <input type="text" name="ssh_user" class="form-input" value="root" placeholder="root / ubuntu">
                </div>
                <div class="form-group">
                    <label class="form-label">Max DB</label>
                    <input type="number" name="max_db" class="form-input" value="50" min="1">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Metode Auth</label>
                <select name="auth_type" class="form-input" onchange="toggleAuth(this.value)">
                    <option value="password">Password</option>
                    <option value="ssh_key">SSH Key</option>
                </select>
            </div>

            <div class="auth-section show" id="authPassword">
                <div class="form-group">
                    <label class="form-label">SSH Password</label>
                    <input type="password" name="ssh_pass" class="form-input" placeholder="Password SSH">
                </div>
            </div>

            <div class="auth-section" id="authKey">
                <div class="form-group">
                    <label class="form-label">Private Key (PEM)</label>
                    <textarea name="ssh_key" class="form-input" placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----"></textarea>
                </div>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                <input type="checkbox" name="use_sudo" id="useSudo" value="1" style="accent-color:#3b82f6;width:16px;height:16px;">
                <label for="useSudo" style="font-size:13px;color:#888;cursor:pointer;">Gunakan <code style="color:#60a5fa;">sudo</code> (jika user bukan root, misal ubuntu)</label>
            </div>

            <button type="submit" class="btn btn-primary">+ Tambah Node</button>
        </form>
    </div>

    <div class="card">
        <h2>📋 Node Aktif (<?= count($nodes) ?>)</h2>
        <?php if (empty($nodes)): ?>
            <div class="empty">Belum ada node. Tambah node pertama!</div>
        <?php else: foreach ($nodes as $n): ?>
        <div class="node-card">
            <div class="node-header">
                <div>
                    <div class="node-name"><?= htmlspecialchars($n['name']) ?></div>
                </div>
                <span class="node-badge <?= ($n['active'] ?? true) ? 'badge-active' : 'badge-inactive' ?>">
                    <?= ($n['active'] ?? true) ? '● Online' : '● Offline' ?>
                </span>
            </div>
            <div class="node-meta">
                <span>🖥 <?= htmlspecialchars($n['ip']) ?>:<?= $n['ssh_port'] ?></span>
                <span>👤 <?= htmlspecialchars($n['ssh_user']) ?></span>
                <span>🔑 <?= $n['auth_type'] === 'ssh_key' ? 'SSH Key' : 'Password' ?></span>
                <?php if ($n['use_sudo'] ?? false): ?><span>⚡ sudo</span><?php endif; ?>
                <span>📦 Max <?= $n['max_db'] ?> DB</span>
                <span>📅 <?= $n['created_at'] ?></span>
            </div>
            <div class="node-actions">
                <form method="POST" action="/database_node.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="toggle_node">
                    <input type="hidden" name="node_id" value="<?= htmlspecialchars($n['id']) ?>">
                    <button type="submit" class="btn btn-sm <?= ($n['active'] ?? true) ? 'btn-toggle' : 'btn-toggle off' ?>">
                        <?= ($n['active'] ?? true) ? '✓ Aktif' : '✗ Nonaktif' ?>
                    </button>
                </form>
                <button class="btn btn-sm btn-toggle" onclick="testNode('<?= htmlspecialchars($n['id']) ?>', this)">🔌 Test SSH</button>
                <button class="btn btn-sm" style="background:#1a1a2a;color:#60a5fa;border:1px solid #1e2a3a;" onclick="toggleEdit('<?= $n['id'] ?>')">✏️ Edit</button>
                <form method="POST" action="/database_node.php" style="display:inline;" onsubmit="return confirm('Hapus node <?= htmlspecialchars($n['name']) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete_node">
                    <input type="hidden" name="node_id" value="<?= htmlspecialchars($n['id']) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">🗑 Hapus</button>
                </form>
            </div>
            <div class="test-result" id="test_<?= $n['id'] ?>"></div>
            <div id="edit_<?= $n['id'] ?>" style="display:none;margin-top:12px;padding:12px;background:#0f0f0f;border:1px solid #222;border-radius:8px;">
                <form method="POST" action="/database_node.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="edit_node">
                    <input type="hidden" name="node_id" value="<?= htmlspecialchars($n['id']) ?>">
                    <div>
                        <div class="form-label">Nama Node</div>
                        <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($n['name']) ?>" style="width:160px;">
                    </div>
                    <div>
                        <div class="form-label">Max DB</div>
                        <input type="number" name="max_db" class="form-input" value="<?= $n['max_db'] ?>" min="1" style="width:80px;">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" style="margin-bottom:0;">Simpan</button>
                </form>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<script>
function toggleAuth(val) {
    document.getElementById('authPassword').classList.toggle('show', val === 'password');
    document.getElementById('authKey').classList.toggle('show', val === 'ssh_key');
}

function toggleEdit(id) {
    const el = document.getElementById("edit_" + id);
    el.style.display = el.style.display === "none" ? "block" : "none";
}
function testNode(nodeId, btn) {
    const resultEl = document.getElementById('test_' + nodeId);
    btn.disabled = true;
    btn.textContent = '⏳ Testing...';
    resultEl.style.display = 'none';
    resultEl.className = 'test-result';

    fetch('/database_node_test.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'node_id=' + encodeURIComponent(nodeId)
    })
    .then(r => r.json())
    .then(data => {
        resultEl.style.display = 'block';
        resultEl.classList.add(data.success ? 'test-ok' : 'test-fail');
        resultEl.textContent = data.message;
        btn.disabled = false;
        btn.textContent = '🔌 Test SSH';
    })
    .catch(() => {
        resultEl.style.display = 'block';
        resultEl.classList.add('test-fail');
        resultEl.textContent = 'Gagal koneksi ke server.';
        btn.disabled = false;
        btn.textContent = '🔌 Test SSH';
    });
}
</script>
</body>
</html>

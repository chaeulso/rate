<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ptero_api.php';
require_login();

$actor = find_user_by_email($_SESSION['user_email']);
$actor_role = $actor['role'] ?? 'member';

if (!in_array($actor_role, ['admin', 'owner'], true)) {
    http_response_code(403);
    die('Akses ditolak.');
}

$csrf = generate_csrf_token();
$users = get_all_users();
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

$roleOrder = ['owner' => 0, 'admin' => 1, 'premium' => 2, 'member' => 3];
usort($users, function($a, $b) use ($roleOrder) {
    $ra = $roleOrder[$a['role'] ?? 'member'] ?? 99;
    $rb = $roleOrder[$b['role'] ?? 'member'] ?? 99;
    return $ra - $rb;
});
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Role - Admin Panel</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
body { background: #0f0f0f; color: #eaeaea; padding: 16px; }
.container { max-width: 720px; margin: 0 auto; }
h1 { font-size: 20px; margin-bottom: 4px; }
.sub { color: #888; font-size: 13px; margin-bottom: 16px; }
.back { color: #6ba8ff; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 16px; }

.search-box { width: 100%; background: #161616; border: 1px solid #262626; color: #eaeaea; padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
.search-box:focus { outline: none; border-color: #555; }
.search-box::placeholder { color: #666; }
.no-result { display: none; text-align: center; color: #888; font-size: 13px; padding: 30px; }

.msg { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
.msg.error { background: #2a1414; color: #ff6b6b; border: 1px solid #4a1f1f; }
.msg.success { background: #142a1a; color: #6bff8f; border: 1px solid #1f4a2a; }

.user-card { background: #161616; border: 1px solid #262626; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.user-info { font-size: 13px; line-height: 1.5; }
.user-info b { color: #fff; font-size: 14px; }
.user-info .email { color: #888; }

.role-badge { font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; }
.role-badge.owner { background: #2a2414; color: #ffd166; }
.role-badge.admin { background: #2a1424; color: #ff6bd1; }
.role-badge.premium { background: #14242a; color: #6bd1ff; }
.role-badge.member { background: #1a1a1a; color: #999; }

.role-form { display: flex; gap: 6px; align-items: center; }
select { background: #0f0f0f; border: 1px solid #2a2a2a; color: #eaeaea; padding: 7px 10px; border-radius: 6px; font-size: 13px; }
.btn-save { background: #eaeaea; color: #0f0f0f; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-save:hover { background: #fff; }
.locked-note { font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="container">
    <a class="back" href="/admin.php">&larr; Kembali ke Admin Panel</a>
    <h1>Set Role User</h1>
    <p class="sub">Kelola role akses untuk setiap user terdaftar</p>

    <input type="text" id="searchBox" class="search-box" placeholder="Cari berdasarkan email atau username..." oninput="filterUsers()" autocomplete="off">
    <div class="no-result" id="noResult">Tidak ada user yang cocok.</div>

    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php foreach ($users as $u): $currentRole = $u['role'] ?? 'member'; ?>
        <div class="user-card user-item" data-search="<?= htmlspecialchars(strtolower($u['email'] . ' ' . $u['username'])) ?>">
            <div class="user-info">
                <b><?= htmlspecialchars($u['username']) ?></b>
                <span class="role-badge <?= htmlspecialchars($currentRole) ?>"><?= htmlspecialchars($currentRole) ?></span><br>
                <span class="email"><?= htmlspecialchars($u['email']) ?></span>
            </div>

            <?php
            $canEdit = true;
            if ($actor_role === 'admin' && in_array($currentRole, ['owner', 'admin'], true)) {
                $canEdit = false;
            }
            ?>
            <?php if ($canEdit): ?>
            <form class="role-form" method="POST" action="set_role_action.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="email" value="<?= htmlspecialchars($u['email']) ?>">
                <select name="role">
                    <option value="member" <?= $currentRole === 'member' ? 'selected' : '' ?>>Member</option>
                    <option value="premium" <?= $currentRole === 'premium' ? 'selected' : '' ?>>Premium</option>
                    <?php if ($actor_role === 'owner'): ?>
                    <option value="admin" <?= $currentRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="owner" <?= $currentRole === 'owner' ? 'selected' : '' ?>>Owner</option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="btn-save">Simpan</button>
            </form>
            <?php else: ?>
            <span class="locked-note">Tidak dapat diubah</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
function filterUsers() {
    const query = document.getElementById('searchBox').value.toLowerCase().trim();
    const items = document.querySelectorAll('.user-item');
    let visibleCount = 0;

    items.forEach(item => {
        const haystack = item.getAttribute('data-search');
        if (haystack.includes(query)) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });

    document.getElementById('noResult').style.display = visibleCount === 0 ? 'block' : 'none';
}
</script>
</body>
</html>

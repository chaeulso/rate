<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/staff_rating.php';
require_once __DIR__ . '/includes/mask_helper.php';
require_once __DIR__ . '/includes/staff_photo.php';
require_once __DIR__ . '/includes/staff_reply.php';
require_login();

$currentUser = find_user_by_email($_SESSION['user_email']);
$userRole = $currentUser['role'] ?? 'member';
$isAdmin = in_array($userRole, ['owner', 'admin']);
$csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

$error = '';
$success = isset($_GET['success']) ? 'Rating kamu berhasil disimpan. Makasih!' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf'] ?? '';
    $staffId = $_POST['staff_id'] ?? '';
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'Sesi tidak valid, silakan coba lagi.';
    } elseif (!get_staff_by_id($staffId)) {
        $error = 'Staff tidak ditemukan.';
    } elseif (get_rating_by_email_staff($_SESSION['user_email'], $staffId)) {
        $error = 'Kamu sudah pernah kasih rating untuk staff ini, tidak bisa diubah lagi.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Pilih rating bintang 1-5.';
    } elseif (mb_strlen($comment) < 10) {
        $error = 'Komentar wajib diisi, minimal 10 karakter.';
    } else {
        save_or_update_staff_rating($_SESSION['user_email'], $_SESSION['username'], $staffId, $rating, $comment);
        header('Location: /rating_pelayanan.php?success=1');
        exit;
    }
}

function render_reply_tree($nodes, $currentEmail, $isModerator, $depth = 0) {
    if (empty($nodes)) return '';
    $html = '<div class="reply-list" style="margin-left:' . ($depth > 0 ? '20px' : '0') . ';">';
    $total = count($nodes);
    $visibleCount = ($depth === 0) ? $total : min($total, 3);
    $shown = array_slice($nodes, 0, $visibleCount);
    $hidden = array_slice($nodes, $visibleCount);

    foreach ($shown as $n) {
        $html .= render_single_reply($n, $currentEmail, $isModerator, $depth);
    }

    if (!empty($hidden)) {
        $groupId = 'hidden-' . bin2hex(random_bytes(4));
        $html .= '<span class="reply-more-link" onclick="document.getElementById(\'' . $groupId . '\').style.display=\'block\';this.style.display=\'none\';">Lihat ' . count($hidden) . ' balasan lainnya</span>';
        $html .= '<div id="' . $groupId . '" style="display:none;">';
        foreach ($hidden as $n) {
            $html .= render_single_reply($n, $currentEmail, $isModerator, $depth);
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

function render_single_reply($n, $currentEmail, $isModerator, $depth) {
    $rUser = find_user_by_email($n['email']);
    $waMasked = ($rUser && !empty($rUser['wa_number'])) ? mask_wa($rUser['wa_number']) : '-';
    $isOwner = ($n['email'] === $currentEmail);
    $canDelete = $isOwner || $isModerator;
    $canEdit = $isOwner && !$n['deleted'];

    $html = '<div class="reply-item" data-reply-id="' . htmlspecialchars($n['id']) . '">';
    $html .= '<div class="reply-item-head"><span class="reply-item-user">' . htmlspecialchars($n['username']) . '</span></div>';
    if (!$n['deleted']) {
        $html .= '<div class="reply-item-meta">' . htmlspecialchars(mask_email($n['email'])) . ' · WA: ' . htmlspecialchars($waMasked) . ' · ' . format_wib($n['created_at']) . '</div>';
    }
    $html .= '<div class="reply-item-comment" id="replytext-' . htmlspecialchars($n['id']) . '">' . nl2br(htmlspecialchars($n['comment'])) . '</div>';

    if (!$n['deleted']) {
        $html .= '<div class="reply-actions">';
        $html .= '<button type="button" class="reply-toggle-btn" onclick="toggleReplyForm(\'' . htmlspecialchars($n['id']) . '\')">Balas</button>';
        if ($canEdit) {
            $html .= '<button type="button" class="reply-toggle-btn" onclick="startEditReply(\'' . htmlspecialchars($n['id']) . '\')">Edit</button>';
        }
        if ($canDelete) {
            $html .= '<button type="button" class="reply-toggle-btn reply-delete-btn" onclick="deleteReply(\'' . htmlspecialchars($n['id']) . '\')">Hapus</button>';
        }
        $html .= '</div>';

        $html .= '<div class="reply-form-wrap" id="replyform-' . htmlspecialchars($n['id']) . '" style="display:none;">';
        $html .= '<textarea class="reply-textarea" placeholder="Tulis balasan..." data-rating-id="' . htmlspecialchars($n['rating_id']) . '" data-parent-id="' . htmlspecialchars($n['id']) . '"></textarea>';
        $html .= '<button type="button" class="btn btn-primary reply-submit-btn" style="width:auto;padding:8px 14px;font-size:12px;" onclick="submitReply(this)">Kirim</button>';
        $html .= '</div>';
    }

    if (!empty($n['children'])) {
        $html .= render_reply_tree($n['children'], $currentEmail, $isModerator, $depth + 1);
    }

    $html .= '</div>';
    return $html;
}

$staffList = get_staff_list();
$userEmail = $_SESSION['user_email'];
$isFounder = is_founder($userEmail);
$isModerator = $isFounder || $isAdmin;

function format_wib($isoDate) {
    try {
        $dt = new DateTime($isoDate);
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('d M Y, H:i') . ' WIB';
    } catch (Exception $e) {
        return '-';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rating Tim Kami — NexHost</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #000000; --surface: #0a0a0a; --surface-hover: #141414; --border: #1f1f22;
  --border-focus: #3b82f6; --text-main: #fafafa; --text-muted: #a1a1aa;
  --primary: #3b82f6; --primary-hover: #2563eb; --danger: #ef4444; --success: #10b981;
  --warning: #f59e0b; --radius: 12px; --font: 'Inter', system-ui, -apple-system, sans-serif;
}
* { margin:0; padding:0; box-sizing:border-box; font-family:var(--font); }
body { background-color:var(--bg); color:var(--text-main); min-height:100vh; overflow-x:hidden; }
.app-layout { display:flex; min-height:100vh; }
.sidebar { width:260px; background-color:var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; position:fixed; top:0; bottom:0; left:0; z-index:100; transition:transform .3s cubic-bezier(.4,0,.2,1); }
.sidebar-header { height:64px; display:flex; align-items:center; padding:0 24px; border-bottom:1px solid var(--border); }
.brand { font-size:18px; font-weight:700; letter-spacing:-0.5px; color:var(--text-main); }
.sidebar-nav { flex:1; padding:24px 16px; display:flex; flex-direction:column; gap:8px; overflow-y:auto; }
.nav-item { display:flex; align-items:center; gap:12px; padding:10px 16px; border-radius:8px; color:var(--text-muted); text-decoration:none; font-size:14px; font-weight:500; transition:all .2s; }
.nav-item:hover { background-color:var(--surface-hover); color:var(--text-main); }
.nav-item.active { background-color:rgba(59,130,246,.1); color:var(--primary); }
.nav-item svg { width:18px; height:18px; }
.nav-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); padding:8px 16px 4px; opacity:.6; }
.sidebar-footer { padding:16px; border-top:1px solid var(--border); }
.user-mini { display:flex; align-items:center; gap:12px; padding:8px; }
.avatar-mini { width:36px; height:36px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:14px; }
.user-info-mini { display:flex; flex-direction:column; }
.user-info-mini .name { font-size:14px; font-weight:600; color:var(--text-main); }
.user-info-mini .email { font-size:12px; color:var(--text-muted); max-width:150px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.main-wrapper { flex:1; margin-left:260px; display:flex; flex-direction:column; min-height:100vh; }
.mobile-header { display:none; height:60px; background:rgba(10,10,10,.8); backdrop-filter:blur(12px); border-bottom:1px solid var(--border); padding:0 20px; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:90; }
.hamburger { width:38px; height:38px; display:flex; flex-direction:column; justify-content:center; gap:6px; cursor:pointer; padding:8px; background:transparent; border:none; }
.hamburger span { display:block; height:2px; width:100%; background:var(--text-main); border-radius:2px; transition:.3s ease; }
.hamburger.open span:nth-child(1) { transform:translateY(8px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity:0; }
.hamburger.open span:nth-child(3) { transform:translateY(-8px) rotate(-45deg); }
.overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); z-index:95; opacity:0; pointer-events:none; transition:opacity .3s; }
.overlay.show { opacity:1; pointer-events:auto; }
.content { padding:32px 40px; max-width:760px; margin:0 auto; width:100%; }
.page-title { font-size:24px; font-weight:700; margin-bottom:8px; }
.page-desc { color:var(--text-muted); font-size:14px; margin-bottom:24px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:20px; }
.staff-head { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
.staff-avatar { width:52px; height:52px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:20px; flex-shrink:0; }
.staff-name { font-size:17px; font-weight:700; }
.staff-role { font-size:12px; color:var(--text-muted); margin-top:2px; }
.staff-meta { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--text-muted); margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border); }
.staff-meta b { color:var(--text-main); font-weight:600; }
.strength-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
.tag { background:rgba(59,130,246,.1); color:#93c5fd; font-size:11px; padding:4px 10px; border-radius:999px; font-weight:500; }
.rating-summary { display:flex; align-items:center; gap:8px; margin-bottom:16px; font-size:13px; color:var(--text-muted); }
.wa-btn { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); text-decoration:none; margin-bottom:14px; font-weight:500; }
.wa-btn:hover { text-decoration:underline; }
.photo-edit-btn { background:transparent; border:1px solid var(--border); color:var(--text-muted); font-size:11px; padding:3px 10px; border-radius:6px; cursor:pointer; margin-top:4px; }
.photo-edit-btn:hover { color:var(--text-main); border-color:var(--primary); }
.photo-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:200; display:none; align-items:center; justify-content:center; padding:20px; }
.photo-modal-overlay.show { display:flex; }
.photo-modal { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:20px; max-width:420px; width:100%; max-height:90vh; overflow-y:auto; }
.photo-modal h3 { font-size:16px; margin-bottom:14px; }
.photo-modal .crop-area { max-height:320px; margin-bottom:14px; background:#000; }
.photo-modal .crop-area img { max-width:100%; display:block; }
.photo-modal-actions { display:flex; gap:10px; margin-top:14px; }
.photo-modal-actions .btn { flex:1; }
.btn-secondary { background:transparent; border:1px solid var(--border); color:var(--text-main); }
.photo-modal-status { font-size:12px; margin-top:10px; min-height:16px; }
.photo-modal-status.error { color:#fca5a5; }
.photo-modal-status.success { color:#6ee7b7; }
.photo-preview-overlay { position:fixed; inset:0; background:rgba(0,0,0,.92); z-index:250; display:none; align-items:center; justify-content:center; overflow:hidden; touch-action:none; }
.photo-preview-overlay.show { display:flex; }
.photo-preview-overlay img { max-width:90vw; max-height:85vh; touch-action:none; user-select:none; transition:transform .1s ease-out; will-change:transform; }
.photo-preview-close { position:absolute; top:20px; right:20px; width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,.1); border:none; color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:251; }
.photo-preview-name { position:absolute; top:24px; left:24px; color:#fff; font-size:15px; font-weight:600; z-index:251; }
.photo-preview-hint { position:absolute; bottom:20px; left:0; right:0; text-align:center; color:rgba(255,255,255,.5); font-size:12px; }


.rating-summary .avg { color:var(--warning); font-weight:700; font-size:16px; }
.form-group { margin-bottom:16px; }
label { display:block; font-size:13px; color:var(--text-muted); margin-bottom:8px; font-weight:500; }
.form-control { width:100%; background:#000; border:1px solid var(--border); color:var(--text-main); padding:12px 14px; border-radius:8px; font-size:14px; transition:all .2s; resize:vertical; font-family:var(--font); }
.form-control:focus { outline:none; border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.btn { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:8px; font-size:13px; font-weight:500; text-decoration:none; cursor:pointer; transition:all .2s; border:1px solid transparent; }
.btn-primary { background:var(--primary); color:#fff; width:100%; justify-content:center; font-size:14px; padding:12px; border:none; }
.btn-primary:hover { background:var(--primary-hover); }
.alert { padding:14px 16px; border-radius:8px; font-size:13px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
.alert-error { background:rgba(239,68,68,.1); color:#fca5a5; border:1px solid rgba(239,68,68,.2); }
.alert-success { background:rgba(16,185,129,.1); color:#6ee7b7; border:1px solid rgba(16,185,129,.2); }
.star-rating { display:flex; gap:6px; }
.star-btn { background:transparent; border:none; font-size:30px; line-height:1; color:#333; cursor:pointer; padding:0; transition:color .15s; }
.star-btn.active { color:var(--warning); }
.word-hint { font-size:12px; color:var(--text-muted); margin-top:6px; }
.already-rated { font-size:12px; color:var(--success); margin-bottom:12px; }
.rating-list { margin-top:20px; padding-top:16px; border-top:1px solid var(--border); }
.rating-list-title { font-size:13px; font-weight:600; color:var(--text-muted); margin-bottom:10px; }
.rating-list-scroll { max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right:4px; }
.rating-item { background:#000; border:1px solid var(--border); border-radius:8px; padding:12px; }
.rating-item-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
.rating-item-user { font-size:13px; font-weight:600; }
.rating-item-stars { color:var(--warning); font-size:13px; }
.rating-item-meta { font-size:11px; color:var(--text-muted); margin-bottom:6px; }
.rating-item-comment { font-size:13px; color:var(--text-main); line-height:1.5; word-break:break-word; }
.reply-toggle-btn { background:transparent; border:none; color:var(--primary); font-size:12px; cursor:pointer; padding:0; margin-top:6px; margin-right:12px; font-weight:500; }
.reply-toggle-btn:hover { text-decoration:underline; }
.reply-delete-btn { color:var(--danger); }
.reply-actions { margin-top:2px; }
.reply-form-wrap { margin-top:8px; }
.reply-textarea { width:100%; background:#000; border:1px solid var(--border); color:var(--text-main); padding:8px 10px; border-radius:6px; font-size:12px; font-family:var(--font); resize:vertical; min-height:60px; margin-bottom:6px; }
.reply-list { margin-top:10px; border-left:2px solid var(--border); padding-left:12px; }
.reply-item { margin-top:10px; }
.reply-item-head { margin-bottom:2px; }
.reply-item-user { font-size:12px; font-weight:600; }
.reply-item-meta { font-size:10px; color:var(--text-muted); margin-bottom:4px; }
.reply-item-comment { font-size:12px; color:var(--text-main); line-height:1.5; word-break:break-word; }
.reply-more-link { display:block; color:var(--primary); font-size:12px; cursor:pointer; margin-top:6px; font-weight:500; }
.reply-more-link:hover { text-decoration:underline; }

@media (max-width:768px) {
  .sidebar { transform:translateX(-100%); width:280px; }
  .sidebar.open { transform:translateX(0); }
  .main-wrapper { margin-left:0; }
  .mobile-header { display:flex; }
  .content { padding:24px 16px; }
}
</style>
</head>
<body>
<div class="app-layout">
  <div class="overlay" id="overlay" onclick="toggleMenu()"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand" style="display:flex;align-items:center;gap:8px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M5 22.5 C7.8 25.3 10.2 25.3 13 22.5 C14.03 18.75 11.12 11.88 11.5 10 C10.38 15.62 4.6 19.38 5 22.5 Z" fill="#93c5fd"/>
          <path d="M9.5 23.5 C12.65 26.65 15.35 26.65 18.5 23.5 C19.82 17.65 16.98 6.93 17.5 4 C15.93 12.77 9.05 18.62 9.5 23.5 Z" fill="#3b82f6"/>
          <path d="M15.05 22.5 C18.03 25.48 20.57 25.48 23.55 22.5 C24.73 18 21.85 9.75 22.3 7.5 C20.95 14.25 14.62 18.75 15.05 22.5 Z" fill="#1d4ed8"/>
        </svg>
        <span style="font-size:17px;font-weight:800;letter-spacing:-0.5px;color:#fff;">Nex<span style="color:#60a5fa;">Host</span></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <a href="/dashboard.php" class="nav-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Dashboard
      </a>
      <div class="nav-label" style="margin-top:8px;">Feedback</div>
      <a href="/feedback.php" class="nav-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Feedback
      </a>
      <a href="/feedback-list.php" class="nav-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Daftar Feedback
      </a>
      <div class="nav-label" style="margin-top:8px;">Rating</div>
      <a href="/rating_pelayanan.php" class="nav-item active">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        Rate Tim Kami
      </a>
      <?php if ($isAdmin): ?>
      <a href="/admin.php" class="nav-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l8 4v5c0 5.25-3.5 9.25-8 10.5C7.5 18.25 4 14.25 4 9V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>
        Admin Panel
      </a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="user-mini">
        <div class="avatar-mini"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div class="user-info-mini">
          <span class="name"><?= htmlspecialchars($_SESSION['username']) ?></span>
          <span class="email"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
        </div>
      </div>
    </div>
  </aside>
  <main class="main-wrapper">
    <header class="mobile-header">
      <div class="brand" style="display:flex;align-items:center;gap:8px;">
        <svg width="24" height="24" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M5 22.5 C7.8 25.3 10.2 25.3 13 22.5 C14.03 18.75 11.12 11.88 11.5 10 C10.38 15.62 4.6 19.38 5 22.5 Z" fill="#93c5fd"/>
          <path d="M9.5 23.5 C12.65 26.65 15.35 26.65 18.5 23.5 C19.82 17.65 16.98 6.93 17.5 4 C15.93 12.77 9.05 18.62 9.5 23.5 Z" fill="#3b82f6"/>
          <path d="M15.05 22.5 C18.03 25.48 20.57 25.48 23.55 22.5 C24.73 18 21.85 9.75 22.3 7.5 C20.95 14.25 14.62 18.75 15.05 22.5 Z" fill="#1d4ed8"/>
        </svg>
        <span style="font-size:16px;font-weight:800;letter-spacing:-0.5px;color:#fff;">Nex<span style="color:#60a5fa;">Host</span></span>
      </div>
      <button class="hamburger" id="hamburger" onclick="toggleMenu()" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </header>
    <div class="content">
      <h1 class="page-title">Rating Tim Kami</h1>
      <p class="page-desc">Kasih rating & komentar buat tim NexHost yang udah bantu kamu.</p>
      <?php if ($error): ?>
      <div class="alert alert-error"><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="alert alert-success"><span><?= htmlspecialchars($success) ?></span></div>
      <?php endif; ?>

      <?php foreach ($staffList as $staff): 
        $summary = get_staff_rating_summary($staff['id']);
        $existing = get_rating_by_email_staff($userEmail, $staff['id']);
        $tenure = get_tenure_years($staff['joined_at']);
      ?>
      <div class="card">
        <div class="staff-head">
          <?php $photoUrl = get_staff_photo_url($staff['id']); ?>
          <div class="staff-avatar" style="<?= $photoUrl ? 'padding:0;overflow:hidden;cursor:zoom-in;' : '' ?>" <?= $photoUrl ? 'onclick="openPhotoPreview(\'' . htmlspecialchars($photoUrl, ENT_QUOTES) . '\', \'' . htmlspecialchars($staff['name'], ENT_QUOTES) . '\')"' : '' ?>>
            <?php if ($photoUrl): ?>
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($staff['name']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
            <?php else: ?>
            <?= strtoupper(substr($staff['name'], 0, 1)) ?>
            <?php endif; ?>
          </div>
          <div>
            <div class="staff-name"><?= htmlspecialchars($staff['name']) ?></div>
            <div class="staff-role"><?= htmlspecialchars($staff['role']) ?></div>
            <?php if ($isFounder): ?>
            <button type="button" class="photo-edit-btn" onclick="openPhotoModal('<?= htmlspecialchars($staff['id']) ?>', '<?= htmlspecialchars($staff['name']) ?>')">Ubah Foto</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($staff['wa'])): ?>
        <a href="https://wa.me/<?= htmlspecialchars($staff['wa']) ?>" target="_blank" class="wa-btn">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="16" height="16"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          Chat via WhatsApp
        </a>
        <?php endif; ?>
        <div class="staff-meta">
          <span>Umur: <b><?= $staff['age'] !== null ? (int)$staff['age'] . ' tahun' : 'Unknown' ?></b></span>
          <span>Gender: <b><?= htmlspecialchars($staff['gender'] ?? 'Unknown') ?></b></span>
          <span>Lama menjabat: <b class="tenure-live" data-joined="<?= htmlspecialchars($staff['joined_at']) ?>"><?= number_format($tenure, 4) ?> tahun</b></span>
        </div>
        <div class="strength-tags">
          <?php foreach ($staff['strengths'] as $s): ?>
          <span class="tag"><?= htmlspecialchars($s) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="rating-summary">
          <span class="avg">★ <?= $summary['count'] > 0 ? number_format($summary['avg'], 1) : '-' ?></span>
          <span><?= $summary['count'] ?> rating</span>
        </div>

        <?php if ($existing): ?>
        <p class="already-rated">Kamu sudah kasih rating <?= (int)$existing['rating'] ?>★ untuk <?= htmlspecialchars($staff['name']) ?>.</p>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="staff_id" value="<?= htmlspecialchars($staff['id']) ?>">
          <input type="hidden" name="rating" class="rating-input" value="0">
          <div class="form-group">
            <label>Rating</label>
            <div class="star-rating" data-target="rating-<?= htmlspecialchars($staff['id']) ?>">
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" class="star-btn" data-value="<?= $i ?>">★</button>
              <?php endfor; ?>
            </div>
          </div>
          <div class="form-group">
            <label>Komentar (wajib, min. 10 karakter)</label>
            <textarea name="comment" class="form-control" rows="3" required minlength="10" placeholder="Ceritain pengalaman kamu dilayani <?= htmlspecialchars($staff['name']) ?>..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Submit Rating</button>
        </form>
        <?php endif; ?>

        <?php
        $staffRatings = get_ratings_by_staff($staff['id']);
        if (!empty($staffRatings)):
        ?>
        <div class="rating-list">
          <div class="rating-list-title">Daftar Rating (<?= count($staffRatings) ?>)</div>
          <div class="rating-list-scroll">
            <?php foreach (array_reverse($staffRatings) as $r):
              $rUser = find_user_by_email($r['email']);
              $waMasked = ($rUser && !empty($rUser['wa_number'])) ? mask_wa($rUser['wa_number']) : '-';
            ?>
            <div class="rating-item">
              <div class="rating-item-head">
                <span class="rating-item-user"><?= htmlspecialchars($r['username']) ?></span>
                <span class="rating-item-stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
              </div>
              <div class="rating-item-meta">
                <?= htmlspecialchars(mask_email($r['email'])) ?> · WA: <?= htmlspecialchars($waMasked) ?> · <?= format_wib($r['created_at']) ?>
              </div>
              <div class="rating-item-comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
              <button type="button" class="reply-toggle-btn" onclick="toggleReplyForm('root-<?= htmlspecialchars($r['id']) ?>')">Balas</button>
              <div class="reply-form-wrap" id="replyform-root-<?= htmlspecialchars($r['id']) ?>" style="display:none;">
                <textarea class="reply-textarea" placeholder="Tulis balasan..." data-rating-id="<?= htmlspecialchars($r['id']) ?>" data-parent-id=""></textarea>
                <button type="button" class="btn btn-primary reply-submit-btn" style="width:auto;padding:8px 14px;font-size:12px;" onclick="submitReply(this)">Kirim</button>
              </div>
              <?php
              $tree = build_reply_tree($r['id']);
              echo render_reply_tree($tree, $userEmail, $isModerator);
              ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>
<script>
function toggleMenu() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
  document.getElementById('hamburger').classList.toggle('open');
}

// Star rating widget
document.querySelectorAll('.star-rating').forEach(function(widget) {
  var stars = widget.querySelectorAll('.star-btn');
  var form = widget.closest('form');
  var input = form.querySelector('.rating-input');
  stars.forEach(function(star) {
    star.addEventListener('click', function() {
      var val = parseInt(star.getAttribute('data-value'));
      input.value = val;
      stars.forEach(function(s) {
        s.classList.toggle('active', parseInt(s.getAttribute('data-value')) <= val);
      });
    });
  });
});
</script>
<div class="photo-modal-overlay" id="photoModalOverlay">
  <div class="photo-modal">
    <h3>Ubah Foto — <span id="photoModalStaffName"></span></h3>
    <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp" style="margin-bottom:14px;width:100%;color:var(--text-muted);font-size:13px;">
    <div class="crop-area" id="cropArea" style="display:none;">
      <img id="cropImage" src="">
    </div>
    <div class="photo-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closePhotoModal()">Batal</button>
      <button type="button" class="btn btn-primary" id="cropSubmitBtn" onclick="submitCroppedPhoto()" disabled>Simpan Foto</button>
    </div>
    <div class="photo-modal-status" id="photoModalStatus"></div>
  </div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
var currentStaffId = null;
var cropperInstance = null;
var csrfToken = <?= json_encode($csrf) ?>;

function openPhotoModal(staffId, staffName) {
  currentStaffId = staffId;
  document.getElementById('photoModalStaffName').textContent = staffName;
  document.getElementById('photoModalOverlay').classList.add('show');
  document.getElementById('cropArea').style.display = 'none';
  document.getElementById('cropSubmitBtn').disabled = true;
  document.getElementById('photoModalStatus').textContent = '';
  document.getElementById('photoModalStatus').className = 'photo-modal-status';
  document.getElementById('photoFileInput').value = '';
}

function closePhotoModal() {
  document.getElementById('photoModalOverlay').classList.remove('show');
  if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
  currentStaffId = null;
}

document.getElementById('photoFileInput').addEventListener('change', function(e) {
  var file = e.target.files[0];
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    var status = document.getElementById('photoModalStatus');
    status.textContent = 'Ukuran file maksimal 5MB.';
    status.className = 'photo-modal-status error';
    return;
  }
  var reader = new FileReader();
  reader.onload = function(ev) {
    var img = document.getElementById('cropImage');
    img.src = ev.target.result;
    document.getElementById('cropArea').style.display = 'block';
    if (cropperInstance) cropperInstance.destroy();
    cropperInstance = new Cropper(img, {
      aspectRatio: 1,
      viewMode: 1,
      autoCropArea: 1,
    });
    document.getElementById('cropSubmitBtn').disabled = false;
  };
  reader.readAsDataURL(file);
});

function submitCroppedPhoto() {
  if (!cropperInstance || !currentStaffId) return;
  var status = document.getElementById('photoModalStatus');
  var btn = document.getElementById('cropSubmitBtn');
  btn.disabled = true;
  status.textContent = 'Mengunggah...';
  status.className = 'photo-modal-status';

  var canvas = cropperInstance.getCroppedCanvas({ width: 512, height: 512 });
  var dataUrl = canvas.toDataURL('image/jpeg', 0.9);

  var formData = new FormData();
  formData.append('csrf', csrfToken);
  formData.append('staff_id', currentStaffId);
  formData.append('image_data', dataUrl);

  fetch('/upload_staff_photo.php', { method: 'POST', body: formData })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        status.textContent = 'Foto berhasil disimpan. Memuat ulang...';
        status.className = 'photo-modal-status success';
        setTimeout(function() { location.reload(); }, 800);
      } else {
        status.textContent = data.error || 'Gagal mengunggah foto.';
        status.className = 'photo-modal-status error';
        btn.disabled = false;
      }
    })
    .catch(function() {
      status.textContent = 'Terjadi kesalahan jaringan.';
      status.className = 'photo-modal-status error';
      btn.disabled = false;
    });
}
</script>
<div class="photo-preview-overlay" id="photoPreviewOverlay">
  <div class="photo-preview-name" id="photoPreviewName"></div>
  <button type="button" class="photo-preview-close" onclick="closePhotoPreview()">&times;</button>
  <img id="photoPreviewImg" src="" alt="">
  <div class="photo-preview-hint">Cubit untuk zoom &middot; Ketuk X untuk tutup</div>
</div>
<script>
var previewScale = 1;
var previewPanX = 0;
var previewPanY = 0;
var previewLastDist = null;
var previewLastMid = null;

function applyPreviewTransform() {
  var img = document.getElementById('photoPreviewImg');
  img.style.transform = 'translate(' + previewPanX + 'px,' + previewPanY + 'px) scale(' + previewScale + ')';
}

function openPhotoPreview(url, name) {
  previewScale = 1; previewPanX = 0; previewPanY = 0;
  document.getElementById('photoPreviewImg').src = url;
  document.getElementById('photoPreviewName').textContent = name;
  document.getElementById('photoPreviewOverlay').classList.add('show');
  applyPreviewTransform();
}

function closePhotoPreview() {
  document.getElementById('photoPreviewOverlay').classList.remove('show');
}

document.getElementById('photoPreviewOverlay').addEventListener('click', function(e) {
  if (e.target.id === 'photoPreviewOverlay') closePhotoPreview();
});

function touchDist(t1, t2) {
  var dx = t1.clientX - t2.clientX, dy = t1.clientY - t2.clientY;
  return Math.sqrt(dx * dx + dy * dy);
}

var previewImgEl = document.getElementById('photoPreviewImg');

previewImgEl.addEventListener('touchstart', function(e) {
  if (e.touches.length === 2) {
    previewLastDist = touchDist(e.touches[0], e.touches[1]);
  }
}, { passive: true });

previewImgEl.addEventListener('touchmove', function(e) {
  if (e.touches.length === 2) {
    e.preventDefault();
    var dist = touchDist(e.touches[0], e.touches[1]);
    if (previewLastDist) {
      var delta = dist / previewLastDist;
      previewScale = Math.min(Math.max(previewScale * delta, 1), 5);
      applyPreviewTransform();
    }
    previewLastDist = dist;
  }
}, { passive: false });

previewImgEl.addEventListener('touchend', function(e) {
  if (e.touches.length < 2) previewLastDist = null;
});

previewImgEl.addEventListener('wheel', function(e) {
  e.preventDefault();
  var delta = e.deltaY < 0 ? 1.1 : 0.9;
  previewScale = Math.min(Math.max(previewScale * delta, 1), 5);
  applyPreviewTransform();
}, { passive: false });

previewImgEl.addEventListener('dblclick', function() {
  previewScale = previewScale > 1 ? 1 : 2;
  previewPanX = 0; previewPanY = 0;
  applyPreviewTransform();
});
</script>
<div class="photo-preview-overlay" id="photoPreviewOverlay">
  <div class="photo-preview-name" id="photoPreviewName"></div>
  <button type="button" class="photo-preview-close" onclick="closePhotoPreview()">&times;</button>
  <img id="photoPreviewImg" src="" alt="">
  <div class="photo-preview-hint">Cubit untuk zoom &middot; Ketuk X untuk tutup</div>
</div>
<script>
var previewScale = 1;
var previewPanX = 0;
var previewPanY = 0;
var previewLastDist = null;
var previewLastMid = null;

function applyPreviewTransform() {
  var img = document.getElementById('photoPreviewImg');
  img.style.transform = 'translate(' + previewPanX + 'px,' + previewPanY + 'px) scale(' + previewScale + ')';
}

function openPhotoPreview(url, name) {
  previewScale = 1; previewPanX = 0; previewPanY = 0;
  document.getElementById('photoPreviewImg').src = url;
  document.getElementById('photoPreviewName').textContent = name;
  document.getElementById('photoPreviewOverlay').classList.add('show');
  applyPreviewTransform();
}

function closePhotoPreview() {
  document.getElementById('photoPreviewOverlay').classList.remove('show');
}

document.getElementById('photoPreviewOverlay').addEventListener('click', function(e) {
  if (e.target.id === 'photoPreviewOverlay') closePhotoPreview();
});

function touchDist(t1, t2) {
  var dx = t1.clientX - t2.clientX, dy = t1.clientY - t2.clientY;
  return Math.sqrt(dx * dx + dy * dy);
}

var previewImgEl = document.getElementById('photoPreviewImg');

previewImgEl.addEventListener('touchstart', function(e) {
  if (e.touches.length === 2) {
    previewLastDist = touchDist(e.touches[0], e.touches[1]);
  }
}, { passive: true });

previewImgEl.addEventListener('touchmove', function(e) {
  if (e.touches.length === 2) {
    e.preventDefault();
    var dist = touchDist(e.touches[0], e.touches[1]);
    if (previewLastDist) {
      var delta = dist / previewLastDist;
      previewScale = Math.min(Math.max(previewScale * delta, 1), 5);
      applyPreviewTransform();
    }
    previewLastDist = dist;
  }
}, { passive: false });

previewImgEl.addEventListener('touchend', function(e) {
  if (e.touches.length < 2) previewLastDist = null;
});

previewImgEl.addEventListener('wheel', function(e) {
  e.preventDefault();
  var delta = e.deltaY < 0 ? 1.1 : 0.9;
  previewScale = Math.min(Math.max(previewScale * delta, 1), 5);
  applyPreviewTransform();
}, { passive: false });

previewImgEl.addEventListener('dblclick', function() {
  previewScale = previewScale > 1 ? 1 : 2;
  previewPanX = 0; previewPanY = 0;
  applyPreviewTransform();
});
</script>
<script>
function toggleReplyForm(id) {
  var el = document.getElementById('replyform-' + id);
  if (!el) return;
  el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
}

function submitReply(btn) {
  var wrap = btn.closest('.reply-form-wrap');
  var textarea = wrap.querySelector('.reply-textarea');
  var comment = textarea.value.trim();
  if (comment.length < 1) { alert('Komentar tidak boleh kosong.'); return; }

  var ratingId = textarea.getAttribute('data-rating-id');
  var parentId = textarea.getAttribute('data-parent-id');

  btn.disabled = true;
  var formData = new FormData();
  formData.append('csrf', csrfToken);
  formData.append('rating_id', ratingId);
  formData.append('parent_id', parentId || '');
  formData.append('comment', comment);

  fetch('/reply_staff_rating.php', { method: 'POST', body: formData })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        location.reload();
      } else {
        alert(data.error || 'Gagal mengirim balasan.');
        btn.disabled = false;
      }
    })
    .catch(function() {
      alert('Terjadi kesalahan jaringan.');
      btn.disabled = false;
    });
}

function startEditReply(replyId) {
  var textEl = document.getElementById('replytext-' + replyId);
  var current = textEl.textContent.trim();
  var newComment = prompt('Edit balasan:', current);
  if (newComment === null) return;
  newComment = newComment.trim();
  if (newComment.length < 1) { alert('Komentar tidak boleh kosong.'); return; }

  var formData = new FormData();
  formData.append('csrf', csrfToken);
  formData.append('reply_id', replyId);
  formData.append('comment', newComment);

  fetch('/edit_staff_reply.php', { method: 'POST', body: formData })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        location.reload();
      } else {
        alert(data.error || 'Gagal mengedit balasan.');
      }
    })
    .catch(function() {
      alert('Terjadi kesalahan jaringan.');
    });
}

function deleteReply(replyId) {
  if (!confirm('Yakin mau hapus balasan ini?')) return;

  var formData = new FormData();
  formData.append('csrf', csrfToken);
  formData.append('reply_id', replyId);

  fetch('/delete_staff_reply.php', { method: 'POST', body: formData })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        location.reload();
      } else {
        alert(data.error || 'Gagal menghapus balasan.');
      }
    })
    .catch(function() {
      alert('Terjadi kesalahan jaringan.');
    });
}
</script>
</body>
</html>

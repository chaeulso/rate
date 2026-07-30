<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$dataFile = __DIR__ . '/data/users.json';
$users = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
$progressFile = __DIR__ . '/data/broadcast_progress.json';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Hapus log broadcast
if (isset($_GET['clear_log'])) {
    if (!empty($_GET['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'])) {
        @unlink($progressFile);
    }
    header('Location: /broadcast.php');
    exit;
}

// AJAX endpoint untuk polling progress
if (isset($_GET['check_progress'])) {
    header('Content-Type: application/json');
    if (file_exists($progressFile)) {
        echo file_get_contents($progressFile);
    } else {
        echo json_encode(['current' => 0, 'total' => 0, 'done' => true, 'not_started' => true]);
    }
    exit;
}

$startResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_broadcast') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $startResult = ['error' => 'CSRF token tidak valid.'];
    } else {
        $text = trim($_POST['broadcast_text'] ?? '');
        if ($text === '') {
            $startResult = ['error' => 'Teks broadcast tidak boleh kosong.'];
        } else {
            // Reset progress file dulu
            @unlink($progressFile);

            // Jalankan worker di background, tidak blocking request ini
            $escapedText = escapeshellarg($text);
            $cmd = "nohup php " . escapeshellarg(__DIR__ . '/broadcast_worker.php') . " {$escapedText} > /tmp/broadcast_worker.log 2>&1 &";
            shell_exec($cmd);

            $startResult = ['started' => true];
        }
    }
}

$total_users_all = count($users);
$total_users_with_wa = 0;
$total_users_without_wa = 0;
foreach ($users as $u) {
    $wa_clean = preg_replace('/[^0-9]/', '', $u['wa_number'] ?? '');
    if ($wa_clean && strlen($wa_clean) >= 10) {
        $total_users_with_wa++;
    } else {
        $total_users_without_wa++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Broadcast WA - NexHost Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: #0a0a0a;
    color: #e2e8f0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    padding: 20px;
}
.container { max-width: 700px; margin: 0 auto; }
h1 { font-size: 1.4rem; margin-bottom: 8px; }
.subtitle { color: #a1a1aa; font-size: 0.9rem; margin-bottom: 24px; }
.card {
    background: #111318;
    border: 1px solid #27272a;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}
label { display: block; font-size: 0.85rem; color: #a1a1aa; margin-bottom: 8px; }
textarea {
    width: 100%;
    min-height: 160px;
    background: #000;
    border: 1px solid #3f3f46;
    border-radius: 8px;
    color: #e2e8f0;
    padding: 12px;
    font-size: 0.95rem;
    font-family: inherit;
    resize: vertical;
}
.info-badge {
    display: inline-block;
    background: rgba(167,139,250,0.1);
    color: #a78bfa;
    border: 1px solid rgba(167,139,250,0.2);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 0.8rem;
    margin-bottom: 16px;
}
.info-badge-svg {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.82rem;
    font-weight: 500;
}
.info-badge-svg svg { flex-shrink: 0; }
.btn {
    width: 100%;
    background: linear-gradient(135deg, #7c3aed, #3b82f6);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 12px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 12px;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.warning {
    background: rgba(251,191,36,0.08);
    border: 1px solid rgba(251,191,36,0.2);
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 0.82rem;
    color: #fbbf24;
    margin-bottom: 16px;
    line-height: 1.5;
}
.progress-bar-wrap {
    background: #000;
    border-radius: 8px;
    height: 24px;
    overflow: hidden;
    margin: 12px 0;
    border: 1px solid #27272a;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(135deg, #7c3aed, #3b82f6);
    width: 0%;
    transition: width 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
}
.result-box {
    background: #0a0a0a;
    border: 1px solid #27272a;
    border-radius: 8px;
    padding: 14px;
    margin-top: 16px;
    font-size: 0.85rem;
    max-height: 300px;
    overflow-y: auto;
}
.result-success { color: #4ade80; }
.result-failed { color: #f87171; }
a { color: #60a5fa; text-decoration: none; }
</style>
</head>
<body>
<div class="container">
    <a href="/admin.php">← Kembali ke Admin</a>
    <h1 style="margin-top:16px;">📢 Broadcast WhatsApp</h1>
    <p class="subtitle">Kirim pesan ke semua user terdaftar via WhatsApp Cloud API resmi (Meta)</p>

    <div class="card">
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <span class="info-badge-svg" style="border-color:rgba(74,222,128,0.25);background:rgba(74,222,128,0.08);color:#4ade80;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?= $total_users_with_wa ?> user punya nomor WA terdaftar
            </span>
            <span class="info-badge-svg" style="border-color:rgba(248,113,113,0.25);background:rgba(248,113,113,0.08);color:#f87171;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <?= $total_users_without_wa ?> user belum daftar nomor WA
            </span>
            <span class="info-badge-svg" style="border-color:rgba(167,139,250,0.25);background:rgba(167,139,250,0.08);color:#a78bfa;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?= $total_users_all ?> total user terdaftar
            </span>
        </div>

        <div class="warning">
            ⚠️ Meski pakai WA Cloud API resmi, Meta tetap membatasi pengiriman pesan teks bebas
            hanya untuk user yang <strong>sudah chat bot dalam 24 jam terakhir</strong> (customer service window).
            Broadcast berjalan di background dengan jeda 1 detik antar nomor (estimasi <?= ceil($total_users_with_wa * 1 / 60) ?> menit total)
            untuk menjaga kualitas nomor dan mencegah rate-limit dari Meta.
        </div>

        <form method="POST" id="broadcastForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="send_broadcast">
            <label>Isi Pesan Broadcast</label>
            <textarea name="broadcast_text" id="broadcastText" placeholder="Tulis pesan broadcast di sini..." required></textarea>
            <button type="submit" class="btn" id="sendBtn" onclick="return confirm('Yakin mau kirim broadcast ke semua user terdaftar? Proses akan berjalan di background selama beberapa menit.')">Kirim Broadcast Sekarang</button>
        </form>
    </div>

    <?php if ($startResult && isset($startResult['error'])): ?>
    <div class="card">
        <p class="result-failed">❌ <?= htmlspecialchars($startResult['error']) ?></p>
    </div>
    <?php endif; ?>

    <div class="card" id="progressCard" style="display:none;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <p id="progressLabel" style="margin:0;">Memulai broadcast...</p>
            <a href="/broadcast.php?clear_log=1&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" id="clearLogBtn" style="display:none;font-size:0.8rem;color:#f87171;white-space:nowrap;margin-left:12px;" onclick="return confirm('Hapus log broadcast ini?')">🗑️ Bersihkan Log</a>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progressFill">0%</div>
        </div>
        <div class="result-box" id="resultBox"></div>
    </div>
</div>

<script>
<?php if ($startResult && isset($startResult['started'])): ?>
document.getElementById('progressCard').style.display = 'block';
document.getElementById('sendBtn').disabled = true;
pollProgress();
<?php endif; ?>

// FIX: kalau halaman di-refresh saat broadcast masih berjalan di background,
// otomatis lanjut nampilin progress (bukan hilang/reset kayak sebelumnya).
window.addEventListener('DOMContentLoaded', () => {
    fetch('/broadcast.php?check_progress=1')
        .then(r => r.json())
        .then(data => {
            if (!data.not_started && data.total > 0) {
                document.getElementById('progressCard').style.display = 'block';
                if (!data.done) {
                    document.getElementById('sendBtn').disabled = true;
                    pollProgress();
                } else {
                    // Sudah selesai sebelumnya, tampilkan hasil akhir tanpa polling terus-terusan
                    const pct = 100;
                    document.getElementById('progressFill').style.width = pct + '%';
                    document.getElementById('progressFill').textContent = pct + '%';
                    document.getElementById('progressLabel').textContent =
                        'Mengirim: ' + data.current + ' / ' + data.total +
                        ' (✅ ' + data.success_count + ' berhasil, ❌ ' + data.failed_count + ' gagal) — SELESAI';
                    document.getElementById('clearLogBtn').style.display = 'inline';

                    const resultBox = document.getElementById('resultBox');
                    let html = '';
                    (data.success_list || []).forEach(s => html += '<div class="result-success">✅ ' + s + '</div>');
                    (data.failed_list || []).forEach(f => html += '<div class="result-failed">❌ ' + f + '</div>');
                    resultBox.innerHTML = html;
                }
            }
        })
        .catch(() => {});
});

function pollProgress() {
    fetch('/broadcast.php?check_progress=1')
        .then(r => r.json())
        .then(data => {
            if (data.not_started) {
                setTimeout(pollProgress, 1000);
                return;
            }
            const pct = data.total > 0 ? Math.round((data.current / data.total) * 100) : 0;
            document.getElementById('progressFill').style.width = pct + '%';
            document.getElementById('progressFill').textContent = pct + '%';
            document.getElementById('progressLabel').textContent =
                'Mengirim: ' + data.current + ' / ' + data.total +
                ' (✅ ' + data.success_count + ' berhasil, ❌ ' + data.failed_count + ' gagal)';

            const resultBox = document.getElementById('resultBox');
            let html = '';
            (data.success_list || []).forEach(s => html += '<div class="result-success">✅ ' + s + '</div>');
            (data.failed_list || []).forEach(f => html += '<div class="result-failed">❌ ' + f + '</div>');
            resultBox.innerHTML = html;
            resultBox.scrollTop = resultBox.scrollHeight;

            if (!data.done) {
                setTimeout(pollProgress, 1500);
            } else {
                document.getElementById('progressLabel').textContent += ' — SELESAI';
                document.getElementById('sendBtn').disabled = false;
                document.getElementById('clearLogBtn').style.display = 'inline';
            }
        })
        .catch(() => setTimeout(pollProgress, 2000));
}
</script>
</body>
</html>

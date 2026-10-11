<?php
// Sign in, sign out, who am I, change my password.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? 'me';

if ($action === 'me') {
    start_session();
    migrate();
    $hasAdmin = (int)db()->query("SELECT COUNT(*) FROM fss_users WHERE role = 'admin'")->fetchColumn() > 0;
    json_out(['ok' => true, 'user' => current_user(), 'setup_needed' => !$hasAdmin]);
}

require_ajax();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
$b = body();

if ($action === 'login') {
    start_session();
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 64);
    $user = strtolower(trim((string)($b['username'] ?? '')));
    $pass = (string)($b['password'] ?? '');
    $since = time() - 15 * 60;
    $st = db()->prepare('SELECT COUNT(*) FROM fss_login_attempts WHERE (ip = ? OR username = ?) AND at_ts > ?');
    $st->execute([$ip, $user, $since]);
    if ((int)$st->fetchColumn() >= 8) fail(429, 'Too many tries. Wait 15 minutes and try again.');
    $st = db()->prepare('SELECT id, pass_hash FROM fss_users WHERE username = ?');
    $st->execute([$user]);
    $row = $st->fetch();
    if (!$row || !password_verify($pass, $row['pass_hash'])) {
        db()->prepare('INSERT INTO fss_login_attempts (ip, username, at_ts) VALUES (?, ?, ?)')->execute([$ip, $user, time()]);
        fail(401, 'Wrong username or password.');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    db()->prepare('DELETE FROM fss_login_attempts WHERE username = ?')->execute([$user]);
    json_out(['ok' => true, 'user' => current_user()]);
}

if ($action === 'logout') {
    start_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

if ($action === 'password') {
    $u = require_user();
    $st = db()->prepare('SELECT pass_hash FROM fss_users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), (string)$st->fetchColumn())) fail(400, 'Your current password is not right.');
    $new = check_password((string)($b['new'] ?? ''));
    db()->prepare('UPDATE fss_users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    json_out(['ok' => true]);
}

// First run only: create the family admin account. Disabled once an admin exists.
if ($action === 'setup') {
    migrate();
    $has = (int)db()->query("SELECT COUNT(*) FROM fss_users WHERE role = 'admin'")->fetchColumn();
    if ($has > 0) fail(403, 'Setup is already done.');
    $username = clean_username((string)($b['username'] ?? ''));
    $name = trim((string)($b['display_name'] ?? '')) ?: $username;
    $pass = check_password((string)($b['password'] ?? ''));
    db()->prepare('INSERT INTO fss_users (username, display_name, pass_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$username, mb_substr($name, 0, 60), password_hash($pass, PASSWORD_DEFAULT), 'admin', now_iso()]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)db()->lastInsertId();
    json_out(['ok' => true, 'user' => current_user()]);
}

fail(404, 'Unknown action.');

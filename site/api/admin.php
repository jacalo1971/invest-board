<?php
// Family admin: list, add, reset password, remove family members.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$admin = require_admin();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $rows = db()->query('SELECT id, username, display_name, role, created_at FROM fss_users ORDER BY role, display_name')->fetchAll();
    json_out(['ok' => true, 'users' => $rows]);
}

require_ajax();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
$b = body();

if ($action === 'add') {
    $username = clean_username((string)($b['username'] ?? ''));
    $name = trim((string)($b['display_name'] ?? '')) ?: $username;
    $pass = check_password((string)($b['password'] ?? ''));
    $role = ($b['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    $st = db()->prepare('SELECT COUNT(*) FROM fss_users WHERE username = ?');
    $st->execute([$username]);
    if ((int)$st->fetchColumn() > 0) fail(400, 'That username is taken.');
    db()->prepare('INSERT INTO fss_users (username, display_name, pass_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$username, mb_substr($name, 0, 60), password_hash($pass, PASSWORD_DEFAULT), $role, now_iso()]);
    json_out(['ok' => true]);
}

if ($action === 'reset') {
    $id = (int)($b['id'] ?? 0);
    $pass = check_password((string)($b['password'] ?? ''));
    db()->prepare('UPDATE fss_users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    json_out(['ok' => true]);
}

if ($action === 'remove') {
    $id = (int)($b['id'] ?? 0);
    if ($id === (int)$admin['id']) fail(400, 'You cannot remove your own account.');
    db()->prepare('DELETE FROM fss_holdings WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM fss_questions WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM fss_users WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

fail(404, 'Unknown action.');

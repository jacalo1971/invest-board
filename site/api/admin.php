<?php
// Family admin: list, add, reset password, remove family members, daily email settings.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$admin = require_admin();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    migrate();
    $rows = db()->query('SELECT u.id, u.username, u.display_name, u.role, u.created_at, n.email, n.enabled, n.last_sent FROM fss_users u LEFT JOIN fss_newsletter n ON n.user_id = u.id ORDER BY u.role, u.display_name')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['enabled'] = (int)($r['enabled'] ?? 0) === 1; }
    unset($r);
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

// Change someone's type: admin or family member.
if ($action === 'role') {
    $id = (int)($b['id'] ?? 0);
    $role = ($b['role'] ?? '') === 'admin' ? 'admin' : 'member';
    if ($id === (int)$admin['id'] && $role !== 'admin') fail(400, 'You cannot remove your own admin access. Ask another admin to do it.');
    $st = db()->prepare('SELECT COUNT(*) FROM fss_users WHERE id = ?'); $st->execute([$id]);
    if ((int)$st->fetchColumn() === 0) fail(404, 'That person was not found.');
    db()->prepare('UPDATE fss_users SET role = ? WHERE id = ?')->execute([$role, $id]);
    json_out(['ok' => true]);
}

// Daily email: save someone's address and whether they get it.
if ($action === 'newsletter') {
    $id = (int)($b['id'] ?? 0);
    $email = trim((string)($b['email'] ?? ''));
    $on = !empty($b['enabled']);
    $st = db()->prepare('SELECT COUNT(*) FROM fss_users WHERE id = ?'); $st->execute([$id]);
    if ((int)$st->fetchColumn() === 0) fail(404, 'That person was not found.');
    if ($email === '') {
        if ($on) fail(400, 'Type an email address first.');
        db()->prepare('DELETE FROM fss_newsletter WHERE user_id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) fail(400, 'That email address does not look right.');
    $st = db()->prepare('SELECT token FROM fss_newsletter WHERE user_id = ?'); $st->execute([$id]);
    $tok = $st->fetchColumn();
    if ($tok) db()->prepare('UPDATE fss_newsletter SET email = ?, enabled = ? WHERE user_id = ?')->execute([$email, $on ? 1 : 0, $id]);
    else { require __DIR__ . '/mailer.php'; db()->prepare('INSERT INTO fss_newsletter (user_id, email, enabled, token) VALUES (?, ?, ?, ?)')->execute([$id, $email, $on ? 1 : 0, nl_token()]); }
    json_out(['ok' => true]);
}

// Send today's email to one person right now, to preview it.
if ($action === 'test') {
    require __DIR__ . '/mailer.php';
    $id = (int)($b['id'] ?? 0);
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, n.email, n.token FROM fss_users u JOIN fss_newsletter n ON n.user_id = u.id WHERE u.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) fail(400, 'Save an email address for this person first.');
    if (!nl_send($r, $r['email'], $r['token'])) fail(502, 'The server could not send the email. Check the sender address in cPanel.');
    json_out(['ok' => true, 'sent_to' => $r['email']]);
}

if ($action === 'remove') {
    $id = (int)($b['id'] ?? 0);
    if ($id === (int)$admin['id']) fail(400, 'You cannot remove your own account.');
    db()->prepare('DELETE FROM fss_holdings WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM fss_questions WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM fss_newsletter WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM fss_users WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

fail(404, 'Unknown action.');

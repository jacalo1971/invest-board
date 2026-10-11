<?php
// My Stocks: each person sees and edits only their own holdings.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$u = require_user();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    json_out(['ok' => true, 'holdings' => user_holdings((int)$u['id'])]);
}

require_ajax();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
$b = body();

if ($action === 'add' || $action === 'update') {
    $sym = clean_symbol((string)($b['symbol'] ?? ''));
    $shares = (float)($b['shares'] ?? 0);
    $cost = (float)($b['avg_cost'] ?? 0);
    if ($shares <= 0 || $shares > 1e7) fail(400, 'Shares must be more than 0 (fractions like 0.25 are fine).');
    if ($cost <= 0 || $cost > 1e7) fail(400, 'Enter the price you paid per share.');
    $date = (string)($b['bought_on'] ?? '');
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
    $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 140);
    if ($action === 'add') {
        $n = db()->prepare('SELECT COUNT(*) FROM fss_holdings WHERE user_id = ?');
        $n->execute([$u['id']]);
        if ((int)$n->fetchColumn() >= 60) fail(400, 'That is a lot of stocks! The limit is 60.');
        db()->prepare('INSERT INTO fss_holdings (user_id, symbol, shares, avg_cost, bought_on, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$u['id'], $sym, $shares, $cost, $date ?: null, $note ?: null, now_iso()]);
    } else {
        db()->prepare('UPDATE fss_holdings SET symbol = ?, shares = ?, avg_cost = ?, bought_on = ?, note = ? WHERE id = ? AND user_id = ?')
            ->execute([$sym, $shares, $cost, $date ?: null, $note ?: null, (int)($b['id'] ?? 0), $u['id']]);
    }
    json_out(['ok' => true]);
}

if ($action === 'remove') {
    db()->prepare('DELETE FROM fss_holdings WHERE id = ? AND user_id = ?')->execute([(int)($b['id'] ?? 0), $u['id']]);
    json_out(['ok' => true]);
}

fail(404, 'Unknown action.');

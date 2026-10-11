<?php
// My Stocks: each person sees and edits only their own holdings.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$u = require_user();
$action = $_GET['action'] ?? 'list';

function load_watchlist(): array {
    static $w = null;
    if ($w !== null) return $w;
    $w = [];
    $rows = http_get_json(cfg()['data_base'] . 'watchlist.json?v=' . intdiv(time(), 300)) ?? [];
    foreach ($rows as $r) if (isset($r['symbol'])) $w[$r['symbol']] = $r;
    return $w;
}

// Price for any ticker: watchlist first, then FMP (cached 6 hours).
function quote(string $sym): array {
    $w = load_watchlist();
    if (isset($w[$sym])) {
        $r = $w[$sym];
        return ['name' => $r['name'], 'price' => (float)$r['price'], 'chg_pct' => (float)$r['chg_pct'], 'risk_score' => (float)$r['risk_score'], 'source' => 'watchlist'];
    }
    $st = db()->prepare('SELECT * FROM fss_prices WHERE symbol = ?');
    $st->execute([$sym]);
    $c = $st->fetch();
    $fresh = $c && (strtotime($c['fetched_at']) > time() - 6 * 3600);
    if (!$fresh && !empty(cfg()['fmp_key'])) {
        $j = http_get_json('https://financialmodelingprep.com/stable/profile?symbol=' . urlencode($sym) . '&apikey=' . urlencode(cfg()['fmp_key']));
        if ($j && isset($j[0]['price'])) {
            $p = $j[0];
            [$lo, $hi] = array_map('floatval', array_pad(explode('-', (string)($p['range'] ?? '')), 2, 0));
            $row = [$sym, mb_substr((string)($p['companyName'] ?? $sym), 0, 120), (float)$p['price'], (float)($p['changePercentage'] ?? 0), isset($p['beta']) ? (float)$p['beta'] : null, $lo ?: null, $hi ?: null, now_iso()];
            db()->prepare('DELETE FROM fss_prices WHERE symbol = ?')->execute([$sym]);
            db()->prepare('INSERT INTO fss_prices (symbol, name, price, chg_pct, beta, low52, high52, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($row);
            $st->execute([$sym]);
            $c = $st->fetch();
        }
    }
    if (!$c) return ['name' => $sym, 'price' => null, 'chg_pct' => null, 'risk_score' => null, 'source' => 'none'];
    $price = $c['price'] !== null ? (float)$c['price'] : null;
    return [
        'name' => $c['name'] ?: $sym, 'price' => $price, 'chg_pct' => $c['chg_pct'] !== null ? (float)$c['chg_pct'] : null,
        'risk_score' => risk_score($c['beta'] !== null ? (float)$c['beta'] : null, $price, $c['low52'] !== null ? (float)$c['low52'] : null, $c['high52'] !== null ? (float)$c['high52'] : null),
        'source' => 'fmp',
    ];
}

if ($action === 'list') {
    $st = db()->prepare('SELECT id, symbol, shares, avg_cost, bought_on, note FROM fss_holdings WHERE user_id = ? ORDER BY symbol, id');
    $st->execute([$u['id']]);
    $out = [];
    foreach ($st->fetchAll() as $h) {
        $q = quote($h['symbol']);
        $shares = (float)$h['shares']; $cost = (float)$h['avg_cost'];
        $value = $q['price'] !== null ? $shares * $q['price'] : null;
        $out[] = [
            'id' => (int)$h['id'], 'symbol' => $h['symbol'], 'name' => $q['name'], 'shares' => $shares, 'avg_cost' => $cost,
            'bought_on' => $h['bought_on'], 'note' => $h['note'], 'price' => $q['price'], 'chg_pct' => $q['chg_pct'],
            'risk_score' => $q['risk_score'], 'value' => $value, 'invested' => $shares * $cost,
            'gain' => $value !== null ? $value - $shares * $cost : null,
            'gain_pct' => ($value !== null && $cost > 0) ? ($value / ($shares * $cost) - 1) * 100 : null,
        ];
    }
    json_out(['ok' => true, 'holdings' => $out]);
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

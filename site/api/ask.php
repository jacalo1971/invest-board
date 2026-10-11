<?php
// Ask a question: an educational money coach for the family, powered by Claude.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$u = require_user();
$action = $_GET['action'] ?? 'history';
$limit = (int)(cfg()['questions_per_day'] ?? 20);

function used_today(int $uid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM fss_questions WHERE user_id = ? AND created_at >= ?');
    $st->execute([$uid, gmdate('Y-m-d') . 'T00:00:00Z']);
    return (int)$st->fetchColumn();
}

if ($action === 'history') {
    $st = db()->prepare('SELECT id, question, answer, created_at FROM fss_questions WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $st->execute([$u['id']]);
    json_out(['ok' => true, 'items' => $st->fetchAll(), 'left_today' => max(0, $limit - used_today((int)$u['id']))]);
}

require_ajax();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
if ($action !== 'ask') fail(404, 'Unknown action.');

$q = trim((string)(body()['question'] ?? ''));
if ($q === '') fail(400, 'Type a question first.');
if (mb_strlen($q) > 600) fail(400, 'Please keep questions under 600 characters.');
if (used_today((int)$u['id']) >= $limit) fail(429, "You've used today's $limit questions. Come back tomorrow!");
$key = (string)(cfg()['anthropic_key'] ?? '');
if ($key === '' || str_starts_with($key, 'PASTE')) fail(500, 'The question box is not set up yet (missing API key).');

// Context: today's watchlist and this person's own stocks.
$wl = http_get_json(cfg()['data_base'] . 'watchlist.json?v=' . intdiv(time(), 300)) ?? [];
$wlText = implode("\n", array_map(fn($r) => sprintf('%s (%s, %s): $%.2f, today %+.2f%%, risk %.1f/10, %.0f%% below 52-week high%s',
    $r['symbol'], $r['name'], $r['group'], $r['price'], $r['chg_pct'], $r['risk_score'], $r['off_high_pct'],
    isset($r['t_cons']) && $r['t_cons'] !== null ? sprintf(', analyst target $%.0f', $r['t_cons']) : ''), $wl));
$st = db()->prepare('SELECT symbol, shares, avg_cost FROM fss_holdings WHERE user_id = ?');
$st->execute([$u['id']]);
$mine = $st->fetchAll();
$mineText = $mine ? implode("\n", array_map(fn($h) => sprintf('%s: %s shares bought at $%.2f each', $h['symbol'], rtrim(rtrim(number_format((float)$h['shares'], 6, '.', ''), '0'), '.'), $h['avg_cost']), $mine)) : 'No stocks entered yet.';

$system = <<<TXT
You are "Coach", the money and investing teacher inside Family Stock School, a website a dad built to learn investing together with his teenage children (around 15 years old). The person asking may be a teenager.

How you answer:
- Teach. Explain ideas in plain, friendly words with short sentences. Define any finance term the first time you use it. Use small examples with their real watchlist or their own stocks when it helps.
- Keep answers short: usually 3 to 8 sentences, or a few short bullets. Never use em dashes or en dashes.
- Answer in the language the question is written in.
- You can explain what a company does, how a stock has moved, what risk, P/E, ETFs, dividends, diversification and similar ideas mean, and how to think about a decision.
- Do NOT tell them to buy, sell or hold any specific stock, and do not predict prices. If they ask "should I buy X?", explain the questions an investor would ask (what the company does, how risky it is, how it fits with what they already own, how long they plan to hold) and suggest talking it through with their parent.
- If they mention money they need soon, debt, borrowing, options, crypto speculation or "getting rich quick", gently explain the risks.
- Stay on money, saving, investing, the economy and the companies on the board. For anything else, kindly say you can only help with money and investing questions.
- Keep everything age-appropriate. If someone seems upset or mentions being in danger, encourage them to talk to their parent or a trusted adult right away.
- The data below comes from the family's board and may be a day old. Say so if timing matters. Do not invent numbers you were not given.

Today's watchlist:
$wlText

This person's own stocks (entered by hand from their Greenlight app):
$mineText
TXT;

// Short memory: the last 3 exchanges.
$st = db()->prepare('SELECT question, answer FROM fss_questions WHERE user_id = ? ORDER BY id DESC LIMIT 3');
$st->execute([$u['id']]);
$messages = [];
foreach (array_reverse($st->fetchAll()) as $p) {
    $messages[] = ['role' => 'user', 'content' => $p['question']];
    $messages[] = ['role' => 'assistant', 'content' => $p['answer']];
}
$messages[] = ['role' => 'user', 'content' => $q];

$payload = json_encode([
    'model' => cfg()['anthropic_model'] ?? 'claude-haiku-5-5',
    'max_tokens' => (int)(cfg()['max_answer_tokens'] ?? 700),
    'system' => $system,
    'messages' => $messages,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = is_string($res) ? json_decode($res, true) : null;
if ($code !== 200 || !is_array($j)) {
    $msg = $code === 401 ? 'The API key is not valid.' : ($code === 429 ? 'Coach is busy right now. Try again in a minute.' : 'Coach could not answer right now. Try again soon.');
    fail(502, $msg);
}
$answer = '';
foreach ($j['content'] ?? [] as $blk) if (($blk['type'] ?? '') === 'text') $answer .= $blk['text'];
$answer = trim($answer) ?: 'Sorry, I did not get that. Could you ask it another way?';

db()->prepare('INSERT INTO fss_questions (user_id, question, answer, created_at) VALUES (?, ?, ?, ?)')->execute([$u['id'], $q, $answer, now_iso()]);
json_out(['ok' => true, 'answer' => $answer, 'left_today' => max(0, $limit - used_today((int)$u['id']))]);

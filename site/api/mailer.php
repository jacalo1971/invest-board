<?php
// Daily email for Stock School: builds one personal email per person and sends it.
// Included by send_newsletter.php (cron) and admin.php (test button). Not reachable from the web.
declare(strict_types=1);
date_default_timezone_set('America/Los_Angeles'); // the family reads it in Pacific time

function nl_site(): string { return rtrim((string)(cfg()['site_url'] ?? 'https://htv.studio/invest/'), '/') . '/'; }
function nl_from(): string { return (string)(cfg()['mail_from'] ?? 'school@htv.studio'); }
function nl_from_name(): string { return (string)(cfg()['mail_from_name'] ?? 'Stock School'); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function money(?float $v): string { return $v === null ? 'n/a' : ($v < 0 ? '-$' : '$') . number_format(abs($v), 2); }
function pct(?float $v): string { return $v === null ? 'n/a' : sprintf('%+.1f%%', $v); }

function nl_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $base = cfg()['data_base'];
    $v = '?v=' . intdiv(time(), 300);
    $d = [
        'briefing' => http_get_json($base . 'briefing.json' . $v) ?? [],
        'news' => http_get_json($base . 'news.json' . $v) ?? [],
        'meta' => http_get_json($base . 'meta.json' . $v) ?? [],
    ];
    return $d;
}

// True when the board was refreshed in the last 20 hours (so we never email stale news).
function nl_data_is_fresh(): bool {
    $t = strtotime((string)(nl_data()['meta']['updated_at'] ?? ''));
    return $t && $t > time() - 20 * 3600;
}

function nl_token(): string { return bin2hex(random_bytes(24)); }

function nl_build(array $user, string $token): array {
    $d = nl_data();
    $first = h(explode(' ', trim($user['display_name']))[0] ?: $user['username']);
    $date = date('l, F j', time());
    $c = ['ink' => '#1f2340', 'soft' => '#5b6080', 'line' => '#e6e3f5', 'brand' => '#5b4bff', 'good' => '#138a52', 'bad' => '#c23b3b', 'bg' => '#f6f4ff'];

    $take = ''; $alerts = '';
    foreach ($d['briefing'] as $b) {
        $k = $b['kind'] ?? '';
        if ($k === 'takeaway') $take = '<p style="font-size:18px;line-height:1.45;margin:0;color:' . $c['ink'] . '"><b>' . h($b['text'] ?? '') . '</b></p>';
        elseif ($k === 'alert') $alerts .= '<tr><td style="padding:10px 0;border-top:1px solid ' . $c['line'] . '"><b style="color:' . $c['ink'] . '">' . h($b['title'] ?? '') . '</b><br><span style="color:' . $c['soft'] . '">' . h($b['text'] ?? '') . '</span></td></tr>';
    }

    $news = '';
    foreach (array_slice($d['news'], 0, 3) as $n) {
        $eff = $n['effect'] ?? 'watch';
        $tag = $eff === 'good' ? ['Good news', $c['good']] : ($eff === 'bad' ? ['Heads up', $c['bad']] : ['Keep an eye on it', $c['brand']]);
        $tick = !empty($n['tickers']) ? ' &middot; ' . h(implode(', ', array_slice($n['tickers'], 0, 5))) : '';
        $news .= '<tr><td style="padding:12px 0;border-top:1px solid ' . $c['line'] . '">'
            . '<span style="font-size:12px;font-weight:bold;color:' . $tag[1] . '">' . $tag[0] . '</span><span style="font-size:12px;color:' . $c['soft'] . '">' . $tick . '</span><br>'
            . '<b style="color:' . $c['ink'] . '">' . h($n['title'] ?? '') . '</b><br>'
            . '<span style="color:' . $c['soft'] . '">' . h($n['summary'] ?? '') . '</span>'
            . (!empty($n['why']) ? '<br><span style="color:' . $c['ink'] . '"><i>Why it matters:</i> ' . h($n['why']) . '</span>' : '')
            . '</td></tr>';
    }

    $hold = user_holdings((int)$user['id']);
    if ($hold) {
        $rows = ''; $tv = 0.0; $ti = 0.0; $complete = true;
        foreach ($hold as $x) {
            $g = $x['gain']; $col = $g === null ? $c['soft'] : ($g >= 0 ? $c['good'] : $c['bad']);
            $tcol = ($x['chg_pct'] ?? 0) >= 0 ? $c['good'] : $c['bad'];
            $rows .= '<tr><td style="padding:8px 6px;border-top:1px solid ' . $c['line'] . '"><b>' . h($x['symbol']) . '</b><br><span style="font-size:12px;color:' . $c['soft'] . '">' . h($x['name']) . '</span></td>'
                . '<td align="right" style="padding:8px 6px;border-top:1px solid ' . $c['line'] . '">' . money($x['value']) . '</td>'
                . '<td align="right" style="padding:8px 6px;border-top:1px solid ' . $c['line'] . ';color:' . $tcol . '">' . pct($x['chg_pct']) . '</td>'
                . '<td align="right" style="padding:8px 6px;border-top:1px solid ' . $c['line'] . ';color:' . $col . '">' . money($g) . '<br><span style="font-size:12px">' . pct($x['gain_pct']) . '</span></td></tr>';
            if ($x['value'] === null) $complete = false; else { $tv += $x['value']; $ti += $x['invested']; }
        }
        $tg = $tv - $ti;
        $sum = $complete && $ti > 0
            ? '<p style="margin:0 0 10px;color:' . $c['ink'] . '">Your stocks are worth <b>' . money($tv) . '</b>. You put in ' . money($ti) . ', so you are <b style="color:' . ($tg >= 0 ? $c['good'] : $c['bad']) . '">' . ($tg >= 0 ? 'up ' : 'down ') . money(abs($tg)) . ' (' . pct(($tv / $ti - 1) * 100) . ')</b>.</p>'
            : '';
        $mine = $sum . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:' . $c['ink'] . '">'
            . '<tr style="font-size:12px;color:' . $c['soft'] . '"><td style="padding:0 6px 4px">Stock</td><td align="right" style="padding:0 6px 4px">Worth now</td><td align="right" style="padding:0 6px 4px">Last day</td><td align="right" style="padding:0 6px 4px">Gain or loss</td></tr>'
            . $rows . '</table>';
    } else {
        $mine = '<p style="margin:0;color:' . $c['soft'] . '">You have not added any stocks yet. Open <b>My investing &gt; My stocks</b> and type in what you own in Greenlight to see it here every day.</p>';
    }

    $unsub = nl_site() . 'api/unsubscribe.php?t=' . urlencode($token);
    $sec = fn(string $title, string $inner) => '<tr><td style="padding:20px 24px 4px"><h2 style="margin:0 0 8px;font-size:17px;color:' . $c['brand'] . '">' . $title . '</h2>' . $inner . '</td></tr>';
    $btn = '<a href="' . h(nl_site()) . '" style="display:inline-block;background:' . $c['brand'] . ';color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:10px">Open Stock School</a>';

    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stock School</title></head>'
        . '<body style="margin:0;padding:0;background:' . $c['bg'] . ';font-family:Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['bg'] . '"><tr><td align="center" style="padding:16px 8px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;font-size:15px;line-height:1.5;color:' . $c['ink'] . '">'
        . '<tr><td style="padding:22px 24px 0"><div style="font-size:24px;font-weight:bold;color:' . $c['brand'] . '">📈 Stock School</div><div style="color:' . $c['soft'] . ';font-size:13px">Morning report for ' . $first . ' &middot; ' . h($date) . '</div></td></tr>'
        . ($take ? $sec("Today's weather", $take) : '')
        . $sec('Your stocks', $mine)
        . ($news ? $sec('In the news', '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $news . '</table>') : '')
        . ($alerts ? $sec('Worth knowing', '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $alerts . '</table>') : '')
        . '<tr><td align="center" style="padding:22px 24px">' . $btn . '</td></tr>'
        . '<tr><td style="padding:0 24px 22px;font-size:12px;color:' . $c['soft'] . '">This is for learning, not advice to buy or sell. Prices are from the morning update and change during the day; open Stock School for the latest.<br>'
        . 'You get this because your family admin turned on the daily email. <a href="' . h($unsub) . '" style="color:' . $c['soft'] . '">Stop these emails</a>.</td></tr>'
        . '</table></td></tr></table></body></html>';

    // Plain text version for simple mail apps.
    $txt = "Stock School, daily report for " . html_entity_decode(strip_tags($first)) . " ($date)\n\n";
    foreach ($d['briefing'] as $b) if (($b['kind'] ?? '') === 'takeaway') $txt .= ($b['text'] ?? '') . "\n\n";
    if ($hold) {
        $txt .= "YOUR STOCKS\n";
        foreach ($hold as $x) $txt .= sprintf("%s: worth %s, last day %s, gain or loss %s (%s)\n", $x['symbol'], money($x['value']), pct($x['chg_pct']), money($x['gain']), pct($x['gain_pct']));
        $txt .= "\n";
    }
    if ($d['news']) {
        $txt .= "IN THE NEWS\n";
        foreach (array_slice($d['news'], 0, 3) as $n) $txt .= '- ' . ($n['title'] ?? '') . ': ' . ($n['summary'] ?? '') . "\n";
        $txt .= "\n";
    }
    $txt .= "Open Stock School: " . nl_site() . "\nStop these emails: $unsub\n";

    $subj = 'Stock School: ' . date('M j') . ($hold ? ', your stocks this morning' : ', your morning market report');
    return [$subj, $html, $txt, $unsub];
}

function nl_send(array $user, string $email, string $token): bool {
    [$subj, $html, $txt, $unsub] = nl_build($user, $token);
    $boundary = 'b' . bin2hex(random_bytes(12));
    $from = nl_from();
    $headers = [
        'From' => '"' . nl_from_name() . '" <' . $from . '>',
        'Reply-To' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        'List-Unsubscribe' => '<' . $unsub . '>',
        'X-Mailer' => 'Stock School',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($txt))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    $subject = '=?UTF-8?B?' . base64_encode($subj) . '?=';
    if (!empty(cfg()['mail_dry_run'])) { file_put_contents(cfg()['mail_dry_run'] . '/' . preg_replace('/\W/', '_', $email) . '.html', $html); return true; }
    return mail($email, $subject, $body, $headers, '-f' . $from);
}

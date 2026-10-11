<?php
// One-click "stop these emails" page. GET shows a button, POST turns the email off (so link scanners cannot unsubscribe people).
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$t = (string)($_GET['t'] ?? '');
$ok = preg_match('/^[a-f0-9]{48}$/', $t) === 1;
$done = false;
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    db()->prepare('UPDATE fss_newsletter SET enabled = 0 WHERE token = ?')->execute([$t]);
    $done = true;
}
$tt = htmlspecialchars($t, ENT_QUOTES);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Stock School emails</title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f4ff;color:#1f2340;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}.c{background:#fff;border-radius:16px;padding:28px;max-width:420px;text-align:center}button{background:#5b4bff;color:#fff;border:0;border-radius:10px;padding:12px 20px;font-weight:bold;font-size:15px;cursor:pointer}a{color:#5b4bff}</style></head>
<body><div class="c"><h1>📈 Stock School</h1>
<?php if (!$ok): ?><p>This link does not look right. Ask your family admin to turn the email off for you.</p>
<?php elseif ($done): ?><p><b>Done.</b> You will not get the daily email anymore. Your family admin can turn it back on any time.</p><p><a href="../">Open Stock School</a></p>
<?php else: ?><p>Stop getting the daily Stock School email?</p><form method="post" action="?t=<?= $tt ?>"><button type="submit">Yes, stop the emails</button></form>
<?php endif; ?></div></body></html>

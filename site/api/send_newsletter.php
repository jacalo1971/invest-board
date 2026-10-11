<?php
// Sends the daily Stock School email to everyone the admin turned it on for.
// Run by a cPanel cron job every 30 minutes; it sends once per weekday after the market closes (New York time).
// php /home4/htvstudio/htv.studio/invest/api/send_newsletter.php
// Add --force to send again today (for testing).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/lib.php';
require __DIR__ . '/mailer.php';

$force = in_array('--force', $argv ?? [], true);
migrate();
$today = date('Y-m-d');            // New York time (set in mailer.php)
$dow = (int)date('N');
$hm = (int)date('Gi');              // e.g. 1745
if (!$force) {
    if ($dow >= 6) { echo "Weekend, nothing to send.\n"; exit(0); }
    if ($hm < 1730) { echo "Too early, the email goes out after the market closes.\n"; exit(0); }
    $upd = strtotime((string)(nl_data()['meta']['updated_at'] ?? ''));
    $afterClose = $upd && date('Y-m-d', $upd) === $today && (int)date('Gi', $upd) >= 1600;
    // Wait for the after-close update; if it has not landed by 8 pm, send with the latest data (if it is from the last 20 hours).
    if (!$afterClose && !($hm >= 2000 && nl_data_is_fresh())) { echo "Waiting for the after-close update.\n"; exit(0); }
}

$rows = db()->query('SELECT u.id, u.username, u.display_name, n.email, n.token, n.last_sent FROM fss_newsletter n JOIN fss_users u ON u.id = n.user_id WHERE n.enabled = 1')->fetchAll();
$sent = 0; $failed = 0;
foreach ($rows as $r) {
    if ($r['last_sent'] === $today && !$force) continue;
    if (nl_send($r, $r['email'], $r['token'])) {
        db()->prepare('UPDATE fss_newsletter SET last_sent = ? WHERE user_id = ?')->execute([$today, $r['id']]);
        $sent++;
    } else { $failed++; error_log('FSS newsletter: could not send to user ' . $r['id']); }
    usleep(300000);
}
echo "Sent $sent, failed $failed.\n";

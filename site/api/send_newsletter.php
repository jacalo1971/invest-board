<?php
// Sends the daily Stock School email to everyone the admin turned it on for.
// Run by a cPanel cron job every 30 minutes; it sends once per weekday at 7 am New York time.
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
$hm = (int)date('Gi');
if (!$force) {
    if ($dow >= 6) { echo "Weekend, nothing to send.\n"; exit(0); }
    if ($hm < 700) { echo "Too early, the email goes out at 7 am New York time.\n"; exit(0); }
    if ($hm >= 1200) { echo "Past noon, skipping today.\n"; exit(0); }
    if (!nl_data_is_fresh()) { echo "Board data is older than 20 hours, skipping.\n"; exit(0); }
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

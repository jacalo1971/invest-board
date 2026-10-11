<?php
// Sends the daily Stock School email to everyone the admin turned it on for.
// Run by a cPanel cron job every 30 minutes; it sends once per weekday at 7 am Pacific, after the morning update.
// php /home4/htvstudio/htv.studio/invest/api/send_newsletter.php
// Add --force to send again today (for testing).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/lib.php';
require __DIR__ . '/mailer.php';

$force = in_array('--force', $argv ?? [], true);
migrate();
$today = date('Y-m-d');            // Pacific time (set in mailer.php)
$dow = (int)date('N');
$hm = (int)date('Gi');              // e.g. 700
if (!$force) {
    if ($dow >= 6) { echo "Weekend, nothing to send.\n"; exit(0); }
    if ($hm < 700) { echo "Too early, the email goes out at 7 am Pacific.\n"; exit(0); }
    if ($hm >= 1200) { echo "Past noon Pacific, skipping today.\n"; exit(0); }
    // The morning update runs around 8 am New York (5 am Pacific). Wait for it; after 9 am Pacific send with the latest data from the last 20 hours.
    $upd = strtotime((string)(nl_data()['meta']['updated_at'] ?? ''));
    $morning = $upd && date('Y-m-d', $upd) === $today && $upd > strtotime($today . ' 03:00');
    if (!$morning && !($hm >= 900 && nl_data_is_fresh())) { echo "Waiting for the morning update.\n"; exit(0); }
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

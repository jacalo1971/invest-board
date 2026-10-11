<?php
// Shared helpers for the Family Stock School API.
declare(strict_types=1);

function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $paths = [];
    if (getenv('FSS_CONFIG')) $paths[] = getenv('FSS_CONFIG');
    // htv.studio/invest/api -> /home/<user>/invest_private/config.php
    $paths[] = dirname(__DIR__, 3) . '/invest_private/config.php';
    foreach ($paths as $p) {
        if (is_file($p)) { $c = require $p; return $c; }
    }
    fail(500, 'The site is not configured yet (config.php is missing).');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    $pdo = new PDO($c['db_dsn'], $c['db_user'] ?? null, $c['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function is_sqlite(): bool { return str_starts_with(cfg()['db_dsn'], 'sqlite'); }

function migrate(): void {
    $pdo = db();
    $auto = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $text = is_sqlite() ? 'TEXT' : 'TEXT';
    $engine = is_sqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS fss_users (
        id $auto,
        username VARCHAR(40) NOT NULL UNIQUE,
        display_name VARCHAR(60) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'member',
        created_at VARCHAR(25) NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fss_holdings (
        id $auto,
        user_id INT NOT NULL,
        symbol VARCHAR(12) NOT NULL,
        shares DECIMAL(18,6) NOT NULL,
        avg_cost DECIMAL(18,4) NOT NULL,
        bought_on VARCHAR(10) NULL,
        note VARCHAR(140) NULL,
        created_at VARCHAR(25) NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fss_questions (
        id $auto,
        user_id INT NOT NULL,
        question $text NOT NULL,
        answer $text NOT NULL,
        created_at VARCHAR(25) NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fss_prices (
        symbol VARCHAR(12) PRIMARY KEY,
        name VARCHAR(120) NULL,
        price DECIMAL(18,4) NULL,
        chg_pct DECIMAL(10,4) NULL,
        beta DECIMAL(10,4) NULL,
        low52 DECIMAL(18,4) NULL,
        high52 DECIMAL(18,4) NULL,
        fetched_at VARCHAR(25) NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fss_login_attempts (
        id $auto,
        ip VARCHAR(64) NOT NULL,
        username VARCHAR(40) NOT NULL,
        at_ts INT NOT NULL
    )$engine");
}

function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg): never { json_out(['ok' => false, 'error' => $msg], $code); }

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('fss_session');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/invest/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    session_start();
}

// Every API call must come from our own page: JSON body + custom header blocks cross-site form posts.
function require_ajax(): void {
    if (($_SERVER['HTTP_X_FSS'] ?? '') !== '1') fail(400, 'Bad request.');
}

function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function current_user(): ?array {
    start_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    $st = db()->prepare('SELECT id, username, display_name, role FROM fss_users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_user(): array {
    $u = current_user();
    if (!$u) fail(401, 'Please sign in.');
    return $u;
}

function require_admin(): array {
    $u = require_user();
    if ($u['role'] !== 'admin') fail(403, 'Only the family admin can do that.');
    return $u;
}

function clean_username(string $u): string {
    $u = strtolower(trim($u));
    if (!preg_match('/^[a-z0-9_.]{3,30}$/', $u)) fail(400, 'Usernames use 3 to 30 letters, numbers, dots or underscores.');
    return $u;
}

function check_password(string $p): string {
    if (strlen($p) < 8) fail(400, 'Passwords need at least 8 characters.');
    if (strlen($p) > 200) fail(400, 'That password is too long.');
    return $p;
}

function clean_symbol(string $s): string {
    $s = strtoupper(trim($s));
    if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,9}$/', $s)) fail(400, 'That ticker does not look right (example: NVDA).');
    return $s;
}

function http_get_json(string $url, int $timeout = 12): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) return null;
    $j = json_decode($res, true);
    return is_array($j) ? $j : null;
}

// Risk score 1-10, same idea as the board (beta, 52-week swing, distance from high).
function risk_score(?float $beta, ?float $p, ?float $lo, ?float $hi): ?float {
    if ($beta === null || !$p || !$lo || !$hi || $hi <= $lo) return null;
    $clamp = fn($x) => max(1.0, min(10.0, $x));
    $range = ($hi - $lo) / (($hi + $lo) / 2);
    $off = ($hi - $p) / $hi;
    $s = 0.45 * $clamp(1 + $beta / 2.6 * 9) + 0.35 * $clamp(1 + $range / 1.2 * 9) + 0.20 * $clamp(1 + $off / 0.6 * 9);
    return round($clamp($s), 1);
}

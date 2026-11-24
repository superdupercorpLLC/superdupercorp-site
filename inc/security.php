<?php
/**
 * Security helpers: HTTPS + headers, encryption at rest, IP minimisation,
 * security log, and TOTP two-factor codes. Loaded by inc/bootstrap.php.
 */
declare(strict_types=1);

// --- transport + headers -------------------------------------------------------

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function is_local_request(): bool {
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli' || in_array($host, ['', 'localhost', '127.0.0.1', '[::1]'], true);
}

/** Send every page over HTTPS so names, emails and messages are never sent in the clear. */
function force_https(): void {
    if (is_https() || is_local_request() || headers_sent()) return;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) return;
    header('Location: https://' . preg_replace('/:\d+$/', '', $host) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

function csp_nonce(): string {
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

/** Headers sent from PHP because the host ignores the ones in .htaccess. */
function send_security_headers(bool $admin = false): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: ' . ($admin ? 'DENY' : 'SAMEORIGIN'));
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-" . csp_nonce() . "'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: https:",
        "media-src 'self'",
        "frame-src https://www.youtube-nocookie.com https://player.vimeo.com",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        'frame-ancestors ' . ($admin ? "'none'" : "'self'"),
    ];
    if (is_https()) $csp[] = 'upgrade-insecure-requests';
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

// --- encryption at rest ----------------------------------------------------------

/**
 * Where the encryption key lives: a folder of its own, outside the web root and apart
 * from the data folder, so a copy of data/ (a backup, a download, a leak) can't be read.
 */
function key_dir(): string {
    static $d = null;
    if ($d !== null) return $d;
    $want = dirname(ROOT) . '/' . basename(ROOT) . '-keys';
    if (!is_dir($want)) @mkdir($want, 0700);
    return $d = (is_dir($want) && is_writable($want)) ? $want : DATA_DIR;
}

function encryption_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $f = key_dir() . '/encryption.key';
    $raw = is_file($f) ? base64_decode(trim((string) file_get_contents($f)), true) : false;
    if (!is_string($raw) || strlen($raw) !== 32) {
        if (is_file($f)) throw new RuntimeException('The encryption key file is damaged: ' . $f);
        $raw = random_bytes(32);
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, base64_encode($raw) . "\n", LOCK_EX) === false || !rename($tmp, $f))
            throw new RuntimeException('Could not save the encryption key in ' . key_dir());
        @chmod($f, 0600);
    }
    return $k = $raw;
}

function can_encrypt(): bool {
    return function_exists('sodium_crypto_secretbox')
        || (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true));
}

/** Encrypt a string. Output is text, safe to keep in JSON. */
function seal(string $plain): string {
    $key = encryption_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return 'o1:' . base64_encode($iv . $tag . $ct);
}

/** Decrypt a value made by seal(); null if it was tampered with or the key is wrong. */
function unseal(string $sealed): ?string {
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false) return null;
    $key = encryption_key();
    if (str_starts_with($sealed, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $p = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        return $p === false ? null : $p;
    }
    if (str_starts_with($sealed, 'o1:')) {
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }
    return null;
}

// --- contact messages (client data) -----------------------------------------------

const MESSAGE_FIELDS = ['name', 'email', 'company', 'kind', 'message', 'ip'];

/** All messages, decrypted, oldest first. Expired ones are removed as a side effect. */
function load_messages(): array {
    $stored = read_json('messages', []);
    $out = []; $plainFound = false;
    foreach ($stored as $m) {
        if (!is_array($m)) continue;
        if (isset($m['sealed'])) {
            $d = json_decode((string) unseal((string) $m['sealed']), true);
            $m = (is_array($d) ? $d : ['name' => '(could not decrypt)', 'email' => '', 'message' => 'This message could not be decrypted. Is the encryption key missing?'])
               + ['at' => $m['at'] ?? '', 'read' => !empty($m['read'])];
        } else {
            $plainFound = true; // saved before encryption was added
        }
        $out[] = $m + array_fill_keys(MESSAGE_FIELDS, '');
    }
    $kept = purge_expired_messages($out);
    if ($plainFound || count($kept) !== count($out)) save_messages($kept);
    return $kept;
}

function save_messages(array $msgs): void {
    $enc = can_encrypt();
    $rows = [];
    foreach ($msgs as $m) {
        if (!$enc) { $rows[] = $m; continue; }
        $private = array_intersect_key($m, array_flip(MESSAGE_FIELDS));
        $rows[] = ['at' => $m['at'] ?? date('c'), 'read' => !empty($m['read']),
                   'sealed' => seal(json_encode($private, JSON_UNESCAPED_UNICODE))];
    }
    write_json('messages', $rows);
}

/** Unread count without decrypting anything. */
function unread_messages(): int {
    return count(array_filter(read_json('messages', []), fn($m) => is_array($m) && empty($m['read'])));
}

function message_retention_days(): int {
    $v = setting('message_retention_days', '365');
    return ctype_digit($v) ? (int) $v : 365;
}

function purge_expired_messages(array $msgs): array {
    $days = message_retention_days();
    if ($days <= 0) return $msgs;
    $cut = time() - $days * 86400;
    return array_values(array_filter($msgs, fn($m) => ($t = strtotime((string) ($m['at'] ?? ''))) === false || $t >= $cut));
}

/** Keep only the network part of an IP (enough to spot spam, not to identify a person). */
function anonymise_ip(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.0', $ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        return (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
    }
    return '';
}

// --- security log --------------------------------------------------------------

function security_log(string $event, string $detail = ''): void {
    $log = read_json('security-log', []);
    $log[] = ['at' => date('c'), 'event' => $event, 'ip' => anonymise_ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
              'detail' => mb_substr($detail, 0, 200)];
    write_json('security-log', array_slice($log, -300));
}

// --- two-factor (TOTP, RFC 6238) --------------------------------------------------

function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_code(string $secret, int $counter): string {
    $h = hash_hmac('sha1', pack('J', $counter), base32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Returns the matched time step (to block replays), or null. Allows ±30 s clock drift. */
function totp_verify(string $secret, string $code, int $lastUsed = 0): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $c = $now + $i;
        if ($c > $lastUsed && hash_equals(totp_code($secret, $c), $code)) return $c;
    }
    return null;
}

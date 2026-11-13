<?php
/**
 * Update the site's code from its GitHub repository (no shell or Git needed on the host).
 *
 * Only ever installs code from REPO below. Never touches: data (lives outside the web root),
 * uploads/, tools/, or cPanel's PHP lines in .htaccess. Sets web-readable permissions and
 * asks LiteSpeed to reload PHP afterwards.
 */
declare(strict_types=1);

const REPO = 'superdupercorpLLC/superdupercorp-site';
const UPDATE_SKIP = ['/^\.git/', '/^tools(\/|$)/', '/^\.cpanel\.yml$/', '/^deploy\.sh$/', '/^dev-router\.php$/',
                     '/^data\/.*\.(json|txt)$/', '/^uploads\/(?!\.htaccess$)/', '/^README\.md$/'];

function http_get(string $url): string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
                               CURLOPT_USERAGENT => 'sdc-selfupdate', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
        $body = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);
        if ($body === false || $code >= 400) throw new RuntimeException("Download failed ($code $err): $url");
        return (string) $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: sdc-selfupdate\r\nAccept: application/vnd.github+json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new RuntimeException("Download failed: $url (enable PHP curl or allow_url_fopen)");
    return $body;
}

function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f)
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}

/** Keep cPanel's MultiPHP handler / ini blocks when replacing .htaccess. */
function merge_htaccess(string $new, string $oldPath): string {
    if (!is_file($oldPath)) return $new;
    $old = (string) file_get_contents($oldPath);
    $keep = [];
    foreach (['/# php -- BEGIN cPanel-generated handler.*?# php -- END cPanel-generated handler[^\n]*/s',
              '/# BEGIN cPanel-generated php ini directives.*?# END cPanel-generated php ini directives[^\n]*/s'] as $re)
        if (preg_match($re, $old, $m)) $keep[] = $m[0];
    return $keep ? implode("\n\n", $keep) . "\n\n" . $new : $new;
}

function self_update(string $ref = 'main'): array {
    if (!preg_match('/^[A-Za-z0-9._\/-]{1,80}$/', $ref)) throw new RuntimeException('Bad ref.');
    $info = json_decode(http_get('https://api.github.com/repos/' . REPO . '/commits/' . rawurlencode($ref)), true);
    $sha = $info['sha'] ?? '';
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException('Could not resolve the latest commit on GitHub.');

    $tmp = sys_get_temp_dir() . '/sdc-update-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    try {
        $tgz = "$tmp/src.tar.gz";
        file_put_contents($tgz, http_get('https://codeload.github.com/' . REPO . '/tar.gz/' . $sha));
        if (class_exists('PharData')) {
            (new PharData($tgz))->extractTo("$tmp/x", null, true);
        } elseif (function_exists('exec')) {
            @mkdir("$tmp/x");
            exec('tar -xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg("$tmp/x"), $o, $rc);
            if ($rc !== 0) throw new RuntimeException('Could not unpack the download.');
        } else {
            throw new RuntimeException('Neither the Phar extension nor exec() is available to unpack the download.');
        }
        $top = glob("$tmp/x/*", GLOB_ONLYDIR)[0] ?? '';
        if (!$top || !is_file("$top/index.php")) throw new RuntimeException('Unexpected download layout.');

        $written = 0; $failed = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($top, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($top))), '/');
            foreach (UPDATE_SKIP as $re) if (preg_match($re, $rel)) continue 2;
            $dest = ROOT . '/' . $rel;
            if ($f->isDir()) { if (!is_dir($dest)) @mkdir($dest, 0755, true); @chmod($dest, 0755); continue; }
            $data = (string) file_get_contents($f->getPathname());
            if ($rel === '.htaccess') $data = merge_htaccess($data, $dest);
            $part = $dest . '.sdc-new';
            if (@file_put_contents($part, $data) === false || !@rename($part, $dest)) { @unlink($part); $failed[] = $rel; continue; }
            @chmod($dest, 0644);
            $written++;
        }
        @chmod(ROOT, 0755);
        @touch(dirname(ROOT) . '/.lsphp_restart.txt');
        if (function_exists('opcache_reset')) @opcache_reset();
        $ver = ['commit' => $sha, 'short' => substr($sha, 0, 7), 'message' => strtok((string) ($info['commit']['message'] ?? ''), "\n"),
                'at' => date('c'), 'files' => $written, 'failed' => $failed];
        write_json('version', $ver);
        return $ver;
    } finally {
        rrmdir($tmp);
    }
}

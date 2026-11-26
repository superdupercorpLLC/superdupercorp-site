<?php
/**
 * Update the site's code from its GitHub repository (no shell or Git needed on the host).
 * Works with a private repository once a read-only access key is saved in the admin.
 *
 * Only ever installs code from REPO below. Never touches: data (lives outside the web root),
 * uploads/, tools/, or cPanel's PHP lines in .htaccess. Sets web-readable permissions and
 * asks LiteSpeed to reload PHP afterwards.
 */
declare(strict_types=1);

const REPO = 'superdupercorpLLC/superdupercorp-site';
const UPDATE_SKIP = ['/^\.git/', '/^tools(\/|$)/', '/^\.cpanel\.yml$/', '/^deploy\.sh$/', '/^dev-router\.php$/',
                     '/^data\/.*\.(json|txt)$/', '/^uploads\/(?!\.htaccess$)/', '/^README\.md$/'];

/** Read-only GitHub access key for the (private) repo, saved encrypted from Admin → Site copy → Security. */
function github_token(): string {
    $t = read_json('auth', [])['github_token'] ?? '';
    return $t ? (string) unseal((string) $t) : '';
}

/**
 * GET from the GitHub API with the access key. Redirects (GitHub sends downloads to a short-lived
 * signed URL on another host) are followed by hand, WITHOUT the key, so it only ever goes to api.github.com.
 */
function github_get(string $path, int $timeout = 120): string {
    $url = 'https://api.github.com' . $path;
    $token = github_token();
    for ($hop = 0; $hop < 5; $hop++) {
        $isApi = str_starts_with($url, 'https://api.github.com/');
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($token !== '' && $isApi) $headers[] = 'Authorization: Bearer ' . $token;
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout,
                                   CURLOPT_USERAGENT => 'sdc-selfupdate', CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true,
                                   CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $resp = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $hlen = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
            $err = curl_error($c);
            curl_close($c);
            if ($resp === false) throw new RuntimeException("Download failed ($err)");
            $head = substr((string) $resp, 0, $hlen); $body = substr((string) $resp, $hlen);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true,
                                          'header' => implode("\r\n", array_merge($headers, ['User-Agent: sdc-selfupdate'])) . "\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new RuntimeException('Download failed (enable PHP curl or allow_url_fopen)');
            $head = implode("\n", $http_response_header ?? []);
            $code = preg_match('~^HTTP/\S+\s+(\d+)~', $head, $m) ? (int) $m[1] : 0;
        }
        if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/mi', $head, $m)) {
            $url = $m[1];
            if (!str_starts_with($url, 'https://')) throw new RuntimeException('Refused a non-HTTPS redirect from GitHub.');
            continue;
        }
        if ($code === 401 || $code === 403 || $code === 404)
            throw new RuntimeException($token === ''
                ? 'GitHub refused the download. If the repository is private, add a GitHub access key in Admin → Site copy → Security.'
                : 'GitHub refused the download (' . $code . '). The access key may have expired or lost access to the repository.');
        if ($code >= 400) throw new RuntimeException("Download failed (HTTP $code).");
        return (string) $body;
    }
    throw new RuntimeException('Too many redirects from GitHub.');
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
    $info = json_decode(github_get('/repos/' . REPO . '/commits/' . rawurlencode($ref)), true);
    $sha = $info['sha'] ?? '';
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException('Could not resolve the latest commit on GitHub.');

    $tmp = sys_get_temp_dir() . '/sdc-update-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    try {
        $tgz = "$tmp/src.tar.gz";
        file_put_contents($tgz, github_get('/repos/' . REPO . '/tarball/' . $sha));
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

<?php
/**
 * Video: YouTube / Vimeo links and uploaded files.
 *
 * Gallery items can be:
 *   ['type' => 'embed', 'provider' => 'youtube|vimeo', 'vid' => ID, 'url' => original link, 'embed' => player URL,
 *    'thumb' => poster image URL, 'title' => '', 'shape' => '16x9|9x16|1x1|4x5', 'caption' => '', 'wide' => bool]
 *   ['type' => 'video', 'src' => 'uploads/…mp4', 'shape' => …, 'loop' => bool, 'caption' => '', 'wide' => bool]
 */
declare(strict_types=1);

const VIDEO_SHAPES = ['16x9' => 'Wide 16:9', '9x16' => 'Vertical 9:16', '1x1' => 'Square 1:1', '4x5' => 'Portrait 4:5'];

if (!function_exists('http_get')) {
    function http_get(string $url, int $timeout = 120): string {
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
                                   CURLOPT_USERAGENT => 'sdc-site', CURLOPT_HTTPHEADER => ['Accept: application/json, */*']]);
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $err = curl_error($c);
            curl_close($c);
            if ($body === false || $code >= 400) throw new RuntimeException("Download failed ($code $err): $url");
            return (string) $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: sdc-site\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new RuntimeException("Download failed: $url");
        return $body;
    }
}

/** Recognise a YouTube or Vimeo link. Returns null if it isn't one. */
function parse_video_url(string $url): ?array {
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([\w-]{11})~i', $url, $m)) {
        $id = $m[1];
        $start = preg_match('~[?&#]t=(\d+)~', $url, $t) ? (int) $t[1] : 0;
        return ['type' => 'embed', 'provider' => 'youtube', 'vid' => $id, 'url' => $url,
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0&modestbranding=1&playsinline=1' . ($start ? "&start=$start" : ''),
                'thumb' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                'shape' => stripos($url, '/shorts/') !== false ? '9x16' : '16x9'];
    }
    if (preg_match('~vimeo\.com/(?:video/|channels/[\w-]+/|groups/[\w-]+/videos/|showcase/\d+/video/)?(\d{5,})(?:/([0-9a-f]{6,}))?~i', $url, $m)) {
        $id = $m[1];
        $hash = $m[2] ?? (preg_match('~[?&]h=([0-9a-f]{6,})~i', $url, $h) ? $h[1] : '');
        return ['type' => 'embed', 'provider' => 'vimeo', 'vid' => $id, 'url' => $url,
                'embed' => 'https://player.vimeo.com/video/' . $id . '?autoplay=1&dnt=1&title=0&byline=0&portrait=0' . ($hash ? "&h=$hash" : ''),
                'thumb' => '', 'shape' => '16x9'];
    }
    return null;
}

/** Fill in title, poster image and shape from the provider (best effort, never fatal). */
function enrich_video(array $v): array {
    $o = null;
    try {
        if ($v['provider'] === 'vimeo') {
            $o = json_decode(http_get('https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode($v['url']), 8), true);
        } elseif ($v['provider'] === 'youtube') {
            $o = json_decode(http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $v['vid']), 8), true);
        }
    } catch (Throwable $e) { $o = null; }
    if (is_array($o)) {
        if (empty($v['title']) && !empty($o['title'])) $v['title'] = mb_substr((string) $o['title'], 0, 200);
        if (!empty($o['thumbnail_url']) && $v['provider'] === 'vimeo') $v['thumb'] = preg_replace('~_\d+x\d+~', '_1280', (string) $o['thumbnail_url']);
        if (!empty($o['width']) && !empty($o['height']) && $v['provider'] === 'vimeo') $v['shape'] = guess_shape((int) $o['width'], (int) $o['height']);
    }
    return $v;
}

function guess_shape(int $w, int $h): string {
    if ($w <= 0 || $h <= 0) return '16x9';
    $r = $w / $h;
    return $r < 0.7 ? '9x16' : ($r < 0.9 ? '4x5' : ($r < 1.2 ? '1x1' : '16x9'));
}

/**
 * Add video links to a project. Returns error messages for links that weren't recognised.
 * Also turns the old single `video_url` field into a gallery item.
 */
function add_video_links(array &$w, array $urls): array {
    $errors = [];
    $have = [];
    foreach ($w['gallery'] ?? [] as $g) if (($g['type'] ?? '') === 'embed') $have[$g['provider'] . ':' . $g['vid']] = true;
    if (!empty($w['video_url'])) { array_unshift($urls, $w['video_url']); $w['video_url'] = ''; }
    $added = [];
    foreach ($urls as $u) {
        $u = trim((string) $u);
        if ($u === '') continue;
        $v = parse_video_url($u);
        if (!$v) { $errors[] = "Not a YouTube or Vimeo link: $u"; continue; }
        $k = $v['provider'] . ':' . $v['vid'];
        if (isset($have[$k])) continue;
        $have[$k] = true;
        $added[] = enrich_video($v) + ['title' => '', 'caption' => '', 'wide' => false];
    }
    if ($added) {
        // New videos go right after the existing videos (or first, if there are none),
        // so adding a link later never replaces the project's lead video.
        $g = $w['gallery'] ?? [];
        $pos = 0;
        foreach ($g as $i => $item) if (is_video_item($item)) $pos = $i + 1;
        array_splice($g, $pos, 0, $added);
        $w['gallery'] = $g;
        if (empty($w['cover']['large'])) foreach ($added as $v) if (!empty($v['thumb'])) { $w['cover'] = ['large' => $v['thumb'], 'thumb' => $v['thumb']]; break; }
        if (!isset($w['lead_video'])) $w['lead_video'] = true;
    }
    return $errors;
}

function is_video_item(array $g): bool { return in_array($g['type'] ?? 'image', ['embed', 'video'], true); }

function has_video(array $w): bool {
    if (!empty($w['video_url'])) return true;
    foreach ($w['gallery'] ?? [] as $g) if (is_video_item($g)) return true;
    return false;
}

/** Items to show, with the legacy video_url turned into an embed on the fly. */
function project_media(array $w): array {
    $items = $w['gallery'] ?? [];
    if (!empty($w['video_url']) && ($v = parse_video_url($w['video_url']))) {
        $dupe = false;
        foreach ($items as $g) if (($g['type'] ?? '') === 'embed' && $g['vid'] === $v['vid']) $dupe = true;
        if (!$dupe) array_unshift($items, $v + ['title' => '', 'caption' => '', 'wide' => true]);
    }
    return $items;
}

/** Player HTML. Embeds load only when clicked (fast pages, no tracking until play). */
function render_video(array $g, string $label = 'video'): string {
    $shape = isset(VIDEO_SHAPES[$g['shape'] ?? '']) ? $g['shape'] : '16x9';
    $own = trim((string) ($g['title'] ?? ''));
    $title = $own ?: $label;
    if (($g['type'] ?? '') === 'embed') {
        $poster = $g['thumb'] ?? '';
        $name = $g['provider'] === 'vimeo' ? 'Vimeo' : 'YouTube';
        return '<div class="vid vid--' . $shape . '">'
             . '<a class="vid__facade" href="' . e($g['url']) . '" data-embed="' . e($g['embed']) . '" data-title="' . e($title) . '" target="_blank" rel="noopener">'
             . ($poster ? '<img src="' . e($poster) . '" alt="" loading="lazy" decoding="async" data-hide-on-error>' : '')
             . '<span class="vid__play" aria-hidden="true"></span>'
             . '<span class="vid__label"><span class="sr-only">Play video: ' . e($title) . '</span>' . ($own ? '<span aria-hidden="true">' . e($own) . '</span>' : '<span></span>') . ' <i>' . $name . '</i></span>'
             . '</a></div>';
    }
    $src = media_url($g['src'] ?? '');
    if (!empty($g['loop'])) {
        return '<div class="vid vid--' . $shape . ' vid--loop"><video src="' . e($src) . '" autoplay muted loop playsinline preload="auto" aria-label="' . e($title) . '"></video></div>';
    }
    $poster = !empty($g['poster']) ? ' poster="' . e(media_url($g['poster'])) . '"' : '';
    return '<div class="vid vid--' . $shape . ' vid--file"><video src="' . e($src) . '#t=0.1" controls playsinline preload="metadata"' . $poster . ' aria-label="' . e($title) . '"></video></div>';
}

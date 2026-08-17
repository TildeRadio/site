<?php
require_once __DIR__ . '/lib/radio.php';

function http_get(string $url, int $timeout = 4, bool $insecure_ssl = false): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'tilderadio-index/1.1',
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
}

function http_get_with_headers(string $url, array $headers = [], int $timeout = 4, bool $insecure_ssl = false): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'tilderadio-index/1.1',
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
}

function azuracast_nowplaying(string $url): array {
    $json = http_get($url);
    if (!$json) return ['title' => null, 'listeners' => null];
    $data = json_decode($json, true);
    if (!is_array($data)) return ['title' => null, 'listeners' => null];

    if (isset($data['icestats'])) {
        $src = $data['icestats']['source'] ?? [];
        $mounts = (is_array($src) && array_keys($src) === range(0, count($src) - 1)) ? $src : [$src];
        $total = 0;
        $preferred = null;
        $fallback  = null;
        foreach ($mounts as $m) {
            if (!is_array($m)) continue;
            if (isset($m['listeners']) && is_numeric($m['listeners'])) $total += (int)$m['listeners'];
            $title = isset($m['title']) && is_string($m['title']) ? trim($m['title']) : '';
            if ($title !== '') {
                if (($m['server_type'] ?? '') === 'audio/mpeg' && $preferred === null) $preferred = $title;
                if ($fallback === null) $fallback = $title;
            } elseif (!empty($m['yp_currently_playing'])) {
                $yp = trim((string)$m['yp_currently_playing']);
                if ($yp !== '' && $fallback === null) $fallback = $yp;
            }
        }
        $title = $preferred ?? $fallback;
        return ['title' => $title, 'listeners' => $total > 0 ? $total : null];
    }

    $np        = $data['now_playing'] ?? null;
    $listeners = $data['listeners'] ?? null;

    $title = null;
    if (is_array($np)) {
        $song = $np['song'] ?? null;
        if (is_array($song)) {
            $artist = trim((string)($song['artist'] ?? ''));
            $track  = trim((string)($song['title']  ?? ''));
            if ($artist !== '' || $track !== '') $title = trim($artist . ' - ' . $track, ' -');
            else {
                $text = trim((string)($song['text'] ?? ''));
                if ($text !== '') $title = $text;
            }
        }
    }

    $listenersVal = null;
    if (is_array($listeners)) {
        foreach (['current', 'total', 'unique'] as $k) {
            if (isset($listeners[$k]) && is_numeric($listeners[$k])) { $listenersVal = (int)$listeners[$k]; break; }
        }
    } elseif (is_numeric($listeners)) {
        $listenersVal = (int)$listeners;
    }

    return ['title' => $title, 'listeners' => $listenersVal];
}

function azuracast_live_dj(string $base, string $shortcode, ?string $apiKey = null): array {
    $base = rtrim($base, '/');
    $url  = $base . '/api/nowplaying/' . rawurlencode($shortcode);

    $headers = ['Accept' => 'application/json'];
    if ($apiKey && trim($apiKey) !== '') {
        $headers['X-API-Key']     = $apiKey;
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $json = http_get_with_headers($url, $headers);
    if ($json === null && $apiKey && trim($apiKey) !== '') {
        $sep  = (strpos($url, '?') !== false) ? '&' : '?';
        $json = http_get($url . $sep . 'api_key=' . rawurlencode($apiKey));
    }
    if ($json === null) return ['is_live' => false, 'streamer_name' => null];

    $data = json_decode($json, true);
    if (!is_array($data)) return ['is_live' => false, 'streamer_name' => null];

    if (array_keys($data) === range(0, count($data) - 1)) {
        foreach ($data as $row) {
            if (is_array($row) && isset($row['station']['shortcode']) &&
                strcasecmp((string)$row['station']['shortcode'], $shortcode) === 0) {
                $data = $row;
                break;
            }
        }
        if (array_keys($data) === range(0, count($data) - 1)) return ['is_live' => false, 'streamer_name' => null];
    }

    $live = $data['live'] ?? null;
    if (!is_array($live)) return ['is_live' => false, 'streamer_name' => null];

    $isLive = !empty($live['is_live']);
    $name   = isset($live['streamer_name']) && is_string($live['streamer_name']) ? trim($live['streamer_name']) : null;

    return ['is_live' => (bool)$isLive, 'streamer_name' => ($name !== '' ? $name : null)];
}

function parse_ics_schedule(string $url): array {
    $ics = http_get($url);
    if (!$ics) return ['current' => null, 'next' => null];
    $raw = preg_split("/\r\n|\n|\r/", $ics);
    $lines = [];
    foreach ($raw as $ln) {
        if ($ln !== '' && (isset($ln[0]) && ($ln[0] === ' ' || $ln[0] === "\t")) && !empty($lines)) $lines[count($lines) - 1] .= substr($ln, 1);
        else $lines[] = rtrim($ln, "\r\n");
    }
    $events = [];
    $in = false;
    $cur = ['start' => null, 'end' => null, 'summary' => null];
    foreach ($lines as $ln) {
        if ($ln === 'BEGIN:VEVENT') { $in = true; $cur = ['start' => null, 'end' => null, 'summary' => null]; continue; }
        if ($ln === 'END:VEVENT') { if ($cur['start'] && $cur['end'] && $cur['summary']) $events[] = $cur; $in = false; $cur = ['start' => null, 'end' => null, 'summary' => null]; continue; }
        if (!$in || strpos($ln, ':') === false) continue;
        [$keyPart, $value] = explode(':', $ln, 2);
        $key = strtoupper(strtok($keyPart, ';'));
        if ($key === 'DTSTART') $cur['start'] = ics_to_epoch($value);
        elseif ($key === 'DTEND') $cur['end'] = ics_to_epoch($value);
        elseif ($key === 'SUMMARY') $cur['summary'] = ics_unescape($value);
    }
    usort($events, fn($a, $b) => $a['start'] <=> $b['start']);
    $now = time();
    $current = null; $next = null;
    foreach ($events as $e) {
        if ($e['start'] <= $now && $now < $e['end']) $current = $e;
        elseif ($e['start'] > $now) { $next = $e; break; }
    }
    return ['current' => $current, 'next' => $next];
}

function ics_to_epoch(string $v): ?int {
    $v = trim($v);
    if (preg_match('/^\d{8}T\d{6}Z$/', $v)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $v, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }
    if (preg_match('/^\d{8}T\d{6}$/', $v)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $v, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }
    return null;
}

function ics_unescape(string $s): string {
    return trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], [' ', ' ', ',', ';', '\\'], $s));
}

function tr_index_safe_art_url(mixed $url): ?string
{
    if (!is_string($url)) {
        return null;
    }

    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function tr_index_dj_summary(?string $dj): ?array
{
    if (!is_string($dj) || trim($dj) === '') {
        return null;
    }

    $dj = trim($dj);
    $slug = tr_slug($dj);
    if ($slug === '') {
        return null;
    }

    static $metadata = null;
    if ($metadata === null) {
        $metadata = tr_dj_metadata();
    }

    $profile = is_array($metadata[$slug] ?? null) ? $metadata[$slug] : [];
    $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];

    $name = isset($profile['name']) && is_string($profile['name']) && trim($profile['name']) !== ''
        ? trim($profile['name'])
        : $dj;
    $tagline = isset($profile['tagline']) && is_string($profile['tagline'])
        ? trim($profile['tagline'])
        : '';
    $showTitle = isset($show['title']) && is_string($show['title']) ? trim($show['title']) : '';
    $showTagline = isset($show['tagline']) && is_string($show['tagline']) ? trim($show['tagline']) : '';

    return [
        'slug' => $slug,
        'name' => $name,
        'tagline' => $tagline !== '' ? $tagline : null,
        'show_title' => $showTitle !== '' ? $showTitle : null,
        'show_tagline' => $showTagline !== '' ? $showTagline : null,
    ];
}

function tr_index_context_line(?array $profile): ?string
{
    if ($profile === null) {
        return null;
    }

    foreach (['show_title', 'tagline'] as $key) {
        if (isset($profile[$key]) && is_string($profile[$key]) && trim($profile[$key]) !== '') {
            return trim($profile[$key]);
        }
    }

    return null;
}

$AZURACAST_BASE      = 'https://azuracast.tilderadio.org';
$AZURACAST_SHORTCODE = 'tilderadio';
$NOWPLAY_URL         = 'https://azuracast.tilderadio.org/radio/8000/status-json.xsl';
$ICS_URL             = 'https://tilderadio.org/schedule/ics.php';

$apikey = null;
@include __DIR__ . '/schedule/apikey.php';

if (isset($_GET['json']) && $_GET['json'] === 'np') {
    $richNow = tr_now_playing();
    if (!empty($richNow['available'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $jsonDj = !empty($richNow['is_live']) ? ($richNow['dj'] ?? null) : null;
        echo json_encode([
            'title' => $richNow['now_playing']['text'] ?? null,
            'listeners' => $richNow['listeners'] ?? null,
            'dj' => $jsonDj,
            'dj_profile' => tr_index_dj_summary(is_string($jsonDj) ? $jsonDj : null),
            'is_live' => !empty($richNow['is_live']),
            'art' => tr_index_safe_art_url($richNow['now_playing']['art'] ?? null),
            'history' => $richNow['history'] ?? [],
            'ts' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $np = azuracast_nowplaying($NOWPLAY_URL);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'title' => $np['title'] ?? null,
        'listeners' => $np['listeners'] ?? null,
        'ts' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$richNow = tr_now_playing();

if (!empty($richNow['available'])) {
    $currentSong = $richNow['now_playing']['text'] ?? null;
    $listenerCt = is_int($richNow['listeners'] ?? null) ? $richNow['listeners'] : null;
    $currentDJ = !empty($richNow['is_live']) ? ($richNow['dj'] ?? null) : null;
    $currentArt = tr_index_safe_art_url($richNow['now_playing']['art'] ?? null);
    $recentTracks = is_array($richNow['history'] ?? null) ? array_slice($richNow['history'], 0, 5) : [];
} else {
    $np = azuracast_nowplaying($NOWPLAY_URL);
    $liveInfo = azuracast_live_dj($AZURACAST_BASE, $AZURACAST_SHORTCODE, $apikey ?? null);
    $currentSong = $np['title'] ?? null;
    $listenerCt = $np['listeners'] ?? null;
    $currentDJ = (!empty($liveInfo['is_live']) && !empty($liveInfo['streamer_name'])) ? $liveInfo['streamer_name'] : null;
    $currentArt = null;
    $recentTracks = [];
}

$currentDjProfile = tr_index_dj_summary(is_string($currentDJ) ? $currentDJ : null);
$currentContext = tr_index_context_line($currentDjProfile);
$upcomingShows = [];
$now = time();
foreach (tr_schedule(8) as $event) {
    if (!is_int($event['start_ts'] ?? null) || $event['start_ts'] <= $now) {
        continue;
    }

    $event['dj_profile'] = tr_index_dj_summary((string) ($event['name'] ?? ''));
    $upcomingShows[] = $event;
    if (count($upcomingShows) >= 3) {
        break;
    }
}

$slogan_raw = http_get('https://bot.tildegit.org/api/slogan', 4, true);
$slogan_txt = $slogan_raw !== null ? json_decode($slogan_raw, true) : null;
$page_stylesheets = ['css/home.css'];
?>
<?php include 'header.php'; ?>

<div class="tr-wrap">

  <section class="tr-section tr-home-now" id="tr-now-card">
    <div class="tr-now-layout">
      <div class="tr-art-frame">
        <img
          id="tr-art"
          class="tr-now-art<?= $currentArt === null ? ' is-fallback' : '' ?>"
          src="<?= htmlspecialchars($currentArt ?? asset('logos/tilderadio.png'), ENT_QUOTES, 'UTF-8') ?>"
          data-fallback="<?= htmlspecialchars(asset('logos/tilderadio.png'), ENT_QUOTES, 'UTF-8') ?>"
          alt="Current track artwork"
          width="240"
          height="240"
          decoding="async"
        >
      </div>

      <div class="tr-now-main">
        <div class="tr-now-head">
          <span class="tr-badge">ON AIR</span>
          <span id="tr-mode" class="tr-mode<?= $currentDJ ? ' is-live' : ' is-auto' ?>">
            <?= $currentDJ ? 'LIVE DJ' : 'AUTODJ' ?>
          </span>
          <span class="tr-now-listeners" id="tr-listeners-label">
            <?= is_int($listenerCt) ? (int) $listenerCt . ' listening' : 'listeners unavailable' ?>
          </span>
        </div>

        <div class="tr-now-copy" role="status" aria-live="polite">
          <h1 id="tr-np-text"><?= htmlspecialchars($currentSong ?: 'unknown', ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="tr-now-byline">
            <span id="tr-dj-auto"<?= $currentDJ ? ' hidden' : '' ?>>tilderadio AutoDJ</span>
            <a
              id="tr-dj-link"
              href="<?= htmlspecialchars(asset('djs/?dj=' . rawurlencode((string)($currentDjProfile['slug'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
              <?= $currentDJ ? '' : 'hidden' ?>
            ><?= htmlspecialchars((string)($currentDjProfile['name'] ?? $currentDJ ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
          </p>
          <p id="tr-dj-context" class="tr-now-context"<?= $currentContext ? '' : ' hidden' ?>>
            <?= htmlspecialchars($currentContext ?? '', ENT_QUOTES, 'UTF-8') ?>
          </p>
        </div>

        <div class="tr-player tr-home-player" id="tr-player" aria-label="tilderadio player" role="group">
          <div class="row top">
            <div class="controls">
              <button id="tr-play" type="button">▶ play</button>
              <button id="tr-mute" type="button">🔈 mute</button>
              <label class="srconly" for="tr-vol">vol</label>
              <input id="tr-vol" type="range" min="0" max="1" step="0.01" value="1" aria-label="volume">
              <span class="tr-eq" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>
            <div class="source">
              <label class="srconly" for="tr-src">stream</label>
              <select id="tr-src" aria-label="stream">
                <option value="https://tilderadio.org/listen/ogg/192k" data-type="audio/ogg">ogg 192k</option>
                <option value="https://tilderadio.org/listen/ogg/320k" data-type="audio/ogg">ogg 320k</option>
                <option value="https://tilderadio.org/listen/mp3/192k" data-type="audio/mpeg">mp3 192k</option>
                <option value="https://tilderadio.org/listen/mp3/320k" data-type="audio/mpeg">mp3 320k</option>
              </select>
            </div>
          </div>
        </div>

        <details class="tr-home-details tr-recent-details">
          <summary>recently played</summary>
          <ol class="tr-history" id="tr-history-list">
            <?php if ($recentTracks): ?>
              <?php foreach ($recentTracks as $track): ?>
                <li>
                  <span><?= htmlspecialchars((string)($track['text'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></span>
                  <?php if (is_int($track['played_at'] ?? null)): ?>
                    <time datetime="<?= htmlspecialchars(gmdate(DATE_ATOM, $track['played_at']), ENT_QUOTES, 'UTF-8') ?>" data-history-time="<?= (int)$track['played_at'] ?>">
                      <?= htmlspecialchars(gmdate('H:i', $track['played_at']) . ' UTC', ENT_QUOTES, 'UTF-8') ?>
                    </time>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            <?php else: ?>
              <li><em>recent track history is temporarily unavailable.</em></li>
            <?php endif; ?>
          </ol>
        </details>
      </div>
    </div>
  </section>

  <section class="tr-section tr-home-upcoming">
    <div class="tr-title tr-title-split">
      <span class="tr-badge">UP NEXT</span>
      <span class="tr-section-tools"><span id="tr-timezone-label">UTC</span> · <a href="<?= htmlspecialchars(asset('schedule/'), ENT_QUOTES, 'UTF-8') ?>">full schedule</a></span>
    </div>

    <?php if ($upcomingShows): ?>
      <div class="tr-upcoming-list">
        <?php foreach ($upcomingShows as $index => $event): ?>
          <?php
          $profile = is_array($event['dj_profile'] ?? null) ? $event['dj_profile'] : null;
          $showTitle = is_string($profile['show_title'] ?? null) ? trim($profile['show_title']) : '';
          $slug = is_string($profile['slug'] ?? null) ? $profile['slug'] : tr_slug((string)$event['name']);
          $displayName = is_string($profile['name'] ?? null) && trim($profile['name']) !== ''
              ? trim($profile['name'])
              : (string)$event['name'];
          ?>
          <article class="tr-upcoming-item<?= $index === 0 ? ' is-next' : '' ?>">
            <div class="tr-upcoming-who">
              <a href="<?= htmlspecialchars(asset('djs/?dj=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
              </a>
              <?php if ($showTitle !== ''): ?>
                <span><?= htmlspecialchars($showTitle, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <div class="tr-upcoming-when">
              <time
                datetime="<?= htmlspecialchars(gmdate(DATE_ATOM, (int)$event['start_ts']), ENT_QUOTES, 'UTF-8') ?>"
                data-local-start="<?= (int)$event['start_ts'] ?>"
              ><?= htmlspecialchars(gmdate('D M d H:i', (int)$event['start_ts']) . ' UTC', ENT_QUOTES, 'UTF-8') ?></time>
              <?php if ($index === 0): ?>
                <span class="tr-countdown" data-countdown="<?= (int)$event['start_ts'] ?>"></span>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p><em>no upcoming live shows are currently listed.</em></p>
    <?php endif; ?>
  </section>

  <section class="tr-section tr-home-about">
    <div class="tr-title"><span class="tr-badge">ABOUT</span></div>
    <?php if ($slogan_txt): ?>
      <blockquote><?= htmlspecialchars((string)$slogan_txt, ENT_QUOTES, 'UTF-8') ?></blockquote>
    <?php endif; ?>
    <p>tilderadio is internet radio streamed by / for users of the <a href="https://tildeverse.org/">tildeverse</a>.</p>
    <p>Want to contribute? <a href="<?= htmlspecialchars(asset('community/'), ENT_QUOTES, 'UTF-8') ?>">make a station ID, submit a jingle, or plan a one-off broadcast</a>.</p>

    <nav class="tr-home-links" aria-label="station links">
      <a href="<?= htmlspecialchars(asset('djs/'), ENT_QUOTES, 'UTF-8') ?>">djs</a>
      <a href="<?= htmlspecialchars(asset('community/'), ENT_QUOTES, 'UTF-8') ?>">community</a>
      <a href="https://tilde.chat/kiwi/#tilderadio" target="_blank" rel="noopener">#tilderadio</a>
      <a href="https://tilde.zone/@tilderadio" target="_blank" rel="me noopener">mastodon</a>
      <a href="<?= htmlspecialchars(asset('now/'), ENT_QUOTES, 'UTF-8') ?>">curl /now</a>
      <a href="<?= htmlspecialchars(asset('api/'), ENT_QUOTES, 'UTF-8') ?>">api</a>
    </nav>

    <details class="tr-home-details tr-stream-details">
      <summary>direct stream URLs</summary>
      <ul class="tr-links">
        <li><a href="https://tilderadio.org/listen">https://tilderadio.org/listen</a></li>
        <li><a href="https://tilderadio.org/listen/ogg/192k">https://tilderadio.org/listen/ogg/192k</a> (ogg 192k)</li>
        <li><a href="https://tilderadio.org/listen/ogg/320k">https://tilderadio.org/listen/ogg/320k</a> (ogg 320k)</li>
        <li><a href="https://tilderadio.org/listen/mp3/192k">https://tilderadio.org/listen/mp3/192k</a> (mp3 192k)</li>
        <li><a href="https://tilderadio.org/listen/mp3/320k">https://tilderadio.org/listen/mp3/320k</a> (mp3 320k)</li>
      </ul>
    </details>
  </section>

</div>

<div style="width: 100%; border: 0; height:0; overflow:hidden">
  <audio id="tr-audio" controls preload="none" style="width:100%">
    <source src="https://tilderadio.org/listen/ogg/192k" type="audio/ogg">
    <source src="https://tilderadio.org/listen/mp3/192k" type="audio/mpeg">
  </audio>
</div>

<script>
(function () {
  var audio       = document.getElementById('tr-audio');
  var ui          = document.getElementById('tr-player');
  var nowCard     = document.getElementById('tr-now-card');
  var play        = document.getElementById('tr-play');
  var mute        = document.getElementById('tr-mute');
  var vol         = document.getElementById('tr-vol');
  var src         = document.getElementById('tr-src');
  var npEl        = document.getElementById('tr-np-text');
  var artEl       = document.getElementById('tr-art');
  var modeEl      = document.getElementById('tr-mode');
  var listenersEl = document.getElementById('tr-listeners-label');
  var djAutoEl    = document.getElementById('tr-dj-auto');
  var djLinkEl    = document.getElementById('tr-dj-link');
  var contextEl   = document.getElementById('tr-dj-context');
  var historyEl   = document.getElementById('tr-history-list');

  if (!audio || !ui) return;
  audio.classList.add('tr-hidden');

  function syncPlayingClass() {
    ui.classList.toggle('is-playing', !audio.paused);
  }

  function setPlayLabel() { play.textContent = audio.paused ? '▶ play' : '⏸ pause'; }
  function setMuteLabel() { mute.textContent = audio.muted ? '🔇 unmute' : '🔈 mute'; }

  function safeArtUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
      var url = new URL(value, window.location.href);
      return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : null;
    } catch (error) {
      return null;
    }
  }

  function setArtwork(value) {
    if (!artEl) return;
    var fallback = artEl.getAttribute('data-fallback') || '';
    var art = safeArtUrl(value);
    artEl.classList.toggle('is-fallback', !art);
    artEl.src = art || fallback;
  }

  function setBroadcastMode(payload) {
    var isLive = Boolean(payload && payload.is_live && typeof payload.dj === 'string' && payload.dj.trim() !== '');
    var profile = payload && payload.dj_profile && typeof payload.dj_profile === 'object'
      ? payload.dj_profile
      : null;

    if (modeEl) {
      modeEl.textContent = isLive ? 'LIVE DJ' : 'AUTODJ';
      modeEl.classList.toggle('is-live', isLive);
      modeEl.classList.toggle('is-auto', !isLive);
    }
    if (nowCard) nowCard.classList.toggle('has-live-dj', isLive);

    if (djAutoEl) djAutoEl.hidden = isLive;
    if (djLinkEl) {
      djLinkEl.hidden = !isLive;
      if (isLive) {
        var slug = profile && typeof profile.slug === 'string' && profile.slug !== ''
          ? profile.slug
          : payload.dj.toLowerCase().replace(/_/g, '-').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        djLinkEl.textContent = profile && typeof profile.name === 'string' && profile.name.trim() !== ''
          ? profile.name
          : payload.dj;
        djLinkEl.href = 'djs/?dj=' + encodeURIComponent(slug);
      }
    }

    var context = null;
    if (isLive && profile) {
      if (typeof profile.show_title === 'string' && profile.show_title.trim() !== '') {
        context = profile.show_title.trim();
      } else if (typeof profile.tagline === 'string' && profile.tagline.trim() !== '') {
        context = profile.tagline.trim();
      }
    }
    if (contextEl) {
      contextEl.hidden = !context;
      contextEl.textContent = context || '';
    }
  }

  function setListenerLabel(value) {
    if (!listenersEl) return;
    if (typeof value !== 'number') {
      listenersEl.textContent = 'listeners unavailable';
      return;
    }
    listenersEl.textContent = value + ' listening';
  }

  play.addEventListener('click', function () {
    if (audio.paused) audio.play().catch(function(){});
    else audio.pause();
    setPlayLabel();
    syncPlayingClass();
  });

  audio.addEventListener('play', function () { setPlayLabel(); syncPlayingClass(); });
  audio.addEventListener('pause', function () { setPlayLabel(); syncPlayingClass(); });

  mute.addEventListener('click', function () {
    audio.muted = !audio.muted;
    setMuteLabel();
  });

  vol.addEventListener('input', function () {
    audio.volume = parseFloat(this.value);
    if (audio.volume === 0 && !audio.muted) audio.muted = true;
    if (audio.volume > 0 && audio.muted) audio.muted = false;
    setMuteLabel();
  });

  src.addEventListener('change', function () {
    var wasPlaying = !audio.paused;
    audio.src = this.value;
    audio.load();
    if (wasPlaying) audio.play().catch(function(){});
  });

  if (artEl) {
    artEl.addEventListener('error', function () {
      if (artEl.classList.contains('is-fallback')) return;
      artEl.classList.add('is-fallback');
      artEl.src = artEl.getAttribute('data-fallback') || '';
    });
  }

  function renderHistory(history) {
    if (!historyEl || !Array.isArray(history)) return;
    historyEl.replaceChildren();

    if (history.length === 0) {
      var empty = document.createElement('li');
      var emptyText = document.createElement('em');
      emptyText.textContent = 'recent track history is temporarily unavailable.';
      empty.appendChild(emptyText);
      historyEl.appendChild(empty);
      return;
    }

    history.slice(0, 5).forEach(function (track) {
      var item = document.createElement('li');
      var title = document.createElement('span');
      title.textContent = (track && typeof track.text === 'string' && track.text.trim() !== '')
        ? track.text
        : 'unknown';
      item.appendChild(title);

      if (track && typeof track.played_at === 'number') {
        var time = document.createElement('time');
        var played = new Date(track.played_at * 1000);
        time.dateTime = played.toISOString();
        time.textContent = played.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        time.title = played.toUTCString();
        item.appendChild(time);
      }

      historyEl.appendChild(item);
    });
  }

  function localizeSchedule() {
    var zoneLabel = document.getElementById('tr-timezone-label');
    try {
      var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (zoneLabel && zone) zoneLabel.textContent = zone;
    } catch (error) {}

    document.querySelectorAll('[data-local-start]').forEach(function (node) {
      var timestamp = Number(node.getAttribute('data-local-start')) * 1000;
      if (!Number.isFinite(timestamp)) return;
      var date = new Date(timestamp);
      node.textContent = date.toLocaleString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
      node.title = date.toUTCString();
    });
  }

  function updateCountdowns() {
    document.querySelectorAll('[data-countdown]').forEach(function (node) {
      var timestamp = Number(node.getAttribute('data-countdown')) * 1000;
      if (!Number.isFinite(timestamp)) return;
      var seconds = Math.max(0, Math.floor((timestamp - Date.now()) / 1000));
      if (seconds < 60) {
        node.textContent = 'starting now';
        return;
      }
      var minutes = Math.floor(seconds / 60);
      if (minutes < 60) {
        node.textContent = 'in ' + minutes + 'm';
        return;
      }
      var hours = Math.floor(minutes / 60);
      if (hours < 24) {
        node.textContent = 'in ' + hours + 'h ' + (minutes % 60) + 'm';
        return;
      }
      var days = Math.floor(hours / 24);
      node.textContent = 'in ' + days + 'd ' + (hours % 24) + 'h';
    });
  }

  function updateNP() {
    fetch('?json=np&_=' + Date.now(), {cache: 'no-store'})
      .then(function (response) {
        if (!response.ok) throw new Error('http ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        var title = payload && typeof payload.title === 'string' && payload.title.trim() !== ''
          ? payload.title
          : null;
        if (npEl) npEl.textContent = title || 'unknown';
        setListenerLabel(payload ? payload.listeners : null);
        setBroadcastMode(payload || {});
        setArtwork(payload ? payload.art : null);
        if (payload && Array.isArray(payload.history)) renderHistory(payload.history);
      })
      .catch(function () {});
  }

  setPlayLabel();
  setMuteLabel();
  syncPlayingClass();
  localizeSchedule();
  updateCountdowns();
  updateNP();
  setInterval(updateNP, 15000);
  setInterval(updateCountdowns, 30000);
})();
</script>

<?php include 'footer.php'; ?>

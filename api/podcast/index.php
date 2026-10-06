<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/Admin/RecordingFile.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$slug = $_GET['dj'] ?? '';
if (!is_string($slug) || ($slug !== '' && !preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $slug))) {
    http_response_code(400);
    exit;
}
$xml = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
$recordingPrefix = 'https://tilderadio.org/recordings/?id=';
try {
    $configFile = getenv('TILDERADIO_DJ_SITE_CONFIG') ?: '/etc/tilderadio/dj-auth-site.json';
    if (is_file($configFile) && is_readable($configFile) && filesize($configFile) <= 65536) {
        $settings = json_decode((string) file_get_contents($configFile), true, 16, JSON_THROW_ON_ERROR);
        $recordingPrefix = ($settings['origin'] ?? 'https://tilderadio.org') . ($settings['base_path'] ?? '') . '/recordings/?id=';
    }
} catch (Throwable) {
    // External recording feeds remain available if optional private configuration is unavailable.
}
$archive = tr_episode_archive();
header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"><channel>
<title><?= $xml('TildeRadio' . ($slug !== '' ? ' — ' . $slug : '') . ' recordings') ?></title>
<link>https://tilderadio.org/episodes/</link>
<description>Published TildeRadio broadcast recordings.</description>
<language>en</language>
<?php foreach ($archive['episodes'] as $episode) :
    $url = $episode['recording_url'] ?? '';
    $parts = is_string($url) ? parse_url($url) : false;
    if (($slug !== '' && ($episode['dj_slug'] ?? '') !== $slug) || empty($episode['ended_at']) || !empty($episode['is_live'])
        || ($episode['status'] ?? '') === 'planned' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        continue;
    }
    $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
    $mime = match ($extension) { 'ogg', 'oga', 'opus' => 'audio/ogg', 'm4a', 'mp4' => 'audio/mp4', 'wav' => 'audio/wav', 'flac' => 'audio/flac', default => 'audio/mpeg' };
    if (str_starts_with($url, $recordingPrefix) && preg_match('~/recordings/\?id=([a-f0-9]{64})\z~D', $url, $local) && class_exists('finfo')) {
        try {
            $recording = \TildeRadio\Site\Admin\RecordingFile::approved($local[1]);
            $actualType = $recording !== null ? (new finfo(FILEINFO_MIME_TYPE))->file($recording['path']) : false;
            if ($actualType === false || !in_array($actualType, ['audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'video/ogg', 'application/ogg'], true)) {
                continue;
            }
            $mime = $actualType;
        } catch (Throwable) {
            continue;
        }
    }
    ?>
<item><title><?= $xml(tr_episode_title($episode)) ?></title>
<link><?= $xml('https://tilderadio.org/episodes/?id=' . (int) $episode['id']) ?></link>
<guid isPermaLink="false">tilderadio-set-<?= (int) $episode['id'] ?></guid>
<pubDate><?= $xml(gmdate(DATE_RSS, (int) $episode['started_at'])) ?></pubDate>
<description><?= $xml((string) ($episode['show']['description'] ?? $episode['show']['note'] ?? '')) ?></description>
<enclosure url="<?= $xml($url) ?>" length="<?= max(0, (int) ($episode['recording_bytes'] ?? 0)) ?>" type="<?= $xml($mime) ?>" />
</item>
<?php endforeach; ?>
</channel></rss>

<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use PDO;
use RuntimeException;

// Native PHP keeps approved recording downloads independent of Composer and login.
final class RecordingFile
{
    /** @return array{path:string,bytes:int,prefix:?string}|null */
    public static function approved(string $token, bool $preview = false): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return null;
        }
        $configFile = getenv('TILDERADIO_DJ_SITE_CONFIG') ?: '/etc/tilderadio/dj-auth-site.json';
        $data = is_file($configFile) && filesize($configFile) <= 65536 ? json_decode((string) file_get_contents($configFile), true, 16, JSON_THROW_ON_ERROR) : null;
        $settings = $data['carrier']['recordings'] ?? null;
        $directory = is_array($settings) ? ($settings['directory'] ?? null) : null;
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $private = is_string($directory) && str_starts_with($directory, '/') ? realpath($directory) : false;
        if ($private === false || is_link($directory) || $private === $root || str_starts_with($private, $root . '/') || (fileperms($private) & 0077) !== 0) {
            return null;
        }
        $database = realpath(PublicProfiles::databasePath());
        if ($database === false || $database === $root || str_starts_with($database, $root . '/')) {
            return null;
        }
        $uri = str_replace('%2F', '/', rawurlencode($database));
        $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
        $url = ($data['origin'] ?? '') . ($data['base_path'] ?? '') . '/recordings/?id=' . $token;
        $query = $db->prepare("SELECT c.status,b.json,b.deleted,c.broadcast_id FROM recording_candidates c LEFT JOIN broadcast_edits b ON b.id=c.broadcast_id WHERE c.url=?");
        $query->execute([$url]);
        $candidate = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($candidate)) {
            return null;
        }
        $patch = is_string($candidate['json']) ? json_decode($candidate['json'], true, 32, JSON_THROW_ON_ERROR) : [];
        if (!$preview && ($candidate['status'] !== 'approved' || !empty($candidate['deleted']) || ($patch['recording_url'] ?? '') !== $url)) {
            return null;
        }
        $path = $private . '/' . $token . '.audio';
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $prefix = is_string($settings['x_accel_prefix'] ?? null) ? $settings['x_accel_prefix'] : null;
        if ($prefix !== null && !preg_match('~\A/[a-zA-Z0-9/_-]+/\z~D', $prefix)) {
            throw new RuntimeException('Invalid internal recording prefix.');
        }
        return ['path' => $path, 'bytes' => (int) filesize($path), 'prefix' => $prefix];
    }

    /** @param array{path:string,bytes:int,prefix:?string} $recording */
    public static function send(array $recording): never
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }
        $file = fopen($recording['path'], 'rb');
        if ($file === false) {
            http_response_code(503);
            exit;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($recording['path']);
        $allowed = ['audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'video/ogg', 'application/ogg'];
        if (!in_array($type, $allowed, true)) {
            fclose($file);
            http_response_code(415);
            exit;
        }
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Security-Policy: sandbox');
        if ($recording['prefix'] !== null) {
            fclose($file);
            header('X-Accel-Redirect: ' . $recording['prefix'] . basename($recording['path']));
            exit;
        }
        $bytes = $recording['bytes'];
        $start = 0;
        $end = $bytes - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        if (is_string($range)) {
            if (!preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $bytes);
                fclose($file);
                exit;
            }
            if ($match[1] === '') {
                $start = max(0, $bytes - (int) $match[2]);
            } else {
                $start = (int) $match[1];
            }
            $end = $match[2] === '' || $match[1] === '' ? $end : min($end, (int) $match[2]);
            if ($start > $end || $start >= $bytes) {
                http_response_code(416);
                header('Content-Range: bytes */' . $bytes);
                fclose($file);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $bytes);
        }
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        if ($method !== 'HEAD') {
            fseek($file, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !connection_aborted()) {
                $chunk = fread($file, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        }
        fclose($file);
        exit;
    }
}

<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

/**
 * @phpstan-type DirectoryEntry array{id:int,username:string,display_name:string,active:bool}
 */
final readonly class ScheduleApi implements ScheduleTransport
{
    private string $base;
    private string $key;
    private ?string $ca;
    /** @var list<int> */
    private array $stations;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings)
    {
        $url = $settings['base_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new Problem('The private schedule API URL must use HTTPS.', 503);
        }
        $this->base = rtrim($url, '/') . '/api';
        $file = self::privateFile($settings['key_file'] ?? null);
        if (filesize($file) > 1024 || (fileperms($file) & 0007) !== 0) {
            throw new Problem('The schedule API key file must be private and readable by PHP-FPM.', 503);
        }
        $key = file_get_contents($file);
        if (!is_string($key) || !preg_match('/\A[\x21-\x7e]{16,512}\z/D', trim($key))) {
            throw new Problem('The private schedule API key is invalid.', 503);
        }
        $this->key = trim($key);
        $ids = $settings['station_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new Problem('Configure the station IDs allowed for schedule editing.', 503);
        }
        $stations = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new Problem('Schedule API station IDs must be positive JSON integers.', 503);
            }
            $stations[] = Input::integer($id, 'allowed station ID');
        }
        $this->stations = array_values(array_unique($stations));
        $this->ca = isset($settings['ca_file']) ? self::privateFile($settings['ca_file']) : null;
    }

    private static function privateFile(mixed $file): string
    {
        $resolved = is_string($file) ? realpath($file) : false;
        $root = dirname(__DIR__, 2);
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? $root;
        if (!is_string($file) || !str_starts_with($file, '/') || $resolved === false
            || !is_file($resolved) || !is_readable($resolved)
            || str_starts_with($file, $root . '/') || str_starts_with($resolved, $root . '/')
            || (is_string($docRoot) && $docRoot !== ''
                && (str_starts_with($file, rtrim($docRoot, '/') . '/') || str_starts_with($resolved, rtrim($docRoot, '/') . '/')))) {
            throw new Problem('Schedule API credential and CA files must be outside the website.', 503);
        }

        return $resolved;
    }

    public function timezone(int $station): string
    {
        $this->allowed($station);
        $data = $this->request('GET', '/station/' . $station);
        try {
            return Input::timezone($data['timezone'] ?? null);
        } catch (Problem) {
            throw new Problem('AzuraCast did not return a usable station timezone. Check its installed API documentation.', 502);
        }
    }

    public function allows(int $station): bool
    {
        return in_array($station, $this->stations, true);
    }

    /** @return list<DirectoryEntry> */
    public function streamers(int $station, int $timeout = 15): array
    {
        $this->allowed($station);
        $deadline = microtime(true) + max(1, min(15, $timeout));
        $entries = [];
        $pages = 1;
        $total = null;
        $perPage = 25;
        for ($page = 1; $page <= $pages; ++$page) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                throw new Problem('DJ lookup took too long. Reload or use manual entry.', 502);
            }
            try {
                $data = $this->request('GET', '/station/' . $station . '/streamers?per_page=' . $perPage . '&page=' . $page, null, min(10, $remaining), true);
            } catch (\LengthException) {
                if ($perPage === 1) {
                    throw new Problem('The DJ directory still exceeds the 2 MiB safety limit with one DJ requested. Use manual entry and check the installed AzuraCast API.', 502);
                }
                $perPage = max(1, intdiv($perPage, 2));
                // Changing page size changes offsets. Restart so no DJs are skipped or duplicated.
                $entries = [];
                $pages = 1;
                $total = null;
                $page = 0;
                continue;
            }
            if (array_is_list($data)) {
                if ($page !== 1) {
                    throw new Problem('AzuraCast returned inconsistent DJ pagination.', 502);
                }
                $rows = $data;
            } else {
                $rows = $data['rows'] ?? null;
                $count = $data['total_pages'] ?? null;
                $expected = $data['total'] ?? null;
                if (($data['page'] ?? null) !== $page || !is_int($count) || $count < 0 || $count > 5000
                    || !is_int($expected) || $expected < 0 || $expected > 5000
                    || !is_array($rows) || !array_is_list($rows)
                    || ($count === 0 && ($rows !== [] || ($data['total'] ?? null) !== 0))
                    || ($page !== 1 && ($count !== $pages || $expected !== $total))) {
                    throw new Problem('AzuraCast returned unsupported DJ pagination.', 502);
                }
                $pages = max(1, $count);
                $total = $expected;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new Problem('AzuraCast returned an unsupported DJ directory.', 502);
                }
                $entry = self::directoryEntry($row, $station);
                if (isset($entries[$entry['id']]) || count($entries) >= 5000) {
                    throw new Problem('The DJ directory is too large or changed while loading. Reload it.', 502);
                }
                // Only these safe fields leave the API client; credentials and schedules are discarded.
                $entries[$entry['id']] = $entry;
            }
        }
        if ($total !== null && count($entries) !== $total) {
            throw new Problem('The DJ directory changed while loading. Refresh the DJ list.', 502);
        }
        $entries = array_values($entries);
        usort($entries, static fn (array $a, array $b): int => strnatcasecmp($a['username'], $b['username']));

        return $entries;
    }

    /** @return DirectoryEntry */
    public function streamerIdentity(int $station, int $streamer): array
    {
        $this->allowed($station);
        Input::integer($streamer, 'streamer ID');
        $entry = self::directoryEntry($this->request('GET', '/station/' . $station . '/streamer/' . $streamer), $station);
        if ($entry['id'] !== $streamer) {
            throw new Problem('AzuraCast returned a different DJ identity. Reload the DJ list.', 502);
        }
        if (!$entry['active']) {
            throw new Problem('This DJ is inactive in AzuraCast. Activate their streaming account there before selecting it.');
        }

        return $entry;
    }

    /**
     * @param array<array-key,mixed> $row
     * @return DirectoryEntry
     */
    private static function directoryEntry(array $row, int $station): array
    {
        if (!is_int($row['id'] ?? null) || $row['id'] < 1 || $row['id'] > 2147483647
            || !is_string($row['streamer_username'] ?? null) || trim($row['streamer_username']) !== $row['streamer_username']
            || !is_string($row['display_name'] ?? '') || !is_bool($row['is_active'] ?? null)
            || (array_key_exists('station_id', $row) && $row['station_id'] !== $station)) {
            throw new Problem('AzuraCast returned an unsupported DJ identity.', 502);
        }
        try {
            $username = Input::text($row['streamer_username'], 'DJ username', 50, true);
            $display = Input::text($row['display_name'] ?? '', 'DJ display name', 255);
        } catch (Problem) {
            throw new Problem('AzuraCast returned an invalid DJ name.', 502);
        }

        return ['id' => $row['id'], 'username' => $username, 'display_name' => $display === '' ? $username : $display, 'active' => $row['is_active']];
    }

    public function streamer(int $station, int $streamer): array
    {
        $this->allowed($station);
        Input::integer($streamer, 'streamer ID');
        $data = $this->request('GET', '/station/' . $station . '/streamer/' . $streamer);
        if (($data['id'] ?? null) !== $streamer || !is_string($data['streamer_username'] ?? null)
            || (isset($data['station_id']) && $data['station_id'] !== $station)
            || !is_array($data['schedule_items'] ?? null) || !array_is_list($data['schedule_items'])
            || count($data['schedule_items']) > 100) {
            throw new Problem('AzuraCast returned an unsupported DJ schedule. Check its installed API documentation.', 502);
        }
        $items = [];
        foreach ($data['schedule_items'] as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] < 1) {
                throw new Problem('AzuraCast returned an unsupported schedule entry.', 502);
            }
            // Preserve all schedule settings supported by the current AzuraCast schedule repository.
            $row = [];
            foreach (['id', 'start_time', 'end_time', 'start_date', 'end_date', 'days', 'loop_once', 'prevent_requests', 'reset_queue_at_start', 'reset_queue_recursive'] as $key) {
                if (array_key_exists($key, $item)) {
                    $row[$key] = $item[$key];
                }
            }
            ScheduleRules::validateStored($row);
            $items[] = $row;
        }

        return ['id' => $streamer, 'username' => $data['streamer_username'], 'schedule_items' => $items];
    }

    public function save(int $station, int $streamer, array $items): void
    {
        $this->allowed($station);
        Input::integer($streamer, 'streamer ID');
        if (count($items) > 100) {
            throw new Problem('This editor supports at most 100 entries per DJ.');
        }
        foreach ($items as $item) {
            ScheduleRules::validateStored($item);
        }
        // Sending only schedule_items leaves credentials, activity and enforce_schedule settings untouched.
        $this->request('PUT', '/station/' . $station . '/streamer/' . $streamer, ['schedule_items' => $items]);
    }

    private function allowed(int $station): void
    {
        if (!$this->allows($station)) {
            throw new Problem('This station is not enabled for schedule editing in the private server configuration.', 403);
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<array-key,mixed>
     */
    private function request(string $method, string $path, ?array $body = null, int $timeout = 10, bool $retryOnLimit = false): array
    {
        $curl = curl_init($this->base . $path);
        if ($curl === false) {
            throw new Problem('The schedule API connection could not be opened.', 502);
        }
        $response = '';
        $tooLarge = false;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'tilderadio-site-admin/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->key],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 2097152) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($this->ca !== null) {
            $options[CURLOPT_CAINFO] = $this->ca;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        unset($curl);
        if ($tooLarge) {
            if ($retryOnLimit && $status >= 200 && $status < 300) {
                throw new \LengthException('AzuraCast API page exceeded its response limit.');
            }
            throw new Problem('The AzuraCast API response exceeded the 2 MiB safety limit. Use manual entry or reduce the upstream record size.', 502);
        }
        if ($ok === false) {
            throw new Problem('The schedule API response could not be confirmed. Reload the schedule before retrying.', 502);
        }
        if (in_array($status, [401, 403], true)) {
            throw new Problem('The schedule API key needs permission to manage streamers in this station.', 502);
        }
        if ($status === 404) {
            throw new Problem('The station or streamer was not found in AzuraCast. Check the assigned IDs.', 404);
        }
        if ($status < 200 || $status >= 300) {
            throw new Problem('AzuraCast did not accept the schedule request. Reload its current schedule before retrying.', 502);
        }
        if ($method === 'PUT' && $response === '') {
            return [];
        }
        try {
            $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem('AzuraCast returned an invalid schedule response.', 502);
        }
        if (!is_array($data)) {
            throw new Problem('AzuraCast returned an invalid schedule response.', 502);
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin\Tests;

use PHPUnit\Framework\TestCase;
use TildeRadio\Site\Admin\DjDirectory;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\ScheduleApi;
use TildeRadio\Site\DjAuth\Config;

final class DjDirectoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dj-directory-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
        file_put_contents($this->directory . '/client.php', '<?php');
        file_put_contents($this->directory . '/client.json', '{}');
        file_put_contents($this->directory . '/api-key', 'test-private-directory-key');
        chmod($this->directory . '/api-key', 0600);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        rmdir($this->directory . '/sessions');
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        $_SESSION = [];
    }

    private function config(?array $api = null): Config
    {
        return new Config([
            'origin' => 'https://tilderadio.org', 'state_dir' => $this->directory,
            'client_bootstrap' => $this->directory . '/client.php',
            'client_config' => $this->directory . '/client.json', 'schedule_api' => $api,
        ]);
    }

    private function settings(): array
    {
        return ['base_url' => 'https://azuracast.example.invalid', 'key_file' => $this->directory . '/api-key', 'station_ids' => [1]];
    }

    public function testMissingApiConfigFallsBackWithoutInitializingAnyWebsiteRecords(): void
    {
        $result = (new DjDirectory($this->config()))->choices(1);
        self::assertFalse($result['available']);
        self::assertSame([], $result['entries']);
        self::assertStringContainsString('Manual entry', $result['error']);
        self::assertFileDoesNotExist($this->directory . '/admin.sqlite');
    }

    public function testSafeNameCacheIsScopedToConfigurationAndNeverBypassesAllowlist(): void
    {
        $settings = $this->settings();
        $entry = ['id' => 4, 'username' => 'cat', 'display_name' => 'Cat', 'active' => true];
        $signature = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
        $_SESSION['admin_dj_directory'][$signature . ':1'] = ['at' => time(), 'entries' => [$entry]];
        $directory = new DjDirectory($this->config($settings));
        self::assertSame([$entry], $directory->choices(1)['entries']);
        self::assertFalse($directory->choices(2)['available']);
        $settings['station_ids'] = [2];
        self::assertFalse((new DjDirectory($this->config($settings)))->choices(1)['available']);
    }

    public function testDirectoryAllowlistIsEnforcedBeforeAnyNetworkRequest(): void
    {
        $api = new ScheduleApi($this->settings());
        self::assertTrue($api->allows(1));
        self::assertFalse($api->allows(2));
        try {
            $api->streamerIdentity(2, 4);
            self::fail('Disallowed station must be rejected.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }
        $this->expectException(Problem::class);
        $api->streamers(2);
    }
}

<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TildeRadio\Site\Admin\PlanForm;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\PublicCarrier;
use TildeRadio\Site\Admin\PublicPlans;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\Config;

final class CarrierTest extends TestCase
{
    private string $directory;
    private Config $config;
    private Store $store;
    private array $admin = ['station_id' => 1, 'streamer_id' => 163];
    private array $cat = ['station_id' => 1, 'streamer_id' => 4];
    private array $other = ['station_id' => 1, 'streamer_id' => 9];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/carrier-php-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
        mkdir($this->directory . '/repo/data/djs', 0700, true);
        file_put_contents($this->directory . '/client.php', '<?php');
        file_put_contents($this->directory . '/client.json', '{}');
        foreach (['cat', 'deepend', 'other'] as $slug) {
            file_put_contents($this->directory . '/repo/data/djs/' . $slug . '.json', json_encode(['name' => $slug, 'published' => true, 'custom' => ['kept' => true]]));
        }
        $this->config = new Config(['origin' => 'https://tilderadio.org', 'state_dir' => $this->directory,
            'client_bootstrap' => $this->directory . '/client.php', 'client_config' => $this->directory . '/client.json',
            'accounts' => ['1:163' => 'deepend', '1:4' => 'cat', '1:9' => 'other'], 'administrators' => ['1:163']]);
        $this->store = new Store($this->config, new NullLogger(), $this->directory . '/repo');
        $start = time() - 7200;
        file_put_contents($this->directory . '/episodes.json', json_encode(['version' => 1, 'generated_at' => 1, 'episodes' => [[
            'id' => 31, 'dj' => 'cat', 'dj_slug' => 'cat', 'started_at' => $start, 'ended_at' => $start + 3600,
            'show' => ['episode' => 'Original'], 'tracks' => [['text' => 'Preserved original playback']], 'peak_listeners' => 99,
        ]]]));
        putenv('TILDERADIO_EPISODES_FILE=' . $this->directory . '/episodes.json');
        putenv('TILDERADIO_DJ_ADMIN_DB=' . $this->directory . '/admin.sqlite');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        putenv('TILDERADIO_EPISODES_FILE');
        putenv('TILDERADIO_DJ_ADMIN_DB');
        putenv('TILDERADIO_DJ_SITE_CONFIG');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function plan(array $extra = []): array
    {
        return PlanForm::decode(array_replace(['starts_at' => gmdate('Y-m-d\TH:i', time() + 600), 'ends_at' => gmdate('Y-m-d\TH:i', time() + 4200),
            'episode' => 'Prepared set', 'playlist' => "Artist | Song\nOther artist\tOther song"], $extra));
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            self::fail('Unauthorized or stale operation succeeded.');
        } catch (Problem $problem) {
            self::assertSame($status, $problem->status);
        }
    }

    public function testPlansUseStableOwnersAndRejectCrossDjWritesAndStaleVersions(): void
    {
        $id = $this->store->savePlan($this->cat, null, $this->cat, 1, $this->plan(), 0);
        self::assertCount(1, $this->store->plans($this->cat));
        self::assertSame([], $this->store->plans($this->other));
        $this->denied(fn () => $this->store->savePlan($this->other, $id, $this->cat, 1, $this->plan(), 1), 403);
        $this->denied(fn () => $this->store->savePlan($this->cat, null, $this->other, 1, $this->plan(), 0), 403);
        $this->store->savePlan($this->cat, $id, $this->cat, 1, $this->plan(['episode' => 'Updated']), 1);
        $this->denied(fn () => $this->store->savePlan($this->cat, $id, $this->cat, 1, $this->plan(), 1), 409);
        self::assertSame('Updated', $this->store->plan($id)['show']['episode']);
    }

    public function testUsedPreparationIsImmutableAndCancellationPreservesItsRecord(): void
    {
        $id = $this->store->savePlan($this->cat, null, $this->cat, 1, $this->plan(), 0);
        $this->store->recordPlanUses([['plan_id' => $id, 'session_id' => 31]]);
        $this->denied(fn () => $this->store->savePlan($this->cat, $id, $this->cat, 1, $this->plan(), 1), 409);
        $this->store->cancelPlan($this->cat, $id, 1, 'CANCEL');
        self::assertTrue($this->store->plan($id)['deleted']);
        self::assertSame(31, $this->store->plan($id)['broadcast_id']);
        self::assertCount(2, $this->store->plan($id)['tracks']);
    }

    public function testMigrationAndSnapshotsPreserveProfilesAndCapturedExports(): void
    {
        $before = file_get_contents($this->directory . '/episodes.json');
        $account = $this->store->requireAccount(1, 4);
        new Store($this->config, new NullLogger(), $this->directory . '/repo');
        self::assertSame($account, $this->store->requireAccount(1, 4));
        self::assertTrue($this->store->profile('cat')['data']['custom']['kept']);
        $snapshot = $this->store->carrierRecords();
        self::assertCount(3, $snapshot);
        self::assertStringNotContainsString('client_config', json_encode($snapshot));
        self::assertStringNotContainsString('password', json_encode($snapshot));
        self::assertSame($before, file_get_contents($this->directory . '/episodes.json'));
    }

    public function testPrivatePlaylistDoesNotLeakInPublicPreparationFeed(): void
    {
        $id = $this->store->savePlan($this->cat, null, $this->cat, 1, $this->plan(), 0);
        self::assertSame([], PublicPlans::upcoming());
        $this->store->savePlan($this->cat, $id, $this->cat, 1, $this->plan(['public' => '1']), 1);
        $public = PublicPlans::upcoming();
        self::assertCount(1, $public);
        self::assertArrayNotHasKey('tracks', $public[0]);
        self::assertArrayNotHasKey('actor', $public[0]);
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat + ['username' => 'cat', 'display_name' => 'cat', 'verified_at' => time()], '', 'cat', false, 'dj', [1 => 4], $account['version']);
        self::assertSame([], PublicPlans::upcoming());
    }

    public function testRecordingReviewIsScopedAndPreservesTracksAndOriginalSource(): void
    {
        $before = file_get_contents($this->directory . '/episodes.json');
        $this->store->importRecordings($this->admin, [['broadcast_id' => 31, 'url' => 'https://example.org/cat.mp3', 'bytes' => 1024]]);
        self::assertSame([], $this->store->recordingCandidates($this->other));
        $candidate = $this->store->recordingCandidates($this->cat)[0];
        $this->denied(fn () => $this->store->reviewRecording($this->other, $candidate['id'], true, 1, 0, $candidate['source_hash']), 403);
        $this->store->reviewRecording($this->cat, $candidate['id'], true, 1, 0, $candidate['source_hash']);
        $edit = $this->store->broadcastEdit(31);
        self::assertSame(['recording_url' => 'https://example.org/cat.mp3', 'recording_bytes' => 1024], $edit['patch']);
        self::assertSame([['text' => 'Preserved original playback']], $edit['source']['tracks']);
        self::assertSame($before, file_get_contents($this->directory . '/episodes.json'));
        $this->denied(fn () => $this->store->reviewRecording($this->cat, $candidate['id'], true, 1, 0, $candidate['source_hash']), 409);
        $this->store->importRecordings($this->admin, [['broadcast_id' => 31, 'url' => 'https://example.org/cat.mp3', 'bytes' => 1024]]);
        self::assertSame([], $this->store->recordingCandidates($this->cat));
    }

    public function testMalformedPlaylistsUnsafeLinksAndInvalidWindowsFail(): void
    {
        $this->denied(fn () => $this->plan(['playlist' => 'Missing separator']), 422);
        $this->denied(fn () => $this->plan(['link' => 'javascript:alert(1)']), 422);
        $this->denied(fn () => $this->plan(['ends_at' => '2020-01-01T00:00']), 422);
        $this->denied(fn () => $this->store->importRecordings($this->cat, []), 403);
    }

    public function testPublicSongSnapshotRequiresFreshMatchingBroadcastAndPreservesRawMetadata(): void
    {
        $path = $this->directory . '/carrier-live.json';
        $config = $this->directory . '/public.json';
        file_put_contents($config, json_encode(['carrier' => ['enabled' => true, 'public_state_file' => $path]]));
        putenv('TILDERADIO_DJ_SITE_CONFIG=' . $config);
        $now = ['is_live' => true, 'station' => ['id' => 1], 'broadcast_start' => time() - 10, 'now_playing' => ['text' => 'Original captured song']];
        $snapshot = ['is_live' => true, 'station_id' => 1, 'broadcast_start' => $now['broadcast_start'], 'generated_at' => time(), 'tracking' => 'planned', 'position' => 2,
            'current_track' => ['artist' => 'Prepared artist', 'title' => 'Prepared title', 'played_at' => time() - 5]];
        file_put_contents($path, json_encode($snapshot));
        $display = PublicCarrier::now($now);
        self::assertSame($now['now_playing'], $display['captured_now_playing']);
        self::assertSame('Prepared title', $display['now_playing']['title']);
        self::assertSame(2, $display['playlist_position']);
        foreach (['station_id' => 2, 'broadcast_start' => $now['broadcast_start'] - 1, 'generated_at' => time() - 61, 'tracking' => 'metadata'] as $field => $value) {
            file_put_contents($path, json_encode(array_replace($snapshot, [$field => $value])));
            self::assertSame($now, PublicCarrier::now($now));
        }
        unlink($path);
        self::assertSame($now, PublicCarrier::now($now));
    }
}

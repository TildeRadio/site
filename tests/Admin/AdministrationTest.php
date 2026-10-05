<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\ProfileForm;
use TildeRadio\Site\Admin\ProfileValidator;
use TildeRadio\Site\Admin\PublicProfiles;
use TildeRadio\Site\Admin\ScheduleRules;
use TildeRadio\Site\Admin\ScheduleService;
use TildeRadio\Site\Admin\ScheduleTransport;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\Config;

final class AdministrationTest extends TestCase
{
    private string $directory;
    private Store $store;
    private array $admin = ['station_id' => 1, 'streamer_id' => 163];
    private array $cat = ['station_id' => 1, 'streamer_id' => 4, 'username' => 'cat', 'display_name' => 'Cat', 'verified_at' => 1];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dj-admin-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
        mkdir($this->directory . '/repository', 0700);
        mkdir($this->directory . '/repository/data', 0700);
        mkdir($this->directory . '/repository/data/djs', 0700);
        file_put_contents($this->directory . '/client.php', '<?php');
        file_put_contents($this->directory . '/client.json', '{}');
        file_put_contents($this->directory . '/repository/data/djs/legacy.json', '{"name":"Legacy DJ","published":true,"show":{"title":"Original","formats":[{"id":"late","title":"Late show","days":["Saturday"]}]},"custom":{"kept":true}}');
        $this->store = new Store($this->config(), new NullLogger(), $this->directory . '/repository');
        $this->store->observe($this->cat);
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function config(array $extra = []): Config
    {
        return new Config(array_replace([
            'origin' => 'https://tilderadio.org', 'state_dir' => $this->directory,
            'client_bootstrap' => $this->directory . '/client.php',
            'client_config' => $this->directory . '/client.json', 'administrators' => ['1:163'],
        ], $extra));
    }

    private function rejects(callable $call, int $status): void
    {
        try {
            $call();
            self::fail('Expected a rejected administrator operation.');
        } catch (Problem $problem) {
            self::assertSame($status, $problem->status);
        }
    }

    public function testPermissionsUseStableIdsAndProtectedAdminCannotBeDeletedOrDemoted(): void
    {
        $this->rejects(fn () => $this->store->saveStation($this->cat, 2, 'Other', 'UTC', true, 0), 403);
        self::assertNull($this->store->station(2));
        $root = $this->store->requireAccount(1, 163);
        $identity = $this->cat;
        $identity['streamer_id'] = 163;
        $this->rejects(fn () => $this->store->saveAccount($this->admin, $identity, '', null, false, 'dj', [], $root['version']), 409);
        $this->rejects(fn () => $this->store->deleteAccount($this->admin, 1, 163, $root['version'], $root['username']), 409);
        self::assertTrue($this->store->isAdministrator($this->admin));
        self::assertSame([], $this->store->audit());
    }

    public function testRootConfigRevocationIsNotCopiedIntoDatabaseRole(): void
    {
        $withoutRoot = new Store($this->config(['administrators' => []]), new NullLogger(), $this->directory . '/repository');
        self::assertFalse($withoutRoot->isAdministrator($this->admin));
    }

    public function testAccountRolesRevocationDeletionAndRestoreRemainWebsiteOnly(): void
    {
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat, 'Local label', 'legacy', true, 'admin', [1 => 4], $account['version']);
        self::assertTrue($this->store->isAdministrator($this->cat));
        $account = $this->store->requireAccount(1, 4);
        $this->rejects(fn () => $this->store->saveAccount($this->cat, $this->cat, '', null, true, 'dj', [], $account['version']), 409);
        $this->store->saveAccount($this->admin, $this->cat, 'Local label', 'legacy', false, 'dj', [1 => 4], $account['version']);
        self::assertFalse($this->store->observe($this->cat)['enabled']);
        $account = $this->store->requireAccount(1, 4);
        $this->rejects(fn () => $this->store->deleteAccount($this->admin, 1, 4, $account['version'], 'other'), 422);
        $this->store->deleteAccount($this->admin, 1, 4, $account['version'], 'cat');
        self::assertTrue($this->store->observe($this->cat)['deleted']);
        self::assertSame([], $this->store->requireAccount(1, 4)['assignments']);
        self::assertFalse($this->store->profile('legacy')['deleted']);
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat, '', 'legacy', true, 'dj', [1 => 4], $account['version']);
        self::assertFalse($this->store->requireAccount(1, 4)['deleted']);
    }

    public function testVersionChecksRejectLostUpdatesAndDoNotCreateAuditEntries(): void
    {
        $profile = $this->store->profile('legacy');
        $this->store->saveProfile($this->admin, 'legacy', ['name' => 'New'], $profile['version']);
        $count = count($this->store->audit());
        $this->rejects(fn () => $this->store->saveProfile($this->admin, 'legacy', ['name' => 'Stale'], $profile['version']), 409);
        self::assertSame('New', $this->store->profile('legacy')['data']['name']);
        self::assertCount($count, $this->store->audit());
    }

    public function testStationAssignmentsHaveExplicitTargetStreamerIdsAndDeletionGuards(): void
    {
        $this->store->saveStation($this->admin, 2, 'Second', 'America/Edmonton', true, 0);
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat, '', null, true, 'dj', [1 => 4, 2 => 99], $account['version']);
        self::assertSame(['station_id' => 2, 'streamer_id' => 99], $this->store->scheduleTarget($this->admin, 1, 4, 2));
        $this->rejects(fn () => $this->store->deleteStation($this->admin, 2, 1, 'Second'), 409);
        $this->store->saveStation($this->admin, 2, 'Second', 'UTC', false, 1);
        $this->rejects(fn () => $this->store->scheduleTarget($this->admin, 1, 4, 2), 409);
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat, 'Still editable', null, true, 'dj', $account['assignments'], $account['version']);
        $this->rejects(fn () => $this->store->saveStation($this->admin, 3, 'Bad', 'invalid/timezone', true, 0), 422);
    }

    public function testPublicOverridesTombstonesAndImportsPreserveRepositoryMetadata(): void
    {
        $root = $this->directory . '/repository';
        $profile = $this->store->profile('legacy');
        self::assertTrue($profile['data']['custom']['kept']);
        $this->store->saveProfile($this->admin, 'legacy', $profile['data'] + ['tagline' => 'Website edit'], $profile['version']);
        $public = PublicProfiles::metadata($root, $this->directory . '/admin.sqlite');
        self::assertSame('Website edit', $public['legacy']['tagline']);
        $profile = $this->store->profile('legacy');
        $this->store->deleteProfile($this->admin, 'legacy', $profile['version'], 'legacy');
        $this->store->importProfiles($this->admin, $root);
        self::assertFalse(PublicProfiles::metadata($root, $this->directory . '/admin.sqlite')['legacy']['published']);
        self::assertTrue($this->store->profile('legacy')['deleted']);
        self::assertSame('Legacy DJ', json_decode(file_get_contents($root . '/data/djs/legacy.json'), true)['name']);
        self::assertSame('Original', $this->store->profile('legacy')['data']['show']['title']);
    }

    public function testFriendlyProfileEditorPreservesFormatsUnknownFieldsAndRejectsUnsafeUrls(): void
    {
        $base = $this->store->profile('legacy')['data'];
        $post = ['name' => 'Edited', 'published' => '1', 'show_timezone' => 'America/Edmonton', 'show_formats' => json_encode($base['show']['formats']),
            'links' => [['label' => 'Home', 'url' => 'https://example.org']], 'bio' => "One\n\nTwo"];
        $data = ProfileForm::apply($base, $post);
        self::assertSame($base['show']['formats'], $data['show']['formats']);
        self::assertSame($base['custom'], $data['custom']);
        self::assertSame(['One', 'Two'], $data['bio']);
        $post['links'][0]['url'] = 'javascript:alert(1)';
        $this->rejects(fn () => ProfileForm::apply($base, $post), 422);
        $this->rejects(fn () => ProfileValidator::decode('{"name":"Unsafe","password":"secret"}'), 422);
        $this->rejects(fn () => ProfileValidator::decode('{"avatar":"//attacker.invalid/a"}'), 422);
        $this->rejects(fn () => ProfileValidator::decode('{"show":{"formats":[{"id":"a","days":["Blursday"]}]}}'), 422);
    }

    public function testUnpublishedProfilesDoNotRemovePublicBroadcastSchedule(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/radio.php';
        $records = [['slug' => 'legacy', 'name' => 'Legacy', 'title' => 'Broadcast', 'start_timestamp' => time() + 600, 'end_timestamp' => time() + 1200]];
        $metadata = ['legacy' => ['slug' => 'legacy', 'name' => 'Private profile', 'published' => false]];
        self::assertSame([], \tr_dj_catalog_from_records($records, $metadata));
        $schedule = \tr_dj_catalog_from_records($records, $metadata, true);
        self::assertTrue($schedule['legacy']['_profile_hidden']);
        self::assertNotEmpty($schedule['legacy']['upcoming']);
    }

    private function api(): ScheduleTransport
    {
        return new class () implements ScheduleTransport {
            public array $items = [
                ['id' => 10, 'start_time' => 1200, 'end_time' => 1300, 'days' => [1], 'start_date' => null, 'end_date' => null, 'prevent_requests' => true],
                ['id' => 11, 'start_time' => 2300, 'end_time' => 100, 'days' => [6], 'start_date' => null, 'end_date' => null, 'reset_queue_at_start' => true],
            ];
            public int $writes = 0;
            public function timezone(int $station): string
            {
                return 'America/Edmonton';
            }
            public function streamer(int $station, int $streamer): array
            {
                return ['id' => $streamer, 'username' => 'cat', 'schedule_items' => $this->items];
            }
            public function save(int $station, int $streamer, array $items): void
            {
                $this->writes++;
                $this->items = $items;
            }
        };
    }

    public function testScheduleEditsPreserveOtherRowsAndFlagsThenRejectStaleForms(): void
    {
        $api = $this->api();
        $service = new ScheduleService($this->store, $api, $this->directory);
        $view = $service->view($this->admin, 1, 4, 1);
        $second = $api->items[1];
        $service->save($this->admin, $view, ['action' => 'edit', 'item_id' => '10', 'start_time' => '14:00', 'end_time' => '15:00', 'days' => ['2']]);
        self::assertSame($second, $api->items[1]);
        self::assertTrue($api->items[0]['prevent_requests']);
        self::assertSame(1400, $api->items[0]['start_time']);
        $this->rejects(fn () => $service->save($this->admin, $view, ['action' => 'delete', 'item_id' => '10', 'confirm' => 'DELETE']), 409);
        self::assertSame(1, $api->writes);
        $fresh = $service->view($this->admin, 1, 4, 1);
        $service->save($this->admin, $fresh, ['action' => 'delete', 'item_id' => '10', 'confirm' => 'DELETE']);
        self::assertSame([$second], $api->items);
    }

    public function testScheduleRejectsRevokedPermissionsChangedAssignmentsAndInvalidTimes(): void
    {
        $api = $this->api();
        $service = new ScheduleService($this->store, $api, $this->directory);
        $view = $service->view($this->admin, 1, 4, 1);
        $this->rejects(fn () => $service->save(['station_id' => 1, 'streamer_id' => 99], $view, ['action' => 'add']), 403);
        $account = $this->store->requireAccount(1, 4);
        $this->store->saveAccount($this->admin, $this->cat, '', null, true, 'dj', [1 => 99], $account['version']);
        $this->rejects(fn () => $service->save($this->admin, $view, ['action' => 'add']), 409);
        $this->rejects(fn () => ScheduleRules::changes(['start_time' => '25:00', 'end_time' => '01:00']), 422);
        $this->rejects(fn () => ScheduleRules::changes(['start_time' => '01:00', 'end_time' => '01:00']), 422);
        $this->rejects(fn () => ScheduleRules::changes(['start_time' => '01:00', 'end_time' => '02:00', 'start_date' => '2026-02-30']), 422);
        self::assertSame(0, $api->writes);
    }
}

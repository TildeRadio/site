<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin\Tests;

use PHPUnit\Framework\TestCase;
use TildeRadio\Site\Admin\BroadcastForm;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\PublicBroadcasts;

final class BroadcastTest extends TestCase
{
    public function testCorrectionsPreserveNewCapturedDataNestedFormatsAndUnknownFields(): void
    {
        $source = [
            'id' => 8, 'dj_slug' => 'cat', 'started_at' => 100, 'peak_listeners' => 99,
            'show' => ['episode' => 'Old', 'format' => ['id' => 'saturday', 'genres' => ['Jazz']]],
            'tracks' => [['text' => 'New captured track']], 'future_field' => ['kept' => true],
        ];
        $edits = [['id' => 8, 'owner_slug' => 'cat', 'deleted' => false, 'source' => null,
            'patch' => ['show' => ['episode' => 'Fixed', 'format' => ['title' => 'Saturday mix']]]]];
        $merged = PublicBroadcasts::merge([$source], $edits)[0];
        self::assertSame('Fixed', $merged['show']['episode']);
        self::assertSame(['id' => 'saturday', 'genres' => ['Jazz'], 'title' => 'Saturday mix'], $merged['show']['format']);
        self::assertSame($source['tracks'], $merged['tracks']);
        self::assertSame(99, $merged['peak_listeners']);
        self::assertSame($source['future_field'], $merged['future_field']);
    }

    public function testDeletionRetainsItsSourceAndRestorationWorksAfterExportRotation(): void
    {
        $source = ['id' => 8, 'dj_slug' => 'cat', 'started_at' => 100, 'show' => ['episode' => 'Original']];
        $edit = ['id' => 8, 'owner_slug' => 'cat', 'deleted' => true, 'source' => $source, 'patch' => []];
        self::assertSame([], PublicBroadcasts::merge([$source], [$edit]));
        self::assertSame([], PublicBroadcasts::merge([], [$edit]));
        self::assertTrue(PublicBroadcasts::merge([], [$edit], true)[0]['_deleted']);
        $edit['deleted'] = false;
        self::assertSame($source, PublicBroadcasts::merge([], [$edit])[0]);
    }

    public function testPartialChangesPreserveTimestampSecondsAndAvoidFreezingTracks(): void
    {
        $start = 1791200007;
        $base = ['dj' => 'Cat', 'started_at' => $start, 'ended_at' => $start + 3600,
            'show' => ['episode' => 'Original'], 'tracks' => [['text' => 'Kept']]];
        $changes = BroadcastForm::changes($base, [
            'dj' => 'Cat', 'started_at' => gmdate('Y-m-d\TH:i', $start),
            'ended_at' => gmdate('Y-m-d\TH:i', $start + 3600), 'status' => 'recorded', 'show_episode' => 'Fixed',
        ]);
        self::assertSame(['show' => ['episode' => 'Fixed']], $changes);
    }

    public function testInvalidDatesAndUnsafeLinksAreRejected(): void
    {
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:password@example.com/'] as $url) {
            try {
                BroadcastForm::url($url);
                self::fail('Unsafe URL accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
            }
        }
        $this->expectException(Problem::class);
        BroadcastForm::date('2026-02-30T12:00', true);
    }

    public function testLegacyOwnerSlugsNormalizeWithoutChangingStableIds(): void
    {
        self::assertSame('cat-user', PublicBroadcasts::slug('Cat_User'));
        self::assertSame('cat-user', PublicBroadcasts::slug('cat-user'));
    }
}

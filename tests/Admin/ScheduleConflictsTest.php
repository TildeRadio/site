<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TildeRadio\Site\Admin\ScheduleConflicts;

final class ScheduleConflictsTest extends TestCase
{
    private static function row(int $start, int $end, array $days = [], ?string $from = null, ?string $to = null): array
    {
        return ['start_time' => $start, 'end_time' => $end, 'days' => $days, 'start_date' => $from, 'end_date' => $to];
    }

    public static function cases(): iterable
    {
        $monday = self::row(1200, 1300, [1]);
        yield 'same slot' => [$monday, $monday, true];
        yield 'partial overlap' => [$monday, self::row(1230, 1400, [1]), true];
        yield 'contains slot' => [$monday, self::row(1100, 1400, [1]), true];
        yield 'adjacent after' => [$monday, self::row(1300, 1400, [1]), false];
        yield 'adjacent before' => [$monday, self::row(1100, 1200, [1]), false];
        yield 'different weekdays' => [$monday, self::row(1200, 1300, [2]), false];
        yield 'every day' => [$monday, self::row(1230, 1400), true];
        yield 'Sunday wraps into Monday' => [$monday, self::row(2300, 1230, [7]), true];
        yield 'Saturday overnight' => [self::row(2300, 100, [6]), self::row(30, 200, [7]), true];
        yield 'overnight endpoint adjacency' => [self::row(2300, 100, [6]), self::row(100, 200, [7]), false];
        yield 'dated weeks apart' => [self::row(1200, 1300, [1], '2026-10-05', '2026-10-05'), self::row(1200, 1300, [1], '2026-10-12', '2026-10-12'), false];
        yield 'wrong weekday in date range' => [self::row(1200, 1300, [2], '2026-10-05', '2026-10-05'), $monday, false];
        yield 'inclusive last date' => [self::row(1200, 1300, [1], null, '2026-10-05'), self::row(1200, 1300, [1], '2026-10-05'), true];
        yield 'far future recurrence' => [$monday, self::row(1200, 1300, [1], '2400-01-01', '2400-12-31'), true];
        yield 'single overnight date exception' => [self::row(2300, 100, [], '2026-10-03', '2026-10-03'), self::row(30, 200, [], '2026-10-04', '2026-10-04'), true];
        yield 'multi-date overnight last night excluded' => [self::row(2300, 100, [], '2026-10-01', '2026-10-03'), self::row(30, 200, [], '2026-10-04', '2026-10-04'), false];
        yield 'multi-date overnight preceding night included' => [self::row(2300, 100, [], '2026-10-01', '2026-10-03'), self::row(30, 200, [], '2026-10-03', '2026-10-03'), true];
        yield 'zero-duration upstream entry' => [$monday, self::row(1230, 1230, [1]), false];
        yield 'spring gap normalizes into booking' => [self::row(230, 245, [], '2026-03-08', '2026-03-08'), self::row(300, 400, [], '2026-03-08', '2026-03-08'), true];
        yield 'spring reversed window normalizes overnight' => [self::row(230, 300, [], '2026-03-08', '2026-03-08'), self::row(0, 100, [], '2026-03-09', '2026-03-09'), true];
        yield 'spring gap collapses window' => [self::row(200, 300, [], '2026-03-08', '2026-03-08'), self::row(230, 245, [], '2026-03-08', '2026-03-08'), false];
        yield 'fall clocks ordinary overlap' => [self::row(100, 200, [], '2026-11-01', '2026-11-01'), self::row(130, 230, [], '2026-11-01', '2026-11-01'), true];
        yield 'overnight crosses fall clocks' => [self::row(2300, 200, [], '2026-10-31', '2026-10-31'), self::row(130, 230, [], '2026-11-01', '2026-11-01'), true];
    }

    #[DataProvider('cases')]
    public function testCalendarAndClockBoundaries(array $a, array $b, bool $expected): void
    {
        $checker = new ScheduleConflicts('America/Edmonton');
        self::assertSame($expected, $checker->overlap($a, $b) !== null);
        self::assertSame($expected, $checker->overlap($b, $a) !== null, 'Collision must be symmetric.');
    }
}

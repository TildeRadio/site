<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

/** Compare recurring calendar rules without imposing a future booking horizon. */
final class ScheduleConflicts
{
    private \DateTimeZone $zone;
    private float $deadline;
    /** @var array<string,array{days:array<int,true>,minutes:array<int,true>,shift:int}> */
    private array $transitions = [];

    public function __construct(string $timezone)
    {
        $this->zone = new \DateTimeZone($timezone);
        $this->deadline = microtime(true) + 10;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public function overlap(array $a, array $b): ?\DateTimeImmutable
    {
        ScheduleRules::validateStored($a);
        ScheduleRules::validateStored($b);
        [$aFrom, $aTo] = self::bounds($a);
        [$bFrom, $bTo] = self::bounds($b);
        if ($aFrom > $aTo || $bFrom > $bTo || $a['start_time'] === $a['end_time'] || $b['start_time'] === $b['end_time']) {
            return null;
        }
        $aStart = self::minutes($a['start_time']);
        $aEnd = self::minutes($a['end_time']) + ($a['end_time'] < $a['start_time'] ? 1440 : 0);
        $bStart = self::minutes($b['start_time']);
        $bEnd = self::minutes($b['end_time']) + ($b['end_time'] < $b['start_time'] ? 1440 : 0);
        $aDays = $a['days'] ?: range(1, 7);
        $bDays = $b['days'] ?: range(1, 7);
        // The extra day on each side also covers historical date-line changes.
        for ($offset = -2; $offset <= 2; ++$offset) {
            $from = max($aFrom, $bFrom - $offset);
            $to = min($aTo, $bTo - $offset);
            if ($from > $to) {
                continue;
            }
            $wallOverlap = $aStart < $bEnd + $offset * 1440 && $bStart + $offset * 1440 < $aEnd;
            foreach ($aDays as $weekday) {
                if (!in_array(self::wrap($weekday + $offset), $bDays, true)) {
                    continue;
                }
                $day = $from + self::wrap($weekday - self::weekday($from) + 1) - 1;
                if ($day > $to) {
                    continue;
                }
                if ($wallOverlap) {
                    // Usually the first occurrence settles the result. On a clock-change
                    // day, normalized times may collapse a window, so try the next week.
                    for ($date = $day; $date <= $to; $date += 7) {
                        $this->checkDeadline();
                        $result = $this->onDate($a, $b, $date, $offset);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                } else {
                    $changes = $this->clockChanges($aFrom - 2, $aTo + 2);
                    $aChanged = isset($changes['minutes'][$aStart]) || isset($changes['minutes'][self::minutes($a['end_time'])]);
                    $bChanged = isset($changes['minutes'][$bStart]) || isset($changes['minutes'][self::minutes($b['end_time'])]);
                    if (!$aChanged && !$bChanged) {
                        continue;
                    }
                    $shift = $changes['shift'];
                    $aCanReverse = $aChanged && ($aEnd - $aStart) * 60 < $shift;
                    $bCanReverse = $bChanged && ($bEnd - $bStart) * 60 < $shift;
                    if (!$aCanReverse && !$bCanReverse
                        && ($aStart * 60 >= ($bEnd + $offset * 1440) * 60 + $shift
                            || ($bStart + $offset * 1440) * 60 >= $aEnd * 60 + $shift)) {
                        continue;
                    }
                    // Nonexistent local times can normalize into an adjacent booking.
                    // Only transition dates need examination; ordinary weeks do not.
                    foreach ($changes['days'] as $date => $_) {
                        $this->checkDeadline();
                        if ($date < $day || $date > $to || self::weekday($date) !== $weekday) {
                            continue;
                        }
                        $result = $this->onDate($a, $b, $date, $offset);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{int,int}
     */
    private static function bounds(array $row): array
    {
        $from = self::day(!empty($row['start_date']) ? $row['start_date'] : '0000-01-01');
        $to = self::day(!empty($row['end_date']) ? $row['end_date'] : '9999-12-31');
        // AzuraCast's end date limits the overnight end, except a one-date
        // overnight show is explicitly allowed to finish the following day.
        if ($row['end_time'] < $row['start_time'] && !empty($row['end_date']) && ($row['start_date'] ?? null) !== $row['end_date']) {
            --$to;
        }

        return [$from, $to];
    }

    private static function day(string $date): int
    {
        return (int) floor((new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp() / 86400);
    }

    private static function calendar(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }

    private static function wrap(int $day): int
    {
        return (($day - 1) % 7 + 7) % 7 + 1;
    }

    private static function weekday(int $day): int
    {
        return self::wrap($day + 4); // 1970-01-01 was Thursday.
    }

    private static function minutes(int $code): int
    {
        return intdiv($code, 100) * 60 + $code % 100;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{\DateTimeImmutable,\DateTimeImmutable}
     */
    private function window(array $row, int $day): array
    {
        $date = new \DateTimeImmutable(self::calendar($day), $this->zone);
        $start = $date->setTime(intdiv($row['start_time'], 100), $row['start_time'] % 100);
        $end = $date->setTime(intdiv($row['end_time'], 100), $row['end_time'] % 100);
        if ($end < $start) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function onDate(array $a, array $b, int $day, int $offset): ?\DateTimeImmutable
    {
        [$startA, $endA] = $this->window($a, $day);
        [$startB, $endB] = $this->window($b, $day + $offset);
        if ($startA >= $endA || $startB >= $endB || $startA >= $endB || $startB >= $endA) {
            return null;
        }

        return $startA > $startB ? $startA : $startB;
    }

    /** @return array{days:array<int,true>,minutes:array<int,true>,shift:int} */
    private function clockChanges(int $from, int $to): array
    {
        $key = $from . ':' . $to;
        if (isset($this->transitions[$key])) {
            return $this->transitions[$key];
        }
        $rows = $this->zone->getTransitions($from * 86400, ($to + 1) * 86400);
        $days = [];
        $minutes = [];
        $shift = 0;
        $previous = null;
        foreach ($rows ?: [] as $row) {
            $this->checkDeadline();
            if ($previous !== null && $previous !== $row['offset']) {
                $shift = max($shift, abs($row['offset'] - $previous));
                $low = $row['ts'] + min($row['offset'], $previous);
                $high = $row['ts'] + max($row['offset'], $previous);
                // Only endpoints inside a changed clock range can disturb wall-time
                // ordering. This keeps ordinary daytime bookings inexpensive.
                for ($time = (int) floor($low / 60); $time <= (int) ceil($high / 60); ++$time) {
                    $minutes[($time % 1440 + 1440) % 1440] = true;
                }
                foreach ([$row['ts'] - 1, $row['ts']] as $timestamp) {
                    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone);
                    $day = self::day($date->format('Y-m-d'));
                    for ($i = -2; $i <= 2; ++$i) {
                        $days[$day + $i] = true;
                    }
                }
            }
            $previous = $row['offset'];
        }
        ksort($days);

        return $this->transitions[$key] = ['days' => $days, 'minutes' => $minutes, 'shift' => $shift];
    }

    private function checkDeadline(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new Problem('The station conflict check took too long. No schedule was saved; reload and try again.', 503);
        }
    }
}

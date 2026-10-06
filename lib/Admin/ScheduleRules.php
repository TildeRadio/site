<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class ScheduleRules
{
    public static function display(int $code): string
    {
        return sprintf('%02d:%02d', intdiv($code, 100), $code % 100);
    }

    private static function time(mixed $value): int
    {
        $time = Input::text($value, 'schedule time', 5, true);
        if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
            throw new Problem('Use a time from 00:00 through 23:59.');
        }

        return (int) str_replace(':', '', $time);
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = Input::text($value, 'schedule date', 10, true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new Problem('Use a valid date in YYYY-MM-DD format.');
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function changes(array $post): array
    {
        $start = self::time($post['start_time'] ?? null);
        $end = self::time($post['end_time'] ?? null);
        if ($start === $end) {
            throw new Problem('Start and end times must differ. An earlier end time runs into the following day.');
        }
        $from = self::date($post['start_date'] ?? null);
        $to = self::date($post['end_date'] ?? null);
        if ($from !== null && $to !== null && $to < $from) {
            throw new Problem('The end date must not be before the start date.');
        }
        $selected = $post['days'] ?? [];
        if (!is_array($selected) || !array_is_list($selected) || count($selected) > 7) {
            throw new Problem('Choose valid weekdays.');
        }
        $days = [];
        foreach ($selected as $value) {
            $day = Input::integer($value, 'weekday');
            if ($day > 7) {
                throw new Problem('Choose valid weekdays.');
            }
            $days[] = $day;
        }
        $days = array_values(array_unique($days));
        sort($days);

        return ['start_time' => $start, 'end_time' => $end, 'start_date' => $from, 'end_date' => $to, 'days' => $days];
    }

    /** @param array<string,mixed> $row */
    public static function validateStored(array $row): void
    {
        foreach (['start_time', 'end_time'] as $field) {
            $code = $row[$field] ?? null;
            if (!is_int($code) || $code < 0 || $code > 2359 || $code % 100 > 59) {
                throw new Problem('AzuraCast returned an unsupported schedule time.', 502);
            }
        }
        $from = self::date($row['start_date'] ?? null);
        $to = self::date($row['end_date'] ?? null);
        if ($from !== null && $to !== null && $to < $from) {
            throw new Problem('AzuraCast returned an unsupported schedule date range.', 502);
        }
        if (!is_array($row['days'] ?? null) || !array_is_list($row['days'])) {
            throw new Problem('AzuraCast returned unsupported schedule weekdays.', 502);
        }
        foreach ($row['days'] as $day) {
            if (!is_int($day) || $day < 1 || $day > 7) {
                throw new Problem('AzuraCast returned unsupported schedule weekdays.', 502);
            }
        }
        foreach (['loop_once', 'prevent_requests', 'reset_queue_at_start', 'reset_queue_recursive'] as $flag) {
            if (isset($row[$flag]) && !is_bool($row[$flag])) {
                throw new Problem('AzuraCast returned an unsupported schedule setting.', 502);
            }
        }
    }
}

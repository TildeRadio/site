<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class PlanForm
{
    /** @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function decode(array $post): array
    {
        $start = BroadcastForm::date(Input::text($post['starts_at'] ?? '', 'start time', 30, true), true);
        $end = BroadcastForm::date(Input::text($post['ends_at'] ?? '', 'end time', 30, true), true);
        if ($start === null || $end === null || $end <= $start || $end - $start > 43200) {
            throw new Problem('Preparation must have an end after its start, within twelve hours.');
        }
        $show = [];
        foreach (['episode', 'topic', 'mood', 'prompt', 'note', 'link'] as $field) {
            $value = Input::text($post[$field] ?? '', $field, 240);
            if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new Problem('Show fields must be plain single-line text.');
            }
            if ($field === 'link') {
                BroadcastForm::url($value);
            }
            $show[$field] = $value;
        }
        return ['starts_at' => $start, 'ends_at' => $end, 'show' => $show,
            'tracks' => self::tracks(Input::text($post['playlist'] ?? '', 'playlist', 180000)),
            'public' => ($post['public'] ?? '') === '1', 'reminder' => ($post['reminder'] ?? '') === '1',
            'song_announcements' => ($post['song_announcements'] ?? '') === '1'];
    }

    /** @return list<array{artist:string,title:string}> */
    public static function tracks(string $value): array
    {
        $tracks = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode(str_contains($line, "\t") ? "\t" : '|', $line, 2);
            if (count($parts) !== 2 || preg_match('/[\x00-\x1f\x7f]/', implode('', $parts))) {
                throw new Problem('Use one song per line: Artist | Title (or paste two tab-separated spreadsheet columns).');
            }
            $tracks[] = ['artist' => Input::text($parts[0], 'artist', 160, true), 'title' => Input::text($parts[1], 'song title', 160, true)];
            if (count($tracks) > 500) {
                throw new Problem('Use no more than 500 planned songs.');
            }
        }
        return $tracks;
    }
}

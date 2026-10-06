<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class ProfileForm
{
    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function apply(array $base, array $post): array
    {
        $data = $base;
        foreach (['name', 'tagline', 'description', 'avatar', 'pronouns', 'location', 'tilde', 'irc', 'since'] as $key) {
            $value = Input::text($post[$key] ?? '', $key, $key === 'description' ? 8192 : 2048, $key === 'name');
            if ($value === '' && $key !== 'name') {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $data['published'] = isset($post['published']);
        $data['bio'] = Input::lines($post['bio'] ?? '', 'biography', true);
        $data['notes'] = Input::lines($post['notes'] ?? '', 'notes');
        $show = is_array($base['show'] ?? null) ? $base['show'] : [];
        foreach (['title', 'tagline', 'description'] as $key) {
            $show[$key] = Input::text($post['show_' . $key] ?? '', 'show ' . $key, $key === 'description' ? 8192 : 2048);
        }
        $show['timezone'] = Input::timezone($post['show_timezone'] ?? 'UTC');
        $show['genres'] = Input::lines($post['show_genres'] ?? '', 'genres');
        $raw = Input::text($post['show_formats'] ?? '', 'show formats', 49152);
        if ($raw === '') {
            $show['formats'] = [];
        } else {
            try {
                $show['formats'] = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new Problem('Show formats must contain valid JSON.');
            }
        }
        $data['show'] = $show;
        $favorites = is_array($base['favorites'] ?? null) ? $base['favorites'] : [];
        foreach (['artists', 'albums', 'tracks'] as $key) {
            $favorites[$key] = Input::lines($post['favorites_' . $key] ?? '', 'favorite ' . $key);
        }
        $data['favorites'] = $favorites;
        $rows = $post['links'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 30) {
            throw new Problem('Enter at most 30 profile links.');
        }
        $previous = self::links($base);
        $data['links'] = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new Problem('Enter valid profile links.');
            }
            $label = Input::text($row['label'] ?? '', 'link label', 100);
            $url = Input::text($row['url'] ?? '', 'link URL', 2048);
            if ($label === '' && $url === '') {
                continue;
            }
            $data['links'][] = array_replace($previous[$index] ?? [], ['label' => $label, 'url' => $url]);
        }
        ProfileValidator::validate($data);

        return $data;
    }

    public static function text(mixed $value, string $separator = "\n"): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return implode($separator, array_filter($value, 'is_string'));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public static function links(array $data): array
    {
        $value = $data['links'] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $links = [];
        foreach ($value as $key => $row) {
            if (array_is_list($value) && is_array($row)) {
                $links[] = $row;
            } elseif (is_string($key) && is_string($row)) {
                $links[] = ['label' => $key, 'url' => $row];
            }
        }

        return $links;
    }
}

<?php

declare(strict_types=1);

/*
 * Community event and submission settings.
 *
 * Station ID / jingle metadata is intentionally kept out of this shared file.
 * Each audio submission has its own JSON file under data/community/audio/.
 * The audio itself remains under community/audio/.
 */
return [
    'events' => [
        // [
        //     'title' => 'Tilde Takeover',
        //     'start' => '2026-09-19T18:00:00Z',
        //     'end' => '2026-09-20T02:00:00Z',
        //     'description' => 'One-off sets from across the tildeverse.',
        //     'url' => null,
        // ],
    ],
    'submissions' => [
        'irc' => 'https://tilde.chat/kiwi/#tilderadio',
        'max_station_id_seconds' => 10,
        'preferred_formats' => ['FLAC', 'WAV', 'OGG'],
    ],
];

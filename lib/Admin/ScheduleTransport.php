<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

interface ScheduleTransport
{
    public function timezone(int $station): string;

    /** @return array{id:int,username:string,schedule_items:list<array<string,mixed>>} */
    public function streamer(int $station, int $streamer): array;

    /** @param list<array<string,mixed>> $items */
    public function save(int $station, int $streamer, array $items): void;
}

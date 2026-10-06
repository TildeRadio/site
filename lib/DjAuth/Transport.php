<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

interface Transport
{
    /** @return array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int} */
    public function verify(string $username, #[\SensitiveParameter] string $password, string $ip): array;

    /** @return array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int} */
    public function check(int $streamerId): array;
}

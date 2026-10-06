<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use RuntimeException;

final class Failure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('DJ authentication failed.');
    }
}

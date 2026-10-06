<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

// Test-only adapter for environments that deny AF_UNIX. Never load in production.
function filetype(string $path): string|false
{
    if (getenv('TILDERADIO_CARRIER_TEST_PORT') && str_ends_with($path, '/carrier.sock')) {
        return 'socket';
    }
    return \filetype($path);
}

/** @return resource|false */
function stream_socket_client(string $address, ?int &$errorCode = null, ?string &$errorMessage = null, ?float $timeout = null)
{
    $port = getenv('TILDERADIO_CARRIER_TEST_PORT');
    if ($port && str_starts_with($address, 'unix://')) {
        $address = 'tcp://127.0.0.1:' . (int) $port;
    }
    return \stream_socket_client($address, $errorCode, $errorMessage, $timeout);
}

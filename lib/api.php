<?php

declare(strict_types=1);

function tr_api_requested_format(): string
{
    $format = $_GET['format'] ?? '';
    return is_string($format) ? strtolower(trim($format)) : '';
}

function tr_terminal_request(): bool
{
    $format = tr_api_requested_format();
    if (in_array($format, ['text', 'txt'], true)) {
        return true;
    }
    if (in_array($format, ['html', 'json'], true)) {
        return false;
    }

    $userAgent = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    return preg_match('/^(curl|wget|httpie)\//', $userAgent) === 1;
}

function tr_api_docs_wants_html(): bool
{
    $format = tr_api_requested_format();
    if ($format === 'html') {
        return true;
    }
    if (in_array($format, ['json', 'text', 'txt'], true)) {
        return false;
    }
    if (tr_terminal_request()) {
        return false;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'text/html');
}

function tr_api_json(array $payload, int $httpStatus = 200, int $maxAge = 60): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    $maxAge = max(0, $maxAge);
    header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . ($maxAge * 2));
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');

    $updatedAt = isset($payload['updated_at']) && is_int($payload['updated_at'])
        ? $payload['updated_at']
        : time();

    $status = 'ok';
    if ($httpStatus >= 500) {
        $status = 'unavailable';
    } elseif ($httpStatus >= 400) {
        $status = 'error';
    }

    $body = [
        'api_version' => 1,
        'status' => $status,
        'updated_at' => $updatedAt,
    ] + $payload;

    echo json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n";
    exit;
}

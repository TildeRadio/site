<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\CarrierClient;
use TildeRadio\Site\Admin\CarrierSync;
use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;

if (!defined('TR_DJ_SELF_SERVICE')) {
    define('TR_DJ_SELF_SERVICE', true);
}
require __DIR__ . '/admin/_admin.php';

function tr_carrier_client(): CarrierClient
{
    global $djConfig;
    return new CarrierClient($djConfig);
}

function tr_carrier_sync(): void
{
    global $djConfig, $djStore;
    (new CarrierSync($djConfig, $djStore))->run();
}

/** @param array<string,mixed> $bound */
function tr_carrier_action(string $op, array $bound = []): void
{
    $id = bin2hex(random_bytes(16));
    $_SESSION['carrier_forms'] ??= [];
    foreach ($_SESSION['carrier_forms'] as $key => $form) {
        if (($form['issued'] ?? 0) < time() - 900) {
            unset($_SESSION['carrier_forms'][$key]);
        }
    }
    if (count($_SESSION['carrier_forms']) > 150) {
        array_shift($_SESSION['carrier_forms']);
    }
    $_SESSION['carrier_forms'][$id] = ['op' => $op, 'issued' => time(), 'bound' => $bound];
    tr_admin_csrf();
    echo '<input type="hidden" name="action_id" value="' . tr_dj_h($id) . '">';
}

/** @return array<string,mixed> */
function tr_carrier_post(): array
{
    global $adminIdentity, $djStore;
    $djStore->requireEditor($adminIdentity);
    $id = Input::text($_POST['action_id'] ?? '', 'action identifier', 32, true);
    $form = $_SESSION['carrier_forms'][$id] ?? null;
    if (!is_array($form) || ($form['issued'] ?? 0) < time() - 900) {
        throw new Problem('This action form expired. Reload before trying again.', 409);
    }
    // Keep the same ID for retries after an uncertain connection outcome.
    return ['op' => $form['op'], 'request_id' => $id, 'actor' => $adminIdentity['station_id'] . ':' . $adminIdentity['streamer_id']] + $form['bound'];
}

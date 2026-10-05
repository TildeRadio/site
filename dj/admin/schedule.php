<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\ScheduleApi;
use TildeRadio\Site\Admin\ScheduleRules;
use TildeRadio\Site\Admin\ScheduleService;

require __DIR__ . '/_admin.php';
try {
    $sourceStation = Input::integer($_GET['source_station'] ?? null, 'authentication station ID');
    $sourceStreamer = Input::integer($_GET['source_streamer'] ?? null, 'authentication streamer ID');
    $station = Input::integer($_GET['station'] ?? null, 'assigned station ID');
    $djStore->scheduleTarget($adminIdentity, $sourceStation, $sourceStreamer, $station);
} catch (Problem $problem) {
    tr_dj_error($problem->status, $problem->getMessage());
}
$query = 'source_station=' . $sourceStation . '&source_streamer=' . $sourceStreamer . '&station=' . $station;
$url = tr_admin_url('schedule.php?' . $query);
$error = null;
$view = null;
$token = '';
$selected = null;
$contexts = $_SESSION['admin_schedule_views'] ?? [];
if (!is_array($contexts)) {
    $contexts = [];
}
foreach ($contexts as $key => $context) {
    if (!is_array($context) || !is_int($context['issued'] ?? null) || time() - $context['issued'] > 900) {
        unset($contexts[$key]);
    }
}
$_SESSION['admin_schedule_views'] = $contexts;
$error = tr_admin_attempt(function () use (&$view, &$token, &$selected, $djConfig, $djStore, $adminIdentity, $sourceStation, $sourceStreamer, $station, $query): void {
    $configuration = $djConfig->scheduleApi();
    if ($configuration === null) {
        throw new Problem('Schedule editing needs a private AzuraCast API key. Follow docs/dj-administration.md to configure it.', 503);
    }
    $service = new ScheduleService($djStore, new ScheduleApi($configuration), $djConfig->stateDir());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = Input::text($_POST['schedule_token'] ?? null, 'schedule form token', 64, true);
        $view = preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? ($_SESSION['admin_schedule_views'][$token] ?? null) : null;
        if (!is_array($view) || $view['source_station'] !== $sourceStation || $view['source_streamer'] !== $sourceStreamer || $view['station_id'] !== $station) {
            $view = null;
            throw new Problem('This schedule form has expired or belongs to another DJ. Reload the schedule.', 409);
        }
        $selected = ($_POST['action'] ?? '') === 'add' ? null : Input::integer($_POST['item_id'] ?? null, 'schedule entry ID');
        $service->save($adminIdentity, $view, $_POST);
        unset($_SESSION['admin_schedule_views'][$token]);
        tr_admin_finish('schedule.php?' . $query, 'AzuraCast schedule saved.');
    }
    $view = $service->view($adminIdentity, $sourceStation, $sourceStreamer, $station);
    $selected = isset($_GET['item']) ? Input::integer($_GET['item'], 'schedule entry ID') : null;
    if ($selected !== null && !in_array($selected, array_column($view['items'], 'id'), true)) {
        throw new Problem('This schedule entry was not found. Reload the current schedule.', 404);
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['admin_schedule_views'][$token] = $view;
    while (count($_SESSION['admin_schedule_views']) > 8) {
        array_shift($_SESSION['admin_schedule_views']);
    }
});
tr_admin_begin('AzuraCast schedule', 'accounts', $error);
?>
<p><a href="<?= tr_dj_h($url) ?>">Reload current schedule</a> · <a href="<?= tr_dj_h(tr_admin_url('account.php?station=' . $sourceStation . '&streamer=' . $sourceStreamer)) ?>">Back to website DJ</a></p>
<?php if ($view !== null) : ?>
    <p>Streaming DJ <strong><?= tr_dj_h($view['username']) ?></strong> · station <?= $view['station_id'] ?> · streamer <?= $view['streamer_id'] ?>. Times use AzuraCast’s <strong><?= tr_dj_h($view['timezone']) ?></strong> timezone.</p>
    <p>A save updates the live AzuraCast schedule. Avoid editing this DJ simultaneously in AzuraCast. Streaming credentials and other account settings stay intact.</p>
    <div class="dj-admin-table-wrap"><table class="dj-admin-table"><thead><tr><th>Entry</th><th>Days</th><th>Time</th><th>Date limits</th><th>Action</th></tr></thead><tbody>
    <?php
    $dayNames = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $item = null;
    foreach ($view['items'] as $row) :
        if ($row['id'] === $selected) {
            $item = $row;
        }
        $days = array_map(static fn (int $day): string => $dayNames[$day], $row['days']);
        ?>
        <tr><td><?= $row['id'] ?></td><td><?= tr_dj_h($days === [] ? 'Every day' : implode(', ', $days)) ?></td><td><?= tr_dj_h(ScheduleRules::display($row['start_time']) . '–' . ScheduleRules::display($row['end_time'])) ?></td><td><?= tr_dj_h(($row['start_date'] ?? 'No start limit') . ' / ' . ($row['end_date'] ?? 'No end limit')) ?></td><td><a href="<?= tr_dj_h($url . '&item=' . $row['id']) ?>">Edit or delete</a></td></tr>
    <?php endforeach; ?>
    <?php if ($view['items'] === []) : ?><tr><td colspan="5">No schedule entries.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($selected === null || $item !== null) :
        $posted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete';
        $value = static fn (string $key, string $default = ''): string => $posted && is_string($_POST[$key] ?? null) ? $_POST[$key] : $default;
        $days = $posted && is_array($_POST['days'] ?? null) ? $_POST['days'] : array_map('strval', $item['days'] ?? []);
        ?>
        <h2><?= $item === null ? 'Add schedule entry' : 'Edit entry ' . $item['id'] ?></h2>
        <form method="post" action="<?= tr_dj_h($url) ?>" class="dj-admin-form" data-tr-dj-auth>
            <?php tr_admin_csrf(); ?><input type="hidden" name="schedule_token" value="<?= tr_dj_h($token) ?>"><input type="hidden" name="action" value="<?= $item === null ? 'add' : 'edit' ?>"><input type="hidden" name="item_id" value="<?= $item['id'] ?? 0 ?>">
            <?php tr_admin_field('start_time', 'Start time', $value('start_time', ScheduleRules::display($item['start_time'] ?? 1200)), 'time', true); ?>
            <?php tr_admin_field('end_time', 'End time', $value('end_time', ScheduleRules::display($item['end_time'] ?? 1300)), 'time', true); ?>
            <p>An earlier end time continues into the next day. Start and end must differ.</p>
            <fieldset><legend>Days (leave all unchecked for every day)</legend>
                <?php foreach ($dayNames as $number => $name) : ?><label class="dj-admin-check"><input type="checkbox" name="days[]" value="<?= $number ?>"<?= in_array((string) $number, $days, true) ? ' checked' : '' ?>><?= tr_dj_h($name) ?></label><?php endforeach; ?>
            </fieldset>
            <?php tr_admin_field('start_date', 'First date (optional)', $value('start_date', $item['start_date'] ?? ''), 'date'); ?>
            <?php tr_admin_field('end_date', 'Last date (optional)', $value('end_date', $item['end_date'] ?? ''), 'date'); ?>
            <p>Existing advanced AzuraCast flags are preserved. New entries use standard defaults.</p>
            <div class="dj-admin-actions"><button type="submit" class="dj-auth-button">Save to AzuraCast</button><a href="<?= tr_dj_h($url) ?>">Add another entry</a></div>
        </form>
        <?php if ($item !== null) : ?>
            <div class="dj-admin-danger"><h2>Delete entry <?= $item['id'] ?></h2>
                <form method="post" action="<?= tr_dj_h($url) ?>" class="dj-admin-form" data-tr-dj-auth>
                    <?php tr_admin_csrf(); ?><input type="hidden" name="schedule_token" value="<?= tr_dj_h($token) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <?php tr_admin_field('confirm', 'Type DELETE to confirm', '', 'text', true); ?><button type="submit" class="dj-auth-button">Delete from AzuraCast</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php tr_admin_end(); ?>

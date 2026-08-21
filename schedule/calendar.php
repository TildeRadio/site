<?php
$calendarNow = time();
$calendarStart = strtotime(gmdate('Y-m-d 00:00:00', $calendarNow) . ' UTC');
$calendarDays = [];
for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
    $calendarDays[] = $calendarStart + ($dayIndex * 86400);
}

$eventAt = static function (int $slotStart) use ($calendarEvents): ?array {
    $slotEnd = $slotStart + 1800;
    foreach ($calendarEvents as $event) {
        $start = is_int($event['start_ts'] ?? null) ? $event['start_ts'] : null;
        if ($start === null) {
            continue;
        }
        $end = is_int($event['end_ts'] ?? null) ? $event['end_ts'] : $start + 1800;
        if ($start < $slotEnd && $end > $slotStart) {
            return $event;
        }
    }
    return null;
};
?>

<?php if (!$calendarEvents): ?>
    <p class="tr-muted">No live broadcasts are scheduled in the next seven days.</p>
<?php else: ?>
<div class="calendar-wrapper">
    <table class="calendar">
        <thead>
            <tr>
                <th scope="col"><span class="tr-calendar-zone">UTC</span></th>
                <?php foreach ($calendarDays as $dayTs): ?>
                    <th scope="col" data-calendar-day-ts="<?= $dayTs ?>">
                        <span class="day<?= $dayTs === $calendarStart ? ' active' : '' ?>">
                            <?= tr_schedule_h(gmdate('d', $dayTs)) ?>
                        </span>
                        <span class="long"><?= tr_schedule_h(gmdate('l', $dayTs)) ?></span>
                        <span class="short"><?= tr_schedule_h(gmdate('D', $dayTs)) ?></span>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($seconds = 0; $seconds < 86400; $seconds += 1800): ?>
                <tr>
                    <?php if ((($seconds / 1800) % 2) === 0): ?>
                        <td class="hour" rowspan="2"><span><?= tr_schedule_h(gmdate('H:i', $calendarStart + $seconds)) ?></span></td>
                    <?php endif; ?>

                    <?php foreach ($calendarDays as $dayTs): ?>
                        <?php
                        $slotTs = $dayTs + $seconds;
                        $event = $eventAt($slotTs);
                        $classes = [];
                        $title = '';
                        $showStart = false;

                        if ($event !== null) {
                            $classes[] = 'has-show';
                            $profile = tr_schedule_profile_for_event($event, $catalog);
                            $info = tr_schedule_show_info($profile);
                            $name = is_array($profile) && !empty($profile['name'])
                                ? trim((string) $profile['name'])
                                : trim((string) ($event['name'] ?? 'unknown DJ'));
                            $showLabel = $info['title'] ?? $name;
                            $title = $name . ($info['title'] !== null && strcasecmp($info['title'], $name) !== 0
                                ? ' — ' . $info['title']
                                : '');
                            $showStart = (int) $event['start_ts'] >= $slotTs && (int) $event['start_ts'] < $slotTs + 1800;
                            if ($showStart) {
                                $classes[] = 'show-start';
                            }
                        }

                        $active = $slotTs <= $calendarNow && $calendarNow < $slotTs + 1800;
                        if ($active) {
                            $classes[] = 'active';
                        }
                        ?>
                        <td
                            id="show-<?= $slotTs ?>"
                            data-slot-ts="<?= $slotTs ?>"
                            class="<?= tr_schedule_h(implode(' ', $classes)) ?>"
                            <?= $title !== '' ? 'title="' . tr_schedule_h($title) . '"' : '' ?>
                        >
                            <?php if ($event !== null && $showStart): ?>
                                <?php $slug = trim((string) ($event['_profile_slug'] ?? $event['slug'] ?? '')); ?>
                                <div class="show-title">
                                    <?php if ($slug !== ''): ?>
                                        <a href="<?= tr_schedule_h(asset('djs/?dj=' . rawurlencode($slug))) ?>"><?= tr_schedule_h($showLabel) ?></a>
                                    <?php else: ?>
                                        <?= tr_schedule_h($showLabel) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($active): ?>
                                <?php $progress = max(0.0, min(1.0, ($calendarNow - $slotTs) / 1800)); ?>
                                <div id="pointer" style="top: <?= number_format($progress * 100, 4, '.', '') ?>%"></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

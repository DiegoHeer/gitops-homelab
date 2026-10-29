<?php

$warnings = logic('warnings');
$now = amsterdam('2026-10-29 08:30');

// MeteoAlarm Atom entry as LaraPaper's XML parser returns it (cap: prefix stripped, times in UTC).
$entry = static fn (string $area, string $severity, string $onset, string $expires, string $event = 'wind'): array => [
    'areaDesc' => $area,
    'event' => $event,
    'severity' => $severity,
    'onset' => $onset,
    'expires' => $expires,
];
$feed = static fn (array|null $entries): array => ['feed' => ['title' => 'MeteoAlarm'] + ($entries === null ? [] : ['entry' => $entries])];

$result = $warnings($feed(null), $now);
check('warnings_none', $result['ok'] === true && $result['active'] === null, json_encode($result));

$result = $warnings($feed($entry('Zuid-Holland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00')), $now);
check(
    'warnings_single_entry_assoc',
    $result['active'] === ['level' => 'ORANGE', 'event' => 'Wind', 'from' => '14:00', 'until' => '22:00', 'untilDay' => null],
    json_encode($result['active'])
);

$result = $warnings($feed([$entry('Zeeland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00')]), $now);
check('warnings_other_province', $result['active'] === null, json_encode($result['active']));

$result = $warnings($feed([$entry('Zuid-Holland', 'Moderate', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00')]), $now);
check('warnings_yellow_ignored', $result['active'] === null, json_encode($result['active']));

$result = $warnings($feed([
    $entry('Zuid-Holland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00'),
    $entry('Zuid-Holland', 'Extreme', '2026-10-29T15:00:00+00:00', '2026-10-30T03:00:00+00:00'),
]), $now);
check('warnings_red_first', ($result['active']['level'] ?? null) === 'RED' && $result['active']['untilDay'] === 'Fri', json_encode($result['active']));

$result = $warnings($feed([$entry('Zuid-Holland', 'Severe', '2026-10-29T05:00:00+00:00', '2026-10-29T07:00:00+00:00')]), $now);
check('warnings_expired', $result['active'] === null, json_encode($result['active']));

$result = $warnings($feed([$entry('Zuid-Holland', 'Severe', '2026-10-30T09:00:00+00:00', '2026-10-30T15:00:00+00:00')]), $now);
check('warnings_tomorrow_ignored', $result['active'] === null, json_encode($result['active']));

// Real Atom wording varies by country ("Orange Warning", "Severe Rain warning", "fog").
$result = $warnings($feed($entry('Zuid-Holland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00', 'Severe Rain warning')), $now);
check('warnings_event_wording_type', ($result['active']['event'] ?? null) === 'Rain', json_encode($result['active']));
$result = $warnings($feed($entry('Zuid-Holland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00', 'Orange Warning')), $now);
check('warnings_event_wording_generic', ($result['active']['event'] ?? null) === 'Weather', json_encode($result['active']));

$noOnset = $entry('Zuid-Holland', 'Severe', '', '2026-10-29T21:00:00+00:00');
unset($noOnset['onset']);
$result = $warnings($feed($noOnset + ['effective' => '2026-10-29T12:00:00+00:00']), $now);
check('warnings_missing_onset_uses_effective', ($result['active']['from'] ?? null) === '13:00', json_encode($result['active']));
$result = $warnings($feed($noOnset), $now);
check('warnings_no_start_time_skipped', $result['ok'] === true && $result['active'] === null, json_encode($result));

$result = $warnings($feed(array_merge($entry('Zuid-Holland', 'Severe', '2026-10-29T13:00:00+00:00', '2026-10-29T21:00:00+00:00'), ['areaDesc' => ['Zeeland', 'Zuid-Holland']])), $now);
check('warnings_multi_area', ($result['active']['level'] ?? null) === 'ORANGE', json_encode($result));

$utcAfterMidnight = new DateTimeImmutable('2026-10-29 23:30', new DateTimeZone('UTC')); // 00:30 Fri in Amsterdam
$result = $warnings($feed($entry('Zuid-Holland', 'Severe', '2026-10-30T09:00:00+00:00', '2026-10-30T15:00:00+00:00')), $utcAfterMidnight);
check('warnings_utc_now_uses_amsterdam_day', ($result['active']['from'] ?? null) === '10:00', json_encode($result['active']));

$result = $warnings(['error' => 'Failed to fetch data'], $now);
check('warnings_failed_source', $result['ok'] === false && $result['active'] === null, json_encode($result));

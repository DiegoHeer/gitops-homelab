<?php

$rain = logic('rain');
$now = amsterdam('2026-10-29 08:30');

const DRY = [0.0, 0];
const LIGHT = [0.3, 20];
const MODERATE = [2.0, 55];
const HEAVY = [8.0, 80];

// Buienradar-shaped forecast points: one [precipitation, value] pair per step.
$series = static function (string $start, int $stepMinutes, array $points): array {
    $at = amsterdam($start);
    $forecasts = [];
    foreach ($points as [$mm, $value]) {
        $forecasts[] = ['datetime' => $at->format('Y-m-d\TH:i:s'), 'precipitation' => $mm, 'value' => $value];
        $at = $at->modify("+{$stepMinutes} minutes");
    }

    return $forecasts;
};

$borders = [
    ['title' => 'licht', 'lower' => 0, 'upper' => 40],
    ['title' => 'matig', 'lower' => 40, 'upper' => 70],
    ['title' => 'zwaar', 'lower' => 70, 'upper' => 100],
];
$fiveMinute = static fn (array $points): array => ['forecasts' => $series('2026-10-29 08:30', 5, $points)];
$hourly = static fn (array $points, bool $withBorders = true): array => ['forecasts' => $series('2026-10-29 09:00', 60, $points)]
    + ($withBorders ? ['borders' => $borders] : []);

$dryHours = array_fill(0, 48, DRY);
$lightFrom1015 = array_merge(array_fill(0, 21, DRY), array_fill(0, 13, LIGHT));

$result = $rain($fiveMinute(array_fill(0, 34, DRY)), $hourly($dryHours), $now);
check('rain_dry_5h', $result['dry'] === true && $result['headline'] === 'Dry for the next 5 hours', json_encode($result['headline']));

$result = $rain($fiveMinute($lightFrom1015), $hourly($dryHours), $now);
check('rain_starts_later', $result['headline'] === 'Dry until 10:15' && $result['detail'] === 'Then light rain.', json_encode([$result['headline'], $result['detail']]));

$hoursWithModerateNoon = $dryHours;
$hoursWithModerateNoon[3] = MODERATE; // 12:00
$result = $rain($fiveMinute($lightFrom1015), $hourly($hoursWithModerateNoon), $now);
check('rain_peak_mentioned', $result['detail'] === 'Then light rain. Moderate around 12:00.', json_encode($result['detail']));

$result = $rain($fiveMinute(array_merge(array_fill(0, 8, LIGHT), array_fill(0, 26, DRY))), $hourly($dryHours), $now);
check('rain_now_stops', $result['headline'] === 'Light rain now' && $result['detail'] === 'Dry from 09:10.', json_encode([$result['headline'], $result['detail']]));

$result = $rain($fiveMinute($lightFrom1015), $hourly($dryHours), $now);
$times = array_column($result['bars'], 'at');
$minutes = array_column($result['bars'], 'minutes');
$firstHourly = array_search(60, $minutes, true);
check(
    'rain_bars_merge',
    count($times) === count(array_unique($times))
        && $firstHourly === 34
        && array_unique(array_slice($minutes, 0, 34)) === [5]
        && array_unique(array_slice($minutes, 34)) === [60]
        && end($times) <= '13:30',
    json_encode($result['bars'])
);

$result = $rain($fiveMinute(array_merge([[0.2, 0]], array_fill(0, 33, DRY))), $hourly($dryHours), $now);
check('rain_value_zero_precip_positive', $result['dry'] === false, json_encode($result['headline']));

$result = $rain(['error' => 'Failed to fetch data'], $hourly($dryHours), $now);
check('rain_failed_source', $result['ok'] === false && $result['headline'] === 'No rain data', json_encode($result['headline'] ?? null));

$customBorders = ['forecasts' => [], 'borders' => [['title' => 'licht', 'upper' => 30], ['title' => 'matig', 'upper' => 60]]];
$result = $rain($fiveMinute(array_fill(0, 34, DRY)), $customBorders, $now);
check('rain_limits_from_borders', $result['limits'] === ['light' => 30, 'moderate' => 60], json_encode($result['limits'] ?? null));

$result = $rain($fiveMinute(array_merge([[2.0, 50], [8.0, 75]], array_fill(0, 32, DRY))), $hourly($dryHours, withBorders: false), $now);
check('rain_borders_fallback', $result['bars'][0]['class'] === 'moderate' && $result['bars'][1]['class'] === 'heavy', json_encode(array_slice($result['bars'], 0, 2)));

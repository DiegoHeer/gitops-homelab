<?php

// Full polled payloads (IDX_0..IDX_4) for review renders. "Now" is pinned to 2026-10-29 08:30 Europe/Amsterdam.

$tz = new DateTimeZone('Europe/Amsterdam');
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time, $tz);
$failed = ['error' => 'Failed to fetch data'];
$borders = [
    ['title' => 'licht', 'lower' => 0, 'upper' => 40],
    ['title' => 'matig', 'lower' => 40, 'upper' => 70],
    ['title' => 'zwaar', 'lower' => 70, 'upper' => 100],
];

// Buienradar: $values maps 'H:i' => value (0-100); precipitation follows the value.
$buienradar = static function (string $start, int $step, int $count, array $values) use ($at): array {
    $forecasts = [];
    for ($i = 0; $i < $count; $i++) {
        $time = $at($start)->modify('+'.($i * $step).' minutes');
        $value = $values[$time->format('H:i')] ?? 0;
        $forecasts[] = ['datetime' => $time->format('Y-m-d\TH:i:s'), 'precipitation' => $value === 0 ? 0.0 : round(10 ** (($value - 109) / 32), 2) + 0.1, 'value' => $value];
    }

    return ['forecasts' => $forecasts];
};
$rain = static fn (array $fiveMinute = [], array $hourly = []): array => [
    'IDX_0' => $buienradar('2026-10-29 08:30', 5, 34, $fiveMinute),
    'IDX_1' => $buienradar('2026-10-29 09:00', 60, 48, $hourly) + ['borders' => $borders],
];
$ramp = static function (string $from, array $values) use ($at): array {
    $points = [];
    foreach ($values as $i => $value) {
        $points[$at($from)->modify('+'.($i * 5).' minutes')->format('H:i')] = $value;
    }

    return $points;
};

// Open-Meteo: $codes maps 'Y-m-d H:i' => WMO code.
$meteo = static function (array $current = [], array $codes = [], array $dailyCodes = [3, 80, 2]) use ($at): array {
    $times = $temps = $hourlyCodes = $chances = [];
    for ($h = 0; $h < 72; $h++) {
        $time = $at('2026-10-29 00:00')->modify("+{$h} hours");
        $times[] = $time->format('Y-m-d\TH:i');
        $temps[] = round(11 + 4 * sin((($h % 24) - 8) / 12 * M_PI), 1);
        $hourlyCodes[] = $codes[$time->format('Y-m-d H:i')] ?? 3;
        $chances[] = $h % 24 === 15 ? 20 : 5;
    }

    return ['IDX_2' => [
        'current' => $current + [
            'temperature_2m' => 11.4, 'apparent_temperature' => 8.6, 'weather_code' => 3, 'wind_speed_10m' => 31.0,
            'wind_direction_10m' => 225, 'wind_gusts_10m' => 52.0, 'relative_humidity_2m' => 87, 'is_day' => 1,
        ],
        'hourly' => ['time' => $times, 'temperature_2m' => $temps, 'weather_code' => $hourlyCodes, 'precipitation_probability' => $chances],
        'daily' => [
            'time' => ['2026-10-29', '2026-10-30', '2026-10-31'],
            'weather_code' => $dailyCodes,
            'temperature_2m_min' => [7.2, 8.6, 6.8],
            'temperature_2m_max' => [15.1, 13.2, 12.3],
            'precipitation_probability_max' => [20, 70, 10],
            'wind_gusts_10m_max' => [52.0, 48.0, 30.0],
        ],
    ]];
};

$noWarnings = ['IDX_3' => ['feed' => ['title' => 'MeteoAlarm - Netherlands']]];
$orangeWind = ['IDX_3' => ['feed' => ['title' => 'MeteoAlarm - Netherlands', 'entry' => [
    'areaDesc' => 'Zuid-Holland', 'event' => 'wind', 'severity' => 'Severe',
    'onset' => '2026-10-29T13:00:00+00:00', 'expires' => '2026-10-29T21:00:00+00:00',
]]]];

$event = static fn (string $title, string $start, string $end, ?string $location = null): array => array_filter([
    'SUMMARY' => $title, 'LOCATION' => $location,
    'DTSTART' => $at($start)->format(DATE_ATOM), 'DTEND' => $at($end)->format(DATE_ATOM), 'all_day' => false,
], static fn ($value): bool => $value !== null);
$work = 'Odido Hoofdkantoor, Waldorpstraat 60, 2521 CC Den Haag, Nederland';
$agenda = ['IDX_4' => ['ical' => [
    $event('Odido: werk', '2026-10-29 09:30', '2026-10-29 18:00', $work),
    $event('Ajudar Lidia', '2026-10-30 14:00', '2026-10-30 22:00'),
    $event('Gymen met Zhi', '2026-11-01 10:00', '2026-11-01 12:30', 'Beest Boulders Den Haag HS, Waldorpstraat 15F, 2521 CA Den Haag, Nederland'),
    $event('Odido: werk', '2026-11-02 09:30', '2026-11-02 18:00', $work),
    $event('Odido: werk', '2026-11-03 09:30', '2026-11-03 18:00', $work),
]]];

$lightThenModerate = $rain($ramp('10:15', [8, 12, 18, 22, 25, 28, 26, 20, 15, 12, 10, 8, 6]), ['12:00' => 55, '13:00' => 20]);
$heavy = $rain($ramp('10:00', [10, 18, 26, 34, 42, 50, 58, 66, 72, 78, 82, 86, 88, 90, 92, 94]), ['12:00' => 96, '13:00' => 70]);

return [
    'rain' => $lightThenModerate + $meteo() + $noWarnings + $agenda,
    'dry' => $rain() + $meteo(['weather_code' => 2]) + $noWarnings + $agenda,
    'dry_thunder_later' => $rain() + $meteo(['weather_code' => 2], ['2026-10-29 18:00' => 95, '2026-10-29 19:00' => 95]) + $noWarnings + $agenda,
    'warning' => $heavy + $meteo(['weather_code' => 95, 'wind_gusts_10m' => 85.0, 'wind_speed_10m' => 64.0], ['2026-10-29 12:00' => 96]) + $orangeWind + $agenda,
    'danger_tomorrow' => $rain() + $meteo([], [], [3, 73, 2]) + $noWarnings + $agenda,
    'failed_sources' => ['IDX_0' => $failed, 'IDX_1' => $failed, 'IDX_2' => $failed, 'IDX_3' => $failed, 'IDX_4' => $failed],
    'malformed_sources' => [
        'IDX_0' => ['forecasts' => [['datetime' => 'not a date', 'precipitation' => 1, 'value' => 30]]],
        'IDX_1' => [],
        'IDX_2' => ['current' => [], 'hourly' => ['time' => []], 'daily' => ['time' => []]],
        'IDX_3' => ['feed' => ['entry' => ['areaDesc' => 'Zuid-Holland', 'severity' => 'Severe', 'onset' => 'soon', 'expires' => 'later']]],
        'IDX_4' => ['ical' => [['SUMMARY' => 'Broken', 'DTSTART' => 'nope', 'DTEND' => 'nope']]],
    ],
    'empty_calendar' => $lightThenModerate + $meteo() + $noWarnings + ['IDX_4' => ['ical' => []]],
];

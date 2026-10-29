<?php

$weather = logic('weather');
$now = amsterdam('2026-10-29 08:30');

// Open-Meteo-shaped response for 29-31 Oct; $hourly overrides are keyed by 'Y-m-d H:i'.
$meteo = static function (array $current = [], array $hourlyCodes = [], array $daily = []): array {
    $times = [];
    $temps = [];
    $start = amsterdam('2026-10-29 00:00');
    for ($h = 0; $h < 72; $h++) {
        $at = $start->modify("+{$h} hours");
        $times[] = $at->format('Y-m-d\TH:i');
        $temps[] = 10 + 6 * sin(($h % 24 - 8) / 12 * M_PI);
    }
    $codes = array_fill(0, 72, 3);
    foreach ($hourlyCodes as $at => $code) {
        $codes[array_search(str_replace(' ', 'T', $at), $times, true)] = $code;
    }

    return [
        'current' => $current + [
            'temperature_2m' => 12.2, 'apparent_temperature' => 11.7, 'weather_code' => 3,
            'wind_speed_10m' => 29.0, 'wind_direction_10m' => 225, 'wind_gusts_10m' => 40.0,
            'relative_humidity_2m' => 87, 'is_day' => 1,
        ],
        'hourly' => [
            'time' => $times,
            'temperature_2m' => $temps,
            'weather_code' => $codes,
            'precipitation_probability' => array_map(static fn (int $h): int => $h === 15 ? 40 : 5, range(0, 71)),
        ],
        'daily' => $daily + [
            'time' => ['2026-10-29', '2026-10-30', '2026-10-31'],
            'weather_code' => [3, 80, 2],
            'temperature_2m_min' => [10.9, 8.5, 7.1],
            'temperature_2m_max' => [15.4, 13.4, 12.0],
            'precipitation_probability_max' => [40, 70, 10],
            'wind_gusts_10m_max' => [40.0, 50.0, 30.0],
        ],
    ];
};

$result = $weather($meteo(), $now);
$nowRow = $result['now'];
check(
    'weather_now_row',
    $nowRow['temp'] === 12 && $nowRow['feels'] === 12 && $nowRow['bft'] === 5 && $nowRow['dir'] === 'SW'
        && $nowRow['humidity'] === 87 && $nowRow['gusts'] === 40 && $nowRow['gustsStrong'] === false && $nowRow['isDay'] === true,
    json_encode($nowRow)
);

$result = $weather($meteo(['wind_gusts_10m' => 75.0]), $now);
check('weather_gusts_strong', $result['now']['gustsStrong'] === true, json_encode($result['now']));

$result = $weather($meteo(), $now);
check(
    'weather_days',
    $result['days'] === [
        ['label' => 'Fri', 'code' => 80, 'min' => 9, 'max' => 13, 'rainChance' => 70, 'danger' => null],
        ['label' => 'Sat', 'code' => 2, 'min' => 7, 'max' => 12, 'rainChance' => 10, 'danger' => null],
    ],
    json_encode($result['days'])
);

$result = $weather($meteo(daily: ['weather_code' => [3, 71, 2]]), $now);
check('weather_day_danger', $result['days'][0]['danger'] === 'Snow', json_encode($result['days'][0]));

$result = $weather($meteo(hourlyCodes: ['2026-10-29 18:00' => 95, '2026-10-29 19:00' => 96]), $now);
check('weather_danger_12h', $result['danger'] === ['word' => 'Hail and thunder', 'at' => '19:00'], json_encode($result['danger']));

$result = $weather($meteo(hourlyCodes: ['2026-10-30 21:00' => 95]), $now);
check('weather_danger_outside_window', $result['danger'] === null, json_encode($result['danger']));

$result = $weather($meteo(), $now);
$line = $result['tempLine'];
$temps = array_column($line['points'], 'temp');
$warmest = max($temps);
check(
    'weather_temp_line',
    count($line['points']) === 13 && $line['points'][0]['at'] === '08:00' && $line['points'][12]['at'] === '20:00'
        && $line['axisMin'] <= min($temps) && $line['axisMax'] >= $warmest
        && $line['axisMin'] % 2 === 0 && $line['axisMax'] % 2 === 0 && $line['axisMax'] - $line['axisMin'] >= 4
        && $line['warmest'] === ['temp' => (int) round($warmest), 'at' => $line['points'][array_search($warmest, $temps, true)]['at']]
        && $line['rainChanceToday'] === 40,
    json_encode($line)
);

$empty = $meteo();
$empty['hourly'] = ['time' => [], 'temperature_2m' => [], 'weather_code' => [], 'precipitation_probability' => []];
$result = $weather($empty, $now);
check('weather_empty_hourly', $result['ok'] === true && $result['tempLine'] === null && $result['danger'] === null, json_encode($result['tempLine']));

$result = $weather(['error' => 'Failed to fetch data'], $now);
check('weather_failed_source', $result['ok'] === false, json_encode($result));

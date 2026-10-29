<?php

// Open-Meteo (KNMI model): now row, next 2 days, dangers in the next 12 h, temperature line.
return static function (array $meteo, DateTimeImmutable $now): array {
    $tz = new DateTimeZone('Europe/Amsterdam');

    if (! isset($meteo['current'], $meteo['hourly']['time'], $meteo['daily']['time'])) {
        return ['ok' => false, 'now' => null, 'days' => [], 'danger' => null, 'tempLine' => null];
    }

    $round = static fn (float|int $value): int => (int) round($value, 0, PHP_ROUND_HALF_UP);

    $now = $now->setTimezone($tz);

    // WMO codes worth shouting about, most severe first.
    $dangers = [
        'Hail and thunder' => [96, 99],
        'Freezing rain' => [56, 57, 66, 67],
        'Snow' => [71, 73, 75, 77, 85, 86],
        'Thunder' => [95],
        'Violent showers' => [82],
    ];
    $dangerWords = array_keys($dangers);
    $rankByCode = [];
    foreach (array_values($dangers) as $rank => $codes) {
        $rankByCode += array_fill_keys($codes, $rank);
    }
    $dangerWord = static fn (int $code): ?string => isset($rankByCode[$code]) ? $dangerWords[$rankByCode[$code]] : null;

    $beaufort = static function (float $kmh): int {
        foreach ([1, 6, 12, 20, 29, 39, 50, 62, 75, 89, 103, 118] as $bft => $upper) {
            if ($kmh < $upper) {
                return $bft;
            }
        }

        return 12;
    };

    $current = $meteo['current'];
    $gusts = $round($current['wind_gusts_10m'] ?? 0);
    $nowRow = [
        'temp' => $round($current['temperature_2m']),
        'feels' => $round($current['apparent_temperature']),
        'code' => (int) $current['weather_code'],
        'isDay' => (bool) ($current['is_day'] ?? true),
        'bft' => $beaufort((float) ($current['wind_speed_10m'] ?? 0)),
        'dir' => ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][$round(($current['wind_direction_10m'] ?? 0) / 45) % 8],
        'humidity' => $round($current['relative_humidity_2m'] ?? 0),
        'gusts' => $gusts,
        'gustsStrong' => $gusts >= 75,
    ];

    $daily = $meteo['daily'];
    $days = [];
    foreach ([1, 2] as $i) {
        if (! isset($daily['time'][$i], $daily['weather_code'][$i], $daily['temperature_2m_min'][$i], $daily['temperature_2m_max'][$i])) {
            continue;
        }
        $code = (int) $daily['weather_code'][$i];
        $days[] = [
            'label' => (new DateTimeImmutable($daily['time'][$i], $tz))->format('D'),
            'code' => $code,
            'min' => $round($daily['temperature_2m_min'][$i]),
            'max' => $round($daily['temperature_2m_max'][$i]),
            'rainChance' => $round($daily['precipitation_probability_max'][$i] ?? 0),
            'danger' => $dangerWord($code),
        ];
    }

    $hourly = $meteo['hourly'];
    $hourStart = $now->setTime((int) $now->format('H'), 0);
    $endOfToday = $now->setTime(23, 59, 59);
    $windowEnd = $now->modify('+12 hours');
    $points = [];
    $danger = null;
    $rainChanceToday = 0;
    foreach ($hourly['time'] as $i => $time) {
        $at = new DateTimeImmutable($time, $tz);
        if ($at < $hourStart) {
            continue;
        }
        if (count($points) < 13) {
            $points[] = ['at' => $at->format('H:i'), 'temp' => (float) $hourly['temperature_2m'][$i]];
        }
        if ($at <= $endOfToday) {
            $rainChanceToday = max($rainChanceToday, $round($hourly['precipitation_probability'][$i] ?? 0));
        }
        $rank = $rankByCode[(int) $hourly['weather_code'][$i]] ?? null;
        if ($at <= $windowEnd && $rank !== null && ($danger === null || $rank < $danger['rank'])) {
            $danger = ['rank' => $rank, 'word' => $dangerWords[$rank], 'at' => $at->format('H:i')];
        }
    }

    $danger = $danger === null ? null : ['word' => $danger['word'], 'at' => $danger['at'], 'isNow' => $danger['at'] === $hourStart->format('H:i')];
    if ($points === []) {
        return ['ok' => true, 'now' => $nowRow, 'days' => $days, 'danger' => $danger, 'tempLine' => null];
    }

    $temps = array_column($points, 'temp');
    $axisMin = 2 * (int) floor(min($temps) / 2);
    $axisMax = max(2 * (int) ceil(max($temps) / 2), $axisMin + 4);
    $warmest = $points[array_search(max($temps), $temps, true)];

    return [
        'ok' => true,
        'now' => $nowRow,
        'days' => $days,
        'danger' => $danger,
        'tempLine' => [
            'points' => $points,
            'axisMin' => $axisMin,
            'axisMax' => $axisMax,
            'warmest' => ['temp' => $round($warmest['temp']), 'at' => $warmest['at']],
            'rainChanceToday' => $rainChanceToday,
        ],
    ];
};

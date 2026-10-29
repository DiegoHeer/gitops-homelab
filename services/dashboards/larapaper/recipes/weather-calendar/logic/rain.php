<?php

// Rain for the next 5 hours: Buienradar 5-minute points first, hourly points after.
return static function (array $rain3, array $rain24, DateTimeImmutable $now): array {
    $tz = new DateTimeZone('Europe/Amsterdam');
    $ranks = ['none' => 0, 'light' => 1, 'moderate' => 2, 'heavy' => 3];

    if (! isset($rain3['forecasts']) || ! is_array($rain3['forecasts'])) {
        return ['ok' => false, 'dry' => false, 'bars' => [], 'limits' => null, 'headline' => 'No rain data', 'detail' => null];
    }

    $upperOf = static function (string $title, int $default) use ($rain24): int {
        foreach ($rain24['borders'] ?? [] as $border) {
            if (($border['title'] ?? null) === $title) {
                return (int) $border['upper'];
            }
        }

        return $default;
    };
    $lightMax = $upperOf('licht', 40);
    $moderateMax = $upperOf('matig', 70);
    $limits = ['light' => $lightMax, 'moderate' => $moderateMax];

    $classify = static fn (float $mm, int $value): string => match (true) {
        $mm <= 0 => 'none',
        $value < $lightMax => 'light',
        $value < $moderateMax => 'moderate',
        default => 'heavy',
    };

    $toBar = static function (array $point, int $minutes) use ($tz, $classify): array {
        $mm = (float) ($point['precipitation'] ?? 0);
        $value = (int) ($point['value'] ?? 0);

        return [
            'time' => new DateTimeImmutable($point['datetime'], $tz),
            'at' => (new DateTimeImmutable($point['datetime'], $tz))->format('H:i'),
            'minutes' => $minutes,
            'level' => max(0, min(100, $value)),
            'class' => $classify($mm, $value),
        ];
    };

    $from = $now->modify('-5 minutes');
    $until = $now->modify('+5 hours');
    $bars = [];
    foreach ($rain3['forecasts'] as $point) {
        $bar = $toBar($point, 5);
        if ($bar['time'] >= $from && $bar['time'] <= $until) {
            $bars[] = $bar;
        }
    }
    $lastFiveMinute = $bars === [] ? $from : end($bars)['time'];
    foreach ($rain24['forecasts'] ?? [] as $point) {
        $bar = $toBar($point, 60);
        if ($bar['time'] > $lastFiveMinute && $bar['time'] <= $until) {
            $bars[] = $bar;
        }
    }
    $bars = array_map(static fn (array $bar): array => array_diff_key($bar, ['time' => true]), $bars);

    $raining = array_values(array_filter($bars, static fn (array $bar): bool => $bar['class'] !== 'none'));
    if ($raining === []) {
        return ['ok' => true, 'dry' => true, 'bars' => $bars, 'limits' => $limits, 'headline' => 'Dry for the next 5 hours', 'detail' => null];
    }

    $peak = array_reduce($raining, static fn (?array $best, array $bar): array => $best !== null && $ranks[$best['class']] >= $ranks[$bar['class']] ? $best : $bar);
    $peakClause = static fn (string $class): string => $ranks[$peak['class']] > $ranks[$class]
        ? ' '.ucfirst($peak['class']).' around '.$peak['at'].'.'
        : '';

    $first = $bars[0];
    if ($first['class'] !== 'none') {
        $stop = null;
        foreach ($bars as $bar) {
            if ($bar['class'] === 'none') {
                $stop = $bar;
                break;
            }
        }
        $detail = ($stop === null ? 'Rain for the next 5 hours.' : 'Dry from '.$stop['at'].'.').$peakClause($first['class']);

        return ['ok' => true, 'dry' => false, 'bars' => $bars, 'limits' => $limits, 'headline' => ucfirst($first['class']).' rain now', 'detail' => $detail];
    }

    $start = $raining[0];

    return [
        'ok' => true,
        'dry' => false,
        'bars' => $bars,
        'limits' => $limits,
        'headline' => 'Dry until '.$start['at'],
        'detail' => 'Then '.$start['class'].' rain.'.$peakClause($start['class']),
    ];
};

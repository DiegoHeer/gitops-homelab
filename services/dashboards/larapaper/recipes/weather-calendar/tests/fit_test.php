<?php

$fit = logic('fit');

$withPlace = ['title' => 'Odido: werk', 'place' => 'Odido Hoofdkantoor', 'time' => '09:30 – 18:00'];
$withoutPlace = ['title' => 'Ajudar Lidia', 'place' => null, 'time' => '14:00 – 22:00'];
$groups = [
    ['label' => 'Next Up', 'events' => [$withPlace]],
    ['label' => 'Fri 30 Oct', 'events' => [$withoutPlace, $withPlace, $withPlace]],
    ['label' => 'Sat 31 Oct', 'events' => [$withoutPlace]],
];

// Costs: header 30 (first) / 34 (later), event 58 with place / 43 without.
$result = $fit($groups, 428);
check('fit_everything_fits', $result === $groups, json_encode($result));

// 30+58 = 88; 34+43 = 165; +58 = 223 fits in 230, the next 58 does not.
$result = $fit($groups, 230);
check(
    'fit_keeps_partial_group',
    array_column($result, 'label') === ['Next Up', 'Fri 30 Oct'] && count($result[1]['events']) === 2,
    json_encode($result)
);

$result = $fit($groups, 100);
check('fit_drops_header_without_events', array_column($result, 'label') === ['Next Up'], json_encode($result));

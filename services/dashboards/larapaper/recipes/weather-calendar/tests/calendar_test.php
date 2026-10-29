<?php

$calendar = logic('calendar');
$now = amsterdam('2026-10-29 08:30');

// Events as LaraPaper's iCal parser returns them; all-day events are midnight in the server timezone (UTC).
$event = static fn (string $title, string $start, string $end, ?string $location = null, bool $allDay = false): array => array_filter([
    'SUMMARY' => $title,
    'LOCATION' => $location,
    'DTSTART' => $allDay ? $start.'T00:00:00+00:00' : amsterdam($start)->format(DATE_ATOM),
    'DTEND' => $allDay ? $end.'T00:00:00+00:00' : amsterdam($end)->format(DATE_ATOM),
    'all_day' => $allDay,
], static fn ($value): bool => $value !== null);

$ical = ['ical' => [
    $event('Ajudar Lidia', '2026-10-30 14:00', '2026-10-30 22:00'),
    $event('Odido: werk', '2026-10-29 09:30', '2026-10-29 18:00', 'Odido Hoofdkantoor, Waldorpstraat 60, 2521 CC Den Haag, Nederland'),
    $event('Gone', '2026-10-29 07:00', '2026-10-29 08:00'),
    $event('Early', '2026-10-29 08:00', '2026-10-29 18:00'),
    $event('Party', '2026-10-30 22:00', '2026-10-31 00:30'),
    $event('Holiday', '2026-10-30', '2026-10-31', allDay: true),
]];

$result = $calendar($ical, $now);
$groups = $result['groups'];
$titles = static fn (array $group): array => array_column($group['events'], 'title');
$find = static function (string $title) use (&$groups): ?array {
    foreach ($groups as $group) {
        foreach ($group['events'] as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }
    }

    return null;
};

check('calendar_next_up', $groups[0]['label'] === 'Next Up' && $titles($groups[0]) === ['Early'], json_encode($groups[0]));

check(
    'calendar_place_before_comma',
    $find('Odido: werk')['place'] === 'Odido Hoofdkantoor' && $find('Ajudar Lidia')['place'] === null,
    json_encode([$find('Odido: werk'), $find('Ajudar Lidia')])
);

check(
    'calendar_time_format',
    $find('Odido: werk')['time'] === '09:30 – 18:00' && $find('Holiday')['time'] === 'All day' && $find('Party')['time'] === '22:00 – Sat 00:30',
    json_encode([$find('Odido: werk'), $find('Holiday'), $find('Party')])
);

check('calendar_started_event_kept', $find('Early') !== null && $find('Gone') === null, json_encode($groups));

check(
    'calendar_day_labels',
    array_column($groups, 'label') === ['Next Up', 'Today', 'Fri 30 Oct'] && $titles($groups[2]) === ['Holiday', 'Ajudar Lidia', 'Party'],
    json_encode($groups)
);

$many = ['ical' => array_map(
    static fn (int $day): array => $event("Day {$day}", sprintf('2026-11-%02d 09:30', $day), sprintf('2026-11-%02d 18:00', $day)),
    range(1, 12)
)];
$result = $calendar($many, $now);
check('calendar_limit', array_sum(array_map(static fn (array $group): int => count($group['events']), $result['groups'])) === 8, json_encode($result['groups']));

$evening = amsterdam('2026-10-29 19:00');
$result = $calendar(['ical' => [$event('Ajudar Lidia', '2026-10-30 14:00', '2026-10-30 22:00')]], $evening);
check('calendar_next_up_other_day', $result['groups'][0]['label'] === 'Next Up · Fri 30 Oct', json_encode($result['groups']));

$result = $calendar(['ical' => [
    $event('Birthday Zhi', '2026-10-29', '2026-10-30', allDay: true),
    $event('Meeting', '2026-10-29 10:00', '2026-10-29 11:00'),
]], $now);
check(
    'calendar_next_up_skips_all_day',
    array_column($result['groups'], 'label') === ['Next Up', 'Today'] && $titles($result['groups'][0]) === ['Meeting'] && $titles($result['groups'][1]) === ['Birthday Zhi'],
    json_encode($result['groups'])
);

$result = $calendar(['ical' => [$event('Holiday', '2026-10-30', '2026-10-31', allDay: true)]], $now);
check('calendar_next_up_only_all_day', $titles($result['groups'][0]) === ['Holiday'] && $result['groups'][0]['label'] === 'Next Up · Fri 30 Oct', json_encode($result['groups']));

$result = $calendar(['error' => 'Failed to fetch data'], $now);
check('calendar_failed_source', $result['ok'] === false && $result['groups'] === [], json_encode($result));

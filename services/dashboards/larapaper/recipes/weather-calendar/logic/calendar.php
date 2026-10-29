<?php

// "Next Up" calendar column: the first upcoming event, then the rest grouped by day.
return static function (array $ical, DateTimeImmutable $now, int $limit = 8): array {
    $tz = new DateTimeZone('Europe/Amsterdam');

    if (! isset($ical['ical']) || ! is_array($ical['ical'])) {
        return ['ok' => false, 'groups' => []];
    }

    $now = $now->setTimezone($tz);
    $today = $now->format('Y-m-d');
    // All-day events arrive as midnight in the server timezone, so their date text is the day.
    $localDay = static fn (string $atom): DateTimeImmutable => new DateTimeImmutable(substr($atom, 0, 10), $tz);

    $events = [];
    foreach ($ical['ical'] as $event) {
        if (! isset($event['DTSTART'])) {
            continue;
        }
        $allDay = (bool) ($event['all_day'] ?? false);
        $start = $allDay ? $localDay($event['DTSTART']) : (new DateTimeImmutable($event['DTSTART']))->setTimezone($tz);
        $end = match (true) {
            ! isset($event['DTEND']) => $allDay ? $start->modify('+1 day') : $start,
            $allDay => $localDay($event['DTEND']),
            default => (new DateTimeImmutable($event['DTEND']))->setTimezone($tz),
        };
        if ($end <= $now) {
            continue;
        }
        $place = trim(explode(',', (string) ($event['LOCATION'] ?? ''))[0]);
        $events[] = [
            'day' => max($start->format('Y-m-d'), $today),
            'allDay' => $allDay,
            'start' => $start,
            'title' => (string) ($event['SUMMARY'] ?? 'Untitled'),
            'place' => $place === '' ? null : $place,
            'time' => match (true) {
                $allDay => 'All day',
                $end->format('Y-m-d') === $start->format('Y-m-d') => $start->format('H:i').' – '.$end->format('H:i'),
                default => $start->format('H:i').' – '.$end->format('D H:i'),
            },
        ];
    }

    usort($events, static fn (array $a, array $b): int => [$a['day'], ! $a['allDay'], $a['start']] <=> [$b['day'], ! $b['allDay'], $b['start']]);

    // An all-day event (birthday, holiday week) would hold "Next Up" all day, so the first timed event takes it.
    $nextUp = array_key_first(array_filter($events, static fn (array $event): bool => ! $event['allDay'])) ?? 0;
    if ($events !== []) {
        array_unshift($events, ...array_splice($events, $nextUp, 1));
    }
    $events = array_slice($events, 0, $limit);

    $dayLabel = static fn (string $day): string => (new DateTimeImmutable($day, $tz))->format('D j M');
    $groups = [];
    foreach ($events as $index => $event) {
        $label = match (true) {
            $index === 0 => $event['day'] === $today ? 'Next Up' : 'Next Up · '.$dayLabel($event['day']),
            $event['day'] === $today => 'Today',
            default => $dayLabel($event['day']),
        };
        if ($groups === [] || end($groups)['label'] !== $label) {
            $groups[] = ['label' => $label, 'events' => []];
        }
        $groups[array_key_last($groups)]['events'][] = ['title' => $event['title'], 'place' => $event['place'], 'time' => $event['time']];
    }

    return ['ok' => true, 'groups' => $groups];
};

<?php

// KNMI warnings via the MeteoAlarm Atom feed: only code orange/red for Zuid-Holland, today.
return static function (array $atom, DateTimeImmutable $now): array {
    $tz = new DateTimeZone('Europe/Amsterdam');
    $levels = ['Extreme' => 'RED', 'Severe' => 'ORANGE'];

    if (! isset($atom['feed']) || ! is_array($atom['feed'])) {
        return ['ok' => false, 'active' => null];
    }

    // The XML parser gives one entry as an assoc array and several as a list.
    $entries = $atom['feed']['entry'] ?? [];
    if (isset($entries['areaDesc'])) {
        $entries = [$entries];
    }

    // Feeds word the event differently ("wind", "Severe Rain warning", "Orange Warning"); keep only the hazard.
    $eventType = static function (string $event): string {
        $hazard = trim(preg_replace('/\b(yellow|orange|red|minor|moderate|severe|extreme|weather|warning)\b/i', '', $event));
        $hazard = trim(preg_replace('/\s+/', ' ', $hazard));

        return $hazard === '' ? 'Weather' : ucfirst(strtolower($hazard));
    };

    $endOfToday = $now->setTime(23, 59, 59);
    $matches = [];
    foreach ($entries as $entry) {
        $level = $levels[$entry['severity'] ?? ''] ?? null;
        if ($level === null || ($entry['areaDesc'] ?? '') !== 'Zuid-Holland') {
            continue;
        }
        // CAP makes onset optional; effective is the next best start time.
        $start = $entry['onset'] ?? $entry['effective'] ?? null;
        if ($start === null || ! isset($entry['expires'])) {
            continue;
        }
        $onset = (new DateTimeImmutable($start))->setTimezone($tz);
        $expires = (new DateTimeImmutable($entry['expires']))->setTimezone($tz);
        if ($expires <= $now || $onset > $endOfToday) {
            continue;
        }
        $matches[] = ['level' => $level, 'event' => $eventType((string) ($entry['event'] ?? '')), 'onset' => $onset, 'expires' => $expires];
    }

    if ($matches === []) {
        return ['ok' => true, 'active' => null];
    }

    usort($matches, static fn (array $a, array $b): int => [$a['level'] === 'RED' ? 0 : 1, $a['onset']] <=> [$b['level'] === 'RED' ? 0 : 1, $b['onset']]);
    $warning = $matches[0];

    return ['ok' => true, 'active' => [
        'level' => $warning['level'],
        'event' => $warning['event'],
        'from' => $warning['onset']->format('H:i'),
        'until' => $warning['expires']->format('H:i'),
        'untilDay' => $warning['expires']->format('Y-m-d') === $now->format('Y-m-d') ? null : $warning['expires']->format('D'),
    ]];
};

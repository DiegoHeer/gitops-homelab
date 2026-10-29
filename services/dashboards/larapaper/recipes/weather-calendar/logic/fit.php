<?php

// Blade cannot measure, so keep only the calendar events that fit the column, by estimated pixel heights.
return static function (array $groups, int $budget): array {
    $fitted = [];
    foreach ($groups as $group) {
        $headerCost = $fitted === [] ? 30 : 34;
        $events = [];
        foreach ($group['events'] as $event) {
            $cost = ($events === [] ? $headerCost : 0) + ($event['place'] ? 58 : 43);
            if ($cost > $budget) {
                break;
            }
            $budget -= $cost;
            $events[] = $event;
        }
        if ($events !== []) {
            $fitted[] = ['label' => $group['label'], 'events' => $events];
        }
        if (count($events) < count($group['events'])) {
            break;
        }
    }

    return $fitted;
};

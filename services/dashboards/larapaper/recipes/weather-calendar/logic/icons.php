<?php

// Inline stroke SVG per WMO weather code, black on white for e-ink.
return static function (int $code, bool $isDay, int $size): string {
    $cloud = '<path d="M7 17h10a4 4 0 0 0 .6-7.95A6 6 0 0 0 6.2 8.6 4.2 4.2 0 0 0 7 17z"/>';
    $highCloud = '<path d="M7 15h10a4 4 0 0 0 .6-7.95A6 6 0 0 0 6.2 6.6 4.2 4.2 0 0 0 7 15z"/>';
    $sun = '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4"/>';
    $moon = '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>';
    $sunBehindCloud = '<circle cx="9" cy="9" r="3.5"/><path d="M9 2.5V4M2.5 9H4M4.4 4.4l1 1M13.6 4.4l-1 1"/>'
        .'<path d="M10 21h8a3.3 3.3 0 0 0 .4-6.6 4.8 4.8 0 0 0-9.1-.6A3.6 3.6 0 0 0 10 21z" fill="#ffffff"/>';
    $bolt = '<path d="M12.5 15l-2 4h3l-2 4"/>';

    [$label, $shapes] = match (true) {
        $code <= 1 => $isDay ? ['Clear', $sun] : ['Clear night', $moon],
        $code === 2 => ['Partly cloudy', $isDay ? $sunBehindCloud : $moon.$cloud],
        $code === 3 => ['Cloudy', $cloud],
        $code === 45, $code === 48 => ['Fog', '<path d="M4 9h16M6 13h12M4 17h16"/>'],
        $code >= 51 && $code <= 57 => ['Drizzle', $highCloud.'<path d="M9 18.5v1M13 18.5v1M17 18.5v1"/>'],
        ($code >= 61 && $code <= 67) || ($code >= 80 && $code <= 82) => ['Rain', $highCloud.'<path d="M9 18l-1 2.5M13 18l-1 2.5M17 18l-1 2.5"/>'],
        ($code >= 71 && $code <= 77) || $code === 85 || $code === 86 => ['Snow', $highCloud.'<path d="M9 18v4M7 20h4M15 18v4M13 20h4"/>'],
        $code === 95 => ['Thunder', $highCloud.$bolt],
        $code === 96, $code === 99 => ['Thunder and hail', $highCloud.$bolt.'<circle cx="7.5" cy="20" r="1" fill="#000000"/><circle cx="16.5" cy="21" r="1" fill="#000000"/>'],
        default => ['Cloudy', $cloud],
    };

    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 26" fill="none" stroke="#000000" stroke-width="1.8"'
        .' stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="'.$label.'">'.$shapes.'</svg>';
};

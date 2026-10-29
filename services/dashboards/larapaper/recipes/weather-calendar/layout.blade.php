{{-- Weather (left 3/4) + Next Up calendar (right 1/4). Built by build.php; do not paste into LaraPaper by hand. --}}
{{-- logic:rain --}}
{{-- logic:weather --}}
{{-- logic:warnings --}}
{{-- logic:calendar --}}
{{-- logic:icons --}}
{{-- logic:fit --}}
@php
    $now = \Carbon\CarbonImmutable::now('Europe/Amsterdam');
    // Malformed upstream data must cost one block, not the whole screen.
    $safely = function (callable $run, array $fallback): array {
        try {
            return $run();
        } catch (\Throwable) {
            return $fallback;
        }
    };
    $rain = $safely(fn () => $rainLogic($data['IDX_0'] ?? [], $data['IDX_1'] ?? [], $now), $rainLogic([], [], $now));
    $weather = $safely(fn () => $weatherLogic($data['IDX_2'] ?? [], $now), $weatherLogic([], $now));
    $warnings = $safely(fn () => $warningsLogic($data['IDX_3'] ?? [], $now), $warningsLogic([], $now));
    $calendar = $safely(fn () => $calendarLogic($data['IDX_4'] ?? [], $now), $calendarLogic([], $now));
    $tempLine = $weather['ok'] ? $weather['tempLine'] : null;
    $danger = $weather['danger'];
    $dangerLine = $danger === null ? null : $danger['word'].' possible around '.$danger['at'].'.';
    $chartLabels = ['Hail and thunder' => 'HAIL', 'Freezing rain' => 'ICE', 'Snow' => 'SNOW', 'Thunder' => 'THUNDER', 'Violent showers' => 'STORM'];
    $showTempLine = $rain['dry'] || ! $rain['ok'];

    $headline = $rain['headline'];
    $detail = $rain['detail'];
    if ($rain['dry'] && $danger === null && $tempLine !== null) {
        $detail = 'Warmest '.$tempLine['warmest']['temp'].'° at '.$tempLine['warmest']['at'].'. Rain chance at most '.$tempLine['rainChanceToday'].'% today.';
    }

    // Column height minus padding and the "Updated" line.
    $calendarGroups = $fitLogic($calendar['groups'], 428);

    // Hour labels under 5-minute bars, except right before the first hourly bar where they collide.
    $fiveMinuteBars = count(array_filter($rain['bars'], fn (array $bar): bool => $bar['minutes'] === 5));
    $barLabel = fn (array $bar, int $index): string => match (true) {
        $index === 0 => 'Now',
        $bar['minutes'] === 60 => $bar['at'],
        str_ends_with($bar['at'], ':00') && $index <= $fiveMinuteBars - 8 => $bar['at'],
        default => '',
    };

    // Temperature line geometry (SVG 564×168: plot from x 56 to 552, y 20 (axisMax) to 140 (axisMin)).
    $svgPoints = '';
    $svgTicks = [];
    $svgLabels = [];
    if ($showTempLine && $tempLine !== null) {
        $line = $tempLine;
        $span = max(1, $line['axisMax'] - $line['axisMin']);
        $x = fn (int $i): float => 56 + $i * (496 / 12);
        $y = fn (float $t): float => 140 - ($t - $line['axisMin']) / $span * 120;
        $svgPoints = implode(' ', array_map(fn (array $p, int $i): string => round($x($i), 1).','.round($y($p['temp']), 1), $line['points'], array_keys($line['points'])));
        for ($t = $line['axisMin']; $t <= $line['axisMax']; $t += 2) {
            $svgTicks[] = ['y' => round($y($t), 1), 'label' => $t.'°', 'base' => $t === $line['axisMin']];
        }
        foreach ([0, 3, 6, 9, 12] as $i) {
            $point = $line['points'][$i] ?? null;
            if ($point !== null) {
                $svgLabels[] = ['x' => round($x($i), 1), 'y' => round($y($point['temp']), 1), 'temp' => (int) round($point['temp']).'°', 'at' => $i === 0 ? 'Now' : $point['at'], 'last' => $i === 12];
            }
        }
    }
@endphp

<x-trmnl::view size="{{ $size }}">
<div style="display: flex; width: 100%; height: 100%; box-sizing: border-box; padding: 14px 0 12px 0; color: #000000; background: #ffffff;">

    <div style="width: 604px; flex-shrink: 0; box-sizing: border-box; padding: 0 20px; display: flex; flex-direction: column; gap: 10px;">

        @if ($warnings['active'])
            @php $warning = $warnings['active']; @endphp
            <div style="background: #000000; color: #ffffff; border-radius: 6px; padding: 7px 12px; display: flex; align-items: center; gap: 12px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Warning"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                <div style="display: flex; flex-direction: column; line-height: 1.2;">
                    <span style="font-size: 20px; font-weight: 800;">CODE {{ $warning['level'] }} · {{ $warning['event'] }}</span>
                    <span style="font-size: 13px; font-weight: 500;">{{ $warning['from'] }} – {{ $warning['untilDay'] ? $warning['untilDay'].' ' : '' }}{{ $warning['until'] }} · KNMI</span>
                </div>
            </div>
        @elseif (! $warnings['ok'])
            <span style="font-size: 12px; font-weight: 600; color: #555555;">Warnings unavailable</span>
        @endif

        <div style="display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #000000; padding-bottom: 8px; {{ $warnings['active'] ? 'height: 80px;' : 'height: 100px;' }}">
            @if ($weather['ok'])
                @php $nowRow = $weather['now']; @endphp
                {!! $iconsLogic($nowRow['code'], $nowRow['isDay'], $warnings['active'] ? 66 : 80) !!}
                <span style="font-size: {{ $warnings['active'] ? 72 : 84 }}px; font-weight: 700; line-height: 1; letter-spacing: -3px;">{{ $nowRow['temp'] }}°</span>
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 20px; font-weight: 700;">Feels like {{ $nowRow['feels'] }}°</span>
                    @if ($nowRow['gustsStrong'])
                        <span style="font-size: 14px; font-weight: 800;">Gusts {{ $nowRow['gusts'] }} km/h · {{ $nowRow['bft'] }} Bft {{ $nowRow['dir'] }}</span>
                        <span style="font-size: 13px; font-weight: 500; color: #555555;">Hum {{ $nowRow['humidity'] }}%</span>
                    @else
                        <span style="font-size: 14px; font-weight: 500; color: #555555;">Wind {{ $nowRow['bft'] }} Bft {{ $nowRow['dir'] }} · Hum {{ $nowRow['humidity'] }}%</span>
                    @endif
                </div>
                <div style="margin-left: auto; display: flex; flex-direction: column; gap: 8px; border-left: 2px dotted #000000; padding-left: 14px;">
                    @foreach ($weather['days'] as $day)
                        <div style="display: flex; align-items: center; gap: 8px;">
                            {!! $iconsLogic($day['code'], true, 30) !!}
                            <div style="display: flex; flex-direction: column; line-height: 1.2;">
                                <span style="font-size: 12px; font-weight: 600; color: #555555;">{{ $day['label'] }} · {{ $day['rainChance'] }}%@if ($day['danger']) <b style="color: #000000;">· {{ $day['danger'] }}</b>@endif</span>
                                <span style="font-size: 17px; font-weight: 700;">{{ $day['min'] }}° / {{ $day['max'] }}°</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <span style="font-size: 20px; font-weight: 600; color: #555555;">Weather unavailable</span>
            @endif
        </div>

        <div style="display: flex; flex-direction: column; gap: 2px;">
            <span style="font-size: 30px; font-weight: 800; line-height: 1.1;">{{ $headline }}</span>
            @if ($detail && ! ($rain['dry'] && $danger))
                <span style="font-size: 16px; font-weight: 500;">{{ $detail }}</span>
            @endif
            @if ($dangerLine)
                <span style="font-size: 16px; font-weight: 800;">{{ $dangerLine }}</span>
            @endif
        </div>

        <div style="display: flex; flex-direction: column; gap: 4px; flex: 1; min-height: 0;">
            @if ($showTempLine && $tempLine !== null)
                <span style="font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #555555;">Temperature · next 12 hours</span>
                <svg width="564" height="168" viewBox="0 0 564 168" role="img" aria-label="Temperature for the next 12 hours">
                    @foreach ($svgTicks as $tick)
                        <line x1="44" y1="{{ $tick['y'] }}" x2="564" y2="{{ $tick['y'] }}" stroke="{{ $tick['base'] ? '#000000' : '#aaaaaa' }}" stroke-width="{{ $tick['base'] ? 2 : 1 }}" @if (! $tick['base']) stroke-dasharray="4 4" @endif />
                        <text x="36" y="{{ $tick['y'] + 4 }}" text-anchor="end" font-size="13" font-weight="600" fill="#555555">{{ $tick['label'] }}</text>
                    @endforeach
                    <polyline points="{{ $svgPoints }}" fill="none" stroke="#000000" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" />
                    @foreach ($svgLabels as $label)
                        <circle cx="{{ $label['x'] }}" cy="{{ $label['y'] }}" r="5" fill="#000000" />
                        <text x="{{ $label['x'] }}" y="{{ $label['y'] - 12 }}" text-anchor="middle" font-size="16" font-weight="700" fill="#000000">{{ $label['temp'] }}</text>
                        <text x="{{ $label['last'] ? 564 : $label['x'] }}" y="164" text-anchor="{{ $label['last'] ? 'end' : 'middle' }}" font-size="13" font-weight="500" fill="#555555">{{ $label['at'] }}</text>
                    @endforeach
                </svg>
            @elseif ($showTempLine)
                <span style="font-size: 14px; font-weight: 600; color: #555555;">No temperature data</span>
            @else
                @php $limits = $rain['limits']; @endphp
                <div style="position: relative; flex: 1; display: flex; align-items: flex-end; gap: 2px; border-bottom: 2px solid #000000; margin-left: 70px;">
                    <div style="position: absolute; left: -70px; right: 0; bottom: {{ $limits['light'] }}%; border-top: 1px dashed #aaaaaa;"></div>
                    <div style="position: absolute; left: -70px; right: 0; bottom: {{ $limits['moderate'] }}%; border-top: 1px dashed #aaaaaa;"></div>
                    <span style="position: absolute; left: -70px; bottom: 4px; font-size: 12px; font-weight: 600; color: #555555;">Light</span>
                    <span style="position: absolute; left: -70px; bottom: calc({{ $limits['light'] }}% + 4px); font-size: 12px; font-weight: 600; color: #555555;">Moderate</span>
                    <span style="position: absolute; left: -70px; bottom: calc({{ $limits['moderate'] }}% + 4px); font-size: 12px; font-weight: 600; color: #555555;">Heavy</span>
                    @foreach ($rain['bars'] as $bar)
                        <div style="position: relative; flex: {{ $bar['minutes'] === 60 ? 12 : 1 }} 1 0; align-self: stretch; display: flex; align-items: flex-end;">
                            <div style="width: 100%; background: #000000; height: {{ $bar['class'] === 'none' ? 0 : max(4, $bar['level']) }}%;"></div>
                            @if ($danger && $bar['at'] === $danger['at'])
                                <span style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); font-size: 12px; font-weight: 800; background: #000000; color: #ffffff; padding: 1px 6px; border-radius: 3px; white-space: nowrap;">{{ $chartLabels[$danger['word']] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div style="display: flex; gap: 2px; margin-left: 70px; font-size: 13px; font-weight: 500; color: #555555;">
                    @foreach ($rain['bars'] as $index => $bar)
                        <span style="flex: {{ $bar['minutes'] === 60 ? 12 : 1 }} 1 0; white-space: nowrap; overflow: visible; width: 0;">{{ $barLabel($bar, $index) }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div style="flex: 1; min-width: 0; box-sizing: border-box; border-left: 2px dotted #000000; padding: 0 14px; display: flex; flex-direction: column; gap: 10px; overflow: hidden;">
        @if (! $calendar['ok'])
            <span style="font-size: 14px; font-weight: 600; color: #555555;">Calendar unavailable</span>
        @elseif ($calendar['groups'] === [])
            <span style="font-size: 16px; font-weight: 700;">Nothing planned</span>
        @endif
        @foreach ($calendarGroups as $group)
            <span style="font-size: 16px; font-weight: 700; {{ $loop->first ? '' : 'margin-top: 4px;' }}">{{ $group['label'] }}</span>
            @foreach ($group['events'] as $event)
                <div style="display: flex; gap: 8px; flex-shrink: 0;">
                    <div style="width: 12px; flex-shrink: 0; background-image: radial-gradient(#777777 0.8px, transparent 0.9px); background-size: 3px 3px;"></div>
                    <div style="display: flex; flex-direction: column; min-width: 0; line-height: 1.25;">
                        <span style="font-size: 16px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $event['title'] }}</span>
                        @if ($event['place'])
                            <span style="font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $event['place'] }}</span>
                        @endif
                        <span style="font-size: 12px; font-weight: 600; align-self: flex-start; border-bottom: 1px dotted #000000;">{{ $event['time'] }}</span>
                    </div>
                </div>
            @endforeach
        @endforeach
        <span style="margin-top: auto; flex-shrink: 0; font-size: 11px; font-weight: 500; color: #555555;">Updated {{ $now->format('H:i') }}</span>
    </div>
</div>
</x-trmnl::view>

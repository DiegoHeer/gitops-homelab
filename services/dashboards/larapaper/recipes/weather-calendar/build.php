<?php

// Inline each logic file into the layout, so LaraPaper stores one self-contained Blade string.
// Logic stays in closures: LaraPaper's worker renders many times per process, and named
// functions or classes would fail to redeclare (or keep a stale version after an update).
function buildMarkup(string $recipeDir): string
{
    $layout = file_get_contents("{$recipeDir}/layout.blade.php");

    return preg_replace_callback('/^\{\{-- logic:(\w+) --\}\}$/m', static function (array $marker) use ($recipeDir): string {
        $file = "{$recipeDir}/logic/{$marker[1]}.php";
        if (! is_file($file)) {
            throw new RuntimeException("Unknown logic marker: {$marker[1]}");
        }
        $body = preg_replace('/^<\?php\s*/', '', file_get_contents($file));

        return "@php \${$marker[1]}Logic = (function () { {$body} })(); @endphp";
    }, $layout);
}

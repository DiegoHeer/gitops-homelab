<?php

// Usage (inside the larapaper container): php render-fixtures.php
// Renders every fixture scenario to a PNG in LaraPaper's generated/ folder, printing "<name>=<uuid>".
// Uses an unsaved plugin and generateImageFromModel, so the device's current screen never changes.

use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

$device = require __DIR__.'/bootstrap.php';
$markup = buildMarkup(__DIR__);
Carbon::setTestNow(CarbonImmutable::parse('2026-10-29 08:30', 'Europe/Amsterdam'));

foreach (require __DIR__.'/fixtures/scenarios.php' as $name => $payload) {
    $plugin = (new Plugin)->forceFill([
        'name' => "Fixture: {$name}",
        'plugin_type' => 'recipe',
        'markup_language' => 'blade',
        'render_markup' => $markup,
        'data_payload' => $payload,
    ]);
    $imageId = ImageGenerationService::generateImageFromModel($plugin->render('full', true, $device), $device->deviceModel);
    echo "{$name}={$imageId}\n";
}

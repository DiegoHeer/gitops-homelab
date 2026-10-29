<?php

// Usage (inside the larapaper container): ICS_URL=... php setup.php [--remove-old]
// Idempotent: upserts the "Weather + Calendar" recipe, shows it on device 2, sets refresh and
// night sleep, refreshes the data and renders the live screen. --remove-old deletes the two
// recipes of the old "Rain + Calendar" mashup.

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/build.php';

$device = Device::findOrFail(2);
$user = $device->user;

if (in_array('--remove-old', $argv, true)) {
    // Only after setup moved the device off the old mashup, or the playlist would point at deleted plugins.
    $current = Plugin::where('user_id', $user->id)->where('plugin_type', 'recipe')->where('name', 'Weather + Calendar')->first();
    $onDevice = $current !== null && PlaylistItem::whereHas('playlist', fn ($query) => $query->where('device_id', $device->id))
        ->where('plugin_id', $current->id)->whereNull('mashup')->exists();
    if (! $onDevice) {
        fwrite(STDERR, "Refusing: run setup first, so device {$device->id} shows \"Weather + Calendar\".\n");
        exit(1);
    }
    $removed = Plugin::where('user_id', $user->id)
        ->where('plugin_type', 'recipe')
        ->whereIn('name', ['Buienradar Den Haag', 'Calendar'])
        ->get();
    foreach ($removed as $plugin) {
        echo "removed plugin {$plugin->id} ({$plugin->name})\n";
        $plugin->delete();
    }
    exit(0);
}

$icsUrl = getenv('ICS_URL');
if (! $icsUrl) {
    fwrite(STDERR, "ICS_URL is not set\n");
    exit(1);
}

$pollingUrls = [
    'https://graphdata.buienradar.nl/2.0/forecast/geo/Rain3Hour?lat=52.06&lon=4.33',
    'https://graphdata.buienradar.nl/2.0/forecast/geo/Rain24Hour?lat=52.06&lon=4.33',
    'https://api.open-meteo.com/v1/forecast?latitude=52.0635&longitude=4.3271&models=knmi_seamless&timezone=Europe%2FAmsterdam&forecast_days=3'
        .'&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,relative_humidity_2m,is_day'
        .'&hourly=temperature_2m,weather_code,precipitation_probability'
        .'&daily=weather_code,temperature_2m_min,temperature_2m_max,precipitation_probability_max,wind_gusts_10m_max',
    'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-netherlands',
    $icsUrl,
];

$plugin = Plugin::firstOrNew(['user_id' => $user->id, 'name' => 'Weather + Calendar', 'plugin_type' => 'recipe']);
$plugin->uuid ??= (string) Str::uuid();
$plugin->fill([
    'markup_language' => 'blade',
    'data_strategy' => 'polling',
    'polling_verb' => 'get',
    'polling_url' => implode("\n", $pollingUrls),
    // MeteoAlarm answers 406 to LaraPaper's default Accept: application/json.
    'polling_header' => 'Accept: */*',
    'data_stale_minutes' => 15,
    'render_markup' => buildMarkup(__DIR__),
])->save();

$playlist = Playlist::where('device_id', $device->id)->where('name', 'Default')->firstOrFail();
$item = PlaylistItem::where('playlist_id', $playlist->id)->orderBy('order')->firstOrFail();
$item->update(['plugin_id' => $plugin->id, 'mashup' => null, 'is_active' => true]);

$device->update([
    'default_refresh_interval' => 900,
    'sleep_mode_enabled' => true,
    'sleep_mode_from' => '23:00',
    'sleep_mode_to' => '06:00',
]);
$user->update(['timezone' => 'Europe/Amsterdam']);

$plugin->updateDataPayload();
$plugin->refresh();
foreach ($plugin->data_payload as $key => $source) {
    $summary = isset($source['error']) ? "ERROR {$source['error']}" : 'keys='.implode(',', array_keys($source));
    echo "{$key}: {$summary}\n";
}
echo 'payload bytes: '.strlen(json_encode($plugin->data_payload, JSON_PRETTY_PRINT))."\n";

$imageId = ImageGenerationService::generateImage($plugin->render('full', true, $device), $device->id);
echo "plugin={$plugin->id} playlist_item={$item->id}\n";
echo "live={$imageId}\n";

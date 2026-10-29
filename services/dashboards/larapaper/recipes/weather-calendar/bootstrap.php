<?php

// Shared start for scripts run inside the larapaper container: boots Laravel, loads buildMarkup()
// and returns the TRMNL device the recipe is for.

use App\Models\Device;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/build.php';

return Device::findOrFail(2);

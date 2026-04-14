<?php

/**
 * One-off: remove ONLY attendance rows dated "today" (APP_TIMEZONE).
 * Does NOT change users, passwords, profiles, or any other tables.
 * Usage (from Backend folder): php scripts/clear_today_attendance.php
 */

use App\Models\Attendance;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$day = now()->toDateString();
$rows = Attendance::withTrashed()->whereDate('date', $day)->get();
$count = $rows->count();
foreach ($rows as $row) {
    $row->forceDelete();
}

echo "Removed {$count} attendance row(s) for {$day}.\n";

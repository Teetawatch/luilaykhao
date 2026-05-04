<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Trip;
use App\Models\TripSchedule;

$trips = Trip::all();
foreach ($trips as $trip) {
    echo "Trip: {$trip->title} (ID: {$trip->id}, Base Price: {$trip->price_per_person})\n";
    $schedules = TripSchedule::where('trip_id', $trip->id)
        ->where('status', 'open')
        ->where('departure_date', '>=', now()->startOfDay())
        ->get();
    
    if ($schedules->isEmpty()) {
        echo "  - No open upcoming schedules\n";
    }

    foreach ($schedules as $sch) {
        echo "  - Schedule #{$sch->id}: Override: " . ($sch->price_override ?? 'NULL') . "\n";
    }
}

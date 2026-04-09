<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\TripSchedule;
use App\Models\Vehicle;

$today = now()->toDateString();
echo "=== Today: {$today} ===\n\n";

// All vehicles
$vehicles = Vehicle::all(['id','name','license_plate']);
echo "Vehicles in DB:\n";
foreach ($vehicles as $v) {
    echo "  [{$v->id}] {$v->name} ({$v->license_plate})\n";
}

echo "\n=== All schedules today (any status) ===\n";
$all = TripSchedule::whereDate('departure_date', today())->get(['id','vehicle_id','departure_date','status','trip_id']);
echo "Count: " . $all->count() . "\n";
foreach ($all as $s) {
    echo "  Schedule #{$s->id}: vehicle_id={$s->vehicle_id}, trip_id={$s->trip_id}, date={$s->departure_date->toDateString()}, status={$s->status}\n";
}

echo "\n=== Exact query used in vehicleTodaySchedules (vehicle_id=1) ===\n";
$vehicleId = 1;
$query = TripSchedule::with('trip')
    ->where('vehicle_id', $vehicleId)
    ->whereDate('departure_date', today())
    ->whereNotIn('status', ['cancelled']);
echo "SQL: " . $query->toSql() . "\n";
echo "Bindings: " . json_encode($query->getBindings()) . "\n";
$result = $query->orderBy('departure_date')->get();
echo "Result count: " . $result->count() . "\n";
foreach ($result as $s) {
    echo "  #{$s->id}: trip={$s->trip->title}, date={$s->departure_date->toDateString()}, status={$s->status}\n";
}

echo "\n=== Check trip lat/lng for schedule #2 ===\n";
$s2 = TripSchedule::with('trip')->find(2);
if ($s2) {
    $t = $s2->trip;
    echo "  trip_id={$t->id}, title={$t->title}\n";
    echo "  latitude=" . var_export($t->latitude, true) . "\n";
    echo "  longitude=" . var_export($t->longitude, true) . "\n";
    echo "  departure_point=" . var_export($t->departure_point, true) . "\n";
}

echo "\n=== Simulated API JSON response ===\n";
$schedules = TripSchedule::with('trip')
    ->where('vehicle_id', 1)
    ->whereDate('departure_date', today())
    ->whereNotIn('status', ['cancelled'])
    ->orderBy('departure_date')
    ->get();
$data = $schedules->map(function ($s) {
    return [
        'id' => $s->id,
        'trip_title' => $s->trip->title ?? '',
        'trip_location' => $s->trip->location ?? '',
        'departure_point' => $s->trip->departure_point ?? '',
        'destination_lat' => $s->trip->latitude,
        'destination_lng' => $s->trip->longitude,
        'departure_date' => $s->departure_date->toDateString(),
        'total_seats' => $s->total_seats,
        'booked_seats' => $s->booked_seats,
        'available_seats' => $s->available_seats,
        'status' => $s->status,
    ];
});
echo json_encode(['success' => true, 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";




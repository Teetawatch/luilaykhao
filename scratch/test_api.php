<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Http\Request;

$slug = '-dOaB9';
$controller = new TripController();
$request = new Request();
$response = $controller->schedules($slug, $request);

echo $response->getContent();

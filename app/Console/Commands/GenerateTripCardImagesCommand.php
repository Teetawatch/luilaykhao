<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTripCardImageJob;
use App\Models\Trip;
use Illuminate\Console\Command;

/**
 * ย่อรูปการ์ดของทริปที่มีอยู่แล้วก่อนมีฟีเจอร์นี้ — ทริปใหม่/รูปที่เปลี่ยนทีหลัง
 * TripObserver สั่งย่อให้เอง รันครั้งเดียวหลัง deploy (รันซ้ำได้ ข้ามทริปที่ย่อแล้ว)
 */
class GenerateTripCardImagesCommand extends Command
{
    protected $signature = 'trips:card-images {--sync : ย่อในคำสั่งนี้เลย ไม่ส่งเข้าคิว}';

    protected $description = 'Generate downscaled card images for trips that do not have one for their current picture.';

    public function handle(): int
    {
        $queued = 0;
        Trip::query()->orderBy('id')->each(function (Trip $trip) use (&$queued) {
            if (! $trip->cardImageSource() || $trip->cardImageUrl() !== null) {
                return;
            }
            $this->option('sync')
                ? GenerateTripCardImageJob::dispatchSync($trip->id)
                : GenerateTripCardImageJob::dispatch($trip->id);
            $queued++;
        });

        $this->info(($this->option('sync') ? 'ย่อแล้ว ' : 'ส่งเข้าคิว ')."{$queued} ทริป");

        return self::SUCCESS;
    }
}

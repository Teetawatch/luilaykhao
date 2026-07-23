<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Trip;
use App\Support\MediaDisk;
use App\Support\ThaiDate;

/**
 * รูปร่าง JSON ของสถานที่ที่ใช้ร่วมกันทุก endpoint (list / detail / ปฏิทินฤดูกาล)
 * เพื่อให้หน้าเว็บอ่านฟิลด์ชุดเดียวกันได้เสมอ
 */
class PlacePresenter
{
    /** ข้อมูลย่อสำหรับการ์ดในหน้ารายการและปฏิทิน */
    public static function card(Place $place): array
    {
        return [
            'id' => $place->id,
            'name' => $place->name,
            'slug' => $place->slug,
            'type' => $place->type,
            'type_label' => $place->typeLabel(),
            'region' => $place->region,
            'region_label' => $place->regionLabel(),
            'province' => $place->province,
            'park' => $place->park,
            'elevation_m' => $place->elevation_m,
            'trail_distance_km' => $place->trail_distance_km !== null ? (float) $place->trail_distance_km : null,
            'elevation_gain_m' => $place->elevation_gain_m,
            'difficulty' => $place->difficulty,
            'difficulty_label' => $place->difficultyLabel(),
            'best_months' => array_values($place->best_months ?? []),
            'closed_months' => array_values($place->closed_months ?? []),
            'summary' => $place->summary,
            'cover_image' => $place->coverUrl(),
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
        ];
    }

    /**
     * ข้อมูลเต็มของหน้าสถานที่ — แนบทริปที่เปิดอยู่ไว้ท้ายสุดในฐานะ "ทางไปที่นี่"
     * ไม่ใช่ตัวเนื้อหาหลักของหน้า
     */
    public static function detail(Place $place): array
    {
        $place->loadMissing(['trips' => fn ($q) => $q->where('status', 'active')
            ->with(['schedules' => fn ($s) => $s
                ->where('departure_date', '>=', now('Asia/Bangkok')->startOfDay())
                ->orderBy('departure_date')])]);

        return array_merge(self::card($place), [
            'description' => $place->description,
            'highlights' => array_values($place->highlights ?? []),
            'know_before' => array_values($place->know_before ?? []),
            'season_note' => $place->season_note,
            'closure_note' => $place->closure_note,
            'gallery' => $place->galleryUrls(),
            'views_count' => $place->views_count,
            'trips' => $place->trips->map(fn (Trip $trip) => self::tripCard($trip))->values()->all(),
        ]);
    }

    private static function tripCard(Trip $trip): array
    {
        $upcoming = $trip->schedules;
        $next = $upcoming->first();

        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'slug' => $trip->slug,
            'duration_days' => $trip->duration_days,
            'cover_image' => MediaDisk::url($trip->thumbnail_image ?: $trip->cover_image),
            'price_from' => (float) ($upcoming
                ->map(fn ($schedule) => $schedule->effective_price)
                ->min() ?? $trip->price_per_person),
            'upcoming_count' => $upcoming->count(),
            'next_departure' => $next?->departure_date?->toDateString(),
            'next_departure_label' => $next?->departure_date
                ? ThaiDate::short($next->departure_date)
                : null,
        ];
    }
}

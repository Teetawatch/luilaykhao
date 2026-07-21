<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\ThaiDate;

/**
 * โปรไฟล์นักเดินทางสาธารณะ — หน้า /u/{handle} ที่แชร์ต่อได้โดยไม่ต้องล็อกอิน
 *
 * รวมของที่มีอยู่แล้วสามอย่างเข้าด้วยกัน: สถิติ+ตราสะสมจาก Passport,
 * ทริปที่เดินจบแล้ว และรูปจากรีวิวที่เจ้าตัวเป็นคนถ่าย. แสดงเฉพาะสิ่งที่
 * เจ้าของโปรไฟล์เปิดเผยเอง — ไม่มีอีเมล เบอร์โทร หรือข้อมูลการจอง.
 */
class PublicProfileService
{
    /** จำนวนรูปสูงสุดที่โชว์บนกำแพงรูป. */
    private const MAX_PHOTOS = 12;

    /** จำนวนทริปล่าสุดที่ไล่เรียงให้ดู. */
    private const MAX_TRIPS = 8;

    public function __construct(private PassportService $passportService) {}

    /**
     * ข้อมูลโปรไฟล์สาธารณะจาก handle — คืน null เมื่อไม่มีคนนี้ หรือเจ้าตัวปิดไว้
     * (ทั้งสองกรณีต้องตอบเหมือนกันจากภายนอก เพื่อไม่ให้เดาได้ว่ามีใครอยู่บ้าง).
     */
    public function forHandle(string $handle): ?array
    {
        $user = User::where('public_handle', $handle)
            ->where('public_profile_enabled', true)
            ->first();

        if (! $user) {
            return null;
        }

        $passport = $this->passportService->forUser($user->id);
        $trips = $this->passportService->completedTripsFor($user->id);

        $earned = collect($passport['badges'])->where('earned', true)->values();

        return [
            'handle' => $user->public_handle,
            'name' => $user->nickname ?: $user->name,
            'bio' => $user->public_bio,
            'avatar_url' => $user->avatar_url,
            'stats' => $passport['stats'],
            'highlights' => $passport['highlights'],
            'badges' => $earned->all(),
            'badges_earned_count' => $earned->count(),
            'badges_total' => count($passport['badges']),
            'trips' => $trips
                ->sortByDesc(fn (array $t) => $t['departure']->timestamp)
                ->take(self::MAX_TRIPS)
                ->map(fn (array $t) => [
                    'title' => $t['trip']->title,
                    'slug' => $t['trip']->slug,
                    'region' => $t['trip']->region,
                    'difficulty' => $t['trip']->difficulty,
                    'cover_image' => MediaDisk::url($t['trip']->cover_image),
                    'departure_date' => $t['departure']->toDateString(),
                    'departure_label' => ThaiDate::short($t['departure']),
                ])
                ->values()
                ->all(),
            'photos' => $this->photos($user),
        ];
    }

    /**
     * รูปที่เจ้าตัวถ่ายเอง — ดึงจากรีวิวที่ผ่านการอนุมัติแล้วเท่านั้น เพื่อไม่ให้
     * รูปที่ยังรอตรวจหลุดออกสู่หน้าสาธารณะ.
     */
    private function photos(User $user): array
    {
        return Review::where('user_id', $user->id)
            ->where('is_approved', true)
            ->whereNotNull('images')
            ->with('trip:id,title,slug')
            ->latest()
            ->get()
            ->flatMap(fn (Review $review) => collect($review->images ?? [])
                ->map(fn (string $path) => [
                    'url' => MediaDisk::url($path),
                    'trip_title' => $review->trip?->title,
                    'trip_slug' => $review->trip?->slug,
                ])
                ->filter(fn (array $photo) => $photo['url'] !== null))
            ->take(self::MAX_PHOTOS)
            ->values()
            ->all();
    }
}

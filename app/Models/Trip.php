<?php

namespace App\Models;

use App\Support\Countries;
use App\Support\TripDocumentRequirements;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    /**
     * Match the DB column default in-memory too, so freshly-created models
     * report 'active' before a refresh. Observers (e.g. the new-trip broadcast)
     * read `status` straight off the just-created instance, which would
     * otherwise be null when the caller omits it.
     */
    protected $attributes = [
        'status' => 'active',
        'views_count' => 0,
        'destination_type' => 'domestic',
    ];

    protected $fillable = [
        'title', 'slug', 'type', 'location', 'region', 'description',
        'destination_type', 'country_code', 'timezone',
        'difficulty', 'duration_days', 'distance_km', 'elevation_gain_m', 'max_participants',
        'price_per_person', 'departure_point', 'latitude', 'longitude',
        'status', 'cover_image', 'thumbnail_image', 'gallery', 'videos', 'inclusions', 'exclusions', 'is_featured',
        'highlights', 'is_women_only', 'must_know', 'itinerary', 'preparations', 'faqs', 'rental_items',
        'document_requirements',
        'route_track',
    ];

    protected function casts(): array
    {
        return [
            'price_per_person' => 'decimal:2',
            'duration_days' => 'integer',
            'distance_km' => 'decimal:2',
            'elevation_gain_m' => 'integer',
            'route_track' => 'array',
            'max_participants' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_featured' => 'boolean',
            'views_count' => 'integer',
            'gallery' => 'array',
            'videos' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'highlights' => 'array',
            'is_women_only' => 'boolean',
            'must_know' => 'array',
            'itinerary' => 'array',
            'preparations' => 'array',
            'faqs' => 'array',
            'rental_items' => 'array',
            'document_requirements' => 'array',
        ];
    }

    /**
     * เอกสารที่ทริปนี้ขอให้ลูกค้าแนบ — จัดระเบียบแล้ว พร้อมส่งให้ทุกหน้าจอ
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool}>
     */
    public function documentRequirements(): array
    {
        return TripDocumentRequirements::normalize($this->document_requirements);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    /**
     * ทริปนี้ออกนอกประเทศไหม — ตัวตัดสินใจเดียวที่ทุกที่ควรถาม
     *
     * ผูกกับ `destination_type` ไม่ใช่ `country_code` เพราะรอบที่แอดมินยังไม่ได้
     * เลือกประเทศก็ยังต้องบังคับเก็บพาสปอร์ตอยู่ดี ปลอดภัยกว่าเดาจากรหัสว่าง
     */
    public function isInternational(): bool
    {
        return $this->destination_type === 'international';
    }

    /** "🇳🇵 เนปาล" สำหรับติดป้ายบนการ์ดทริป; null เมื่อเป็นทริปในประเทศ */
    public function countryLabel(): ?string
    {
        return $this->isInternational() ? Countries::label($this->country_code) : null;
    }

    /**
     * เขตเวลาปลายทาง ใช้กำกับกำหนดการรายวันว่าเป็นเวลาท้องถิ่น
     *
     * ค่าที่แอดมินกรอกเองมาก่อน แล้วค่อยถอยไปใช้เขตเวลามาตรฐานของประเทศ
     * ประเทศกว้าง ๆ อย่างสหรัฐฯ จึงแก้ให้ตรงรัฐได้โดยไม่ต้องแตะทะเบียนประเทศ
     */
    public function destinationTimezone(): ?string
    {
        if (! $this->isInternational()) {
            return null;
        }

        return $this->timezone ?: Countries::timezone($this->country_code);
    }

    /**
     * ปลายทางเวลาต่างจากไทยกี่นาที (บวก = ปลายทางเร็วกว่าไทย)
     *
     * คิดจาก "ตอนนี้" เพราะประเทศที่มี DST (ยุโรป/สหรัฐฯ) ต่างจากไทยไม่เท่ากัน
     * ทั้งปี — ค่าที่ hardcode ไว้จะผิดครึ่งปี
     *
     * มีไว้ให้ฝั่งแอปโดยเฉพาะ: แอปไม่ได้ลงฐานข้อมูลเขตเวลา IANA จึงแปลงจากชื่อ
     * เขตเวลาเองไม่ได้ ส่งเป็นนาทีให้บวกลบตรง ๆ
     */
    public function destinationOffsetMinutes(): ?int
    {
        $timezone = $this->destinationTimezone();
        if (! $timezone) {
            return null;
        }

        try {
            $now = now();
            $home = $now->copy()->setTimezone(Countries::timezone(Countries::HOME));
            $there = $now->copy()->setTimezone($timezone);

            return (int) round(($there->getOffset() - $home->getOffset()) / 60);
        } catch (\Throwable) {
            // เขตเวลาที่แอดมินกรอกผิด ไม่ควรทำให้หน้าทริปพัง
            return null;
        }
    }

    /** สถานที่ที่ทริปนี้พาไป — ข้อมูลของสถานที่อยู่ต่อได้แม้ทริปนี้จะปิดขาย */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'place_trip');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function expenseTemplates(): HasMany
    {
        return $this->hasMany(ExpenseTemplate::class)->orderBy('sort_order')->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TripPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getConfirmedPassengersCountAttribute(): int
    {
        return BookingPassenger::whereHas('booking', function ($q) {
            $q->where('status', 'completed')
                ->whereHas('schedule', function ($sq) {
                    $sq->where('trip_id', $this->id);
                });
        })->count();
    }

    /**
     * Total successful bookings (confirmed or completed) across this trip's
     * schedules. Powers the home "ยอดการจอง" trust stat.
     */
    public function getBookingsCountAttribute(): int
    {
        return Booking::whereIn('status', ['confirmed', 'completed'])
            ->whereHas('schedule', function ($sq) {
                $sq->where('trip_id', $this->id);
            })->count();
    }

    /**
     * Head-count of travellers across this trip's successful bookings
     * (confirmed or completed) — i.e. how many people have booked, not how
     * many booking records exist. Powers the home "คนจองแล้ว" trust stat.
     */
    public function getBookedPassengersCountAttribute(): int
    {
        return BookingPassenger::whereHas('booking', function ($q) {
            $q->whereIn('status', ['confirmed', 'completed'])
                ->whereHas('schedule', function ($sq) {
                    $sq->where('trip_id', $this->id);
                });
        })->count();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

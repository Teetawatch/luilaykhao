<?php

namespace App\Models;

use App\Support\Countries;
use App\Support\MedalDesign;
use App\Support\TripDocumentRequirements;
use App\Support\TripRentalItems;
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
        'destination_type', 'country_code', 'timezone', 'travel_document_mode',
        'difficulty', 'duration_days', 'distance_km', 'elevation_gain_m', 'max_participants',
        'medal_name', 'medal_icon', 'medal_color', 'medal_image',
        'price_per_person', 'departure_point', 'latitude', 'longitude',
        'status', 'cover_image', 'thumbnail_image', 'gallery', 'videos', 'inclusions', 'exclusions', 'is_featured',
        'highlights', 'is_women_only', 'must_know', 'itinerary', 'preparations', 'faqs', 'rental_items',
        'document_requirements', 'checkin_bring',
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

    /**
     * อุปกรณ์ให้เช่าของทริปนี้ — จัดระเบียบแล้ว มี key ถาวรทุกแถว
     *
     * ทุกฝั่งต้องอ่านผ่านตัวนี้ ไม่ใช่ `$trip->rental_items` ดิบ ๆ ไม่อย่างนั้น
     * ลำดับแถว (ที่ฝั่งจองส่งมาเป็น index) จะไม่ตรงกันเมื่อมีแถวเสียปนอยู่
     *
     * @return array<int, array<string, mixed>>
     */
    public function rentalItems(): array
    {
        return TripRentalItems::normalize($this->rental_items);
    }

    /**
     * "สิ่งที่ต้องพกวันเดินทาง" ที่แอดมินพิมพ์ไว้ — null เมื่อไม่ต้องพกอะไรเป็นพิเศษ
     *
     * เว้นว่างแล้วต้องหมายถึง "ไม่ต้องใช้" จริง ๆ ไม่ใช่ "ยังไม่ได้ตั้งค่า" มิฉะนั้น
     * ทริปที่ไม่ขอเอกสารจะไม่มีทางปิดประโยคนั้นในห้องแชทได้เลย
     */
    public function checkinBringNote(): ?string
    {
        $note = trim((string) $this->checkin_bring);

        return $note !== '' ? $note : null;
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    /** ของที่ต้องซื้อทุกรอบ — แต่ละรอบก๊อปไปเป็นใบซื้อของของตัวเอง */
    public function shoppingItems(): HasMany
    {
        return $this->hasMany(TripShoppingItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function medals(): HasMany
    {
        return $this->hasMany(TripMedal::class);
    }

    /** เก็บสีเหรียญในรูปเดียวเสมอ ("#15803D") ไม่ว่าฟอร์มจะส่งตัวเล็ก/ไม่มี # มา */
    public function setMedalColorAttribute(?string $value): void
    {
        $this->attributes['medal_color'] = MedalDesign::normalizeColor($value);
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

    /** ต้องกรอกพาสปอร์ตตอนจอง — ค่าเดิมของทุกทริปต่างประเทศ */
    public const DOCS_PASSPORT = 'passport';

    /** ให้ผู้เดินทางเลือก: มีพาสปอร์ต หรือให้ทีมงานทำ Border Pass (ลาว/กัมพูชาทางบก) */
    public const DOCS_PASSPORT_OR_BORDER_PASS = 'passport_or_border_pass';

    /** ไม่ต้องกรอกล่วงหน้า — ทีมงานดูเอกสารฉบับจริงหน้างาน */
    public const DOCS_ON_SITE = 'on_site';

    public const TRAVEL_DOCUMENT_MODES = [
        self::DOCS_PASSPORT,
        self::DOCS_PASSPORT_OR_BORDER_PASS,
        self::DOCS_ON_SITE,
    ];

    /** คำตอบรายคนของ [DOCS_PASSPORT_OR_BORDER_PASS] */
    public const DOC_PASSPORT = 'passport';

    public const DOC_BORDER_PASS = 'border_pass';

    /**
     * ทริปนี้เก็บเอกสารเดินทางแบบไหน — null สำหรับทริปในประเทศ
     *
     * ทริปต่างประเทศที่ยังไม่เคยตั้งค่า (null ในคอลัมน์) = กรอกพาสปอร์ต เหมือน
     * ก่อนมีตัวเลือกนี้ ทริปเก่าจึงทำงานเหมือนเดิมทุกอย่าง
     */
    public function travelDocumentMode(): ?string
    {
        if (! $this->isInternational()) {
            return null;
        }

        return in_array($this->travel_document_mode, self::TRAVEL_DOCUMENT_MODES, true)
            ? $this->travel_document_mode
            : self::DOCS_PASSPORT;
    }

    /** มีช่องพาสปอร์ตให้กรอกในฟอร์มไหม (บังคับกับทุกคน หรือกับคนที่เลือกพาสปอร์ต) */
    public function collectsPassport(): bool
    {
        return in_array($this->travelDocumentMode(), [self::DOCS_PASSPORT, self::DOCS_PASSPORT_OR_BORDER_PASS], true);
    }

    public function offersBorderPass(): bool
    {
        return $this->travelDocumentMode() === self::DOCS_PASSPORT_OR_BORDER_PASS;
    }

    /**
     * เอกสารที่ผู้เดินทางคนนี้ใช้จริง หลังกรองคำตอบที่ทริปไม่ได้เปิดให้เลือก
     *
     * Border Pass ออกให้คนสัญชาติไทยเท่านั้น ชาวต่างชาติจึงตกเป็นพาสปอร์ตเสมอ
     * คืน null เมื่อทริปไม่ได้ให้เลือก (ไม่มีอะไรต้องจำ)
     */
    public function resolveTravelDocument(?string $choice, ?string $nationality): ?string
    {
        if (! $this->offersBorderPass()) {
            return null;
        }

        return $choice === self::DOC_BORDER_PASS && ($nationality ?: Countries::HOME) === Countries::HOME
            ? self::DOC_BORDER_PASS
            : self::DOC_PASSPORT;
    }

    /** คนนี้ต้องมีข้อมูลพาสปอร์ตในระบบไหม — ตัวตัดสินเดียวของทุกช่องทางที่กรอก */
    public function needsPassportFor(?string $choice, ?string $nationality): bool
    {
        if (! $this->collectsPassport()) {
            return false;
        }

        return $this->resolveTravelDocument($choice, $nationality) !== self::DOC_BORDER_PASS;
    }

    /**
     * กฎ "ต้องมี" ของช่องพาสปอร์ตในฟอร์มที่กรอกทีละคน (ลิงก์กรอกเอง, ลิงก์รับ
     * ข้อมูล, ส่งต่อที่นั่ง) — ทริปที่ให้เลือกบังคับเฉพาะคนที่ไม่ได้เลือก Border Pass
     */
    public function passportRule(string $choiceField = 'travel_document'): string
    {
        return match (true) {
            ! $this->collectsPassport() => 'nullable',
            $this->offersBorderPass() => "required_unless:{$choiceField},".self::DOC_BORDER_PASS,
            default => 'required',
        };
    }

    /**
     * ปรับข้อมูลเอกสารของผู้เดินทางหนึ่งคนให้ตรงกับโหมดของทริป ก่อนบันทึก
     *
     * ทุกช่องทางที่สร้าง/แก้ผู้เดินทางเรียกตัวนี้ (จอง, ลิงก์กรอกเอง, ส่งต่อที่นั่ง,
     * ลิงก์รับข้อมูล) — คำตอบ Border Pass เก็บเฉพาะทริปที่ให้เลือก และคนที่ไม่ต้อง
     * ใช้พาสปอร์ตก็ไม่เก็บเลขพาสปอร์ตไว้ (ทีมงานจะไม่สับสนว่าใช้เอกสารไหน และไม่
     * ถือข้อมูลที่ไม่ได้ใช้)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyTravelDocument(array $data): array
    {
        $data['travel_document'] = $this->resolveTravelDocument(
            $data['travel_document'] ?? null,
            $data['nationality'] ?? null,
        );

        if ($this->isInternational() && ! $this->needsPassportFor($data['travel_document'], $data['nationality'] ?? null)) {
            $data['name_en'] = null;
            $data['passport_no'] = null;
            $data['passport_expires_at'] = null;
        }

        return $data;
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

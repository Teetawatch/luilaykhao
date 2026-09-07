<?php

namespace App\Http\Controllers;

use App\Models\CustomerIntake;
use App\Models\IntakeLink;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Rules\ThaiIdCard;
use App\Services\CustomerIntakeService;
use App\Services\IntakeSeatService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * หน้าให้ลูกค้าที่ทักมาทางแชทกรอกข้อมูลของตัวเอง ก่อนจะมีการจอง
 *
 * มีสองประตู
 *  - `/r/{token}` ลิงก์ของทีมงาน ใช้ซ้ำได้ ใครเปิดก็เปิดกลุ่มใหม่ของตัวเอง
 *  - `/g/{token}` ลิงก์ของกลุ่ม คนแรกส่งต่อในแชท เพื่อนเข้ามากรอกของตัวเอง
 *
 * ทั้งสองหน้า "เขียนได้อย่างเดียว" — ไม่เคยแสดงข้อมูลที่กรอกไปแล้วกลับออกมา
 * เพราะลิงก์ถูกส่งต่อในแชทกลุ่ม ใครก็เปิดได้ หน้ากลุ่มแสดงได้แค่ชื่อเล่นว่า
 * ใครกรอกแล้วบ้าง เพื่อให้รู้ว่าเหลือรอใคร
 */
class PublicIntakeController extends Controller
{
    public function __construct(
        private readonly CustomerIntakeService $intakes,
        private readonly IntakeSeatService $seats,
    ) {}

    public function show(string $token): View
    {
        $link = $this->resolveLink($token);
        $schedule = $link->schedule;
        $type = $link->booking_type;
        $closed = $schedule !== null && ! $this->roundOpenFor($schedule, $type);

        return view('intake.form', [
            'link' => $link,
            'schedule' => $schedule,
            'trip' => $schedule?->trip,
            'closed' => $closed,
            // ลิงก์บอกแล้วว่าเป็นจองปกติหรือจอยทริป ยกเว้นลิงก์แบบ ask ที่ให้
            // ลูกค้าเลือกเอง — ฟอร์มจึงต้องรู้ทั้งประเภทและว่าต้องถามไหม
            'bookingType' => $type,
            'mayChooseType' => ! $link->locksBookingType(),
            // เลือกจุดขึ้นรถได้ต่อเมื่อรู้แน่ว่าเป็นรอบไหน — จุดรับเป็นของรอบ
            // หน้าที่ยังให้เลือกรอบอยู่จึงไม่มีรายการที่ถูกต้องให้เลือก
            // (คนจอยทริปไปเอง ไม่มีรถให้ขึ้น จึงไม่ต้องเลือกเลย)
            'pickupPoints' => $closed ? collect() : $this->pickupChoices($schedule, $link->isJoinTrip()),
            // ผังที่นั่งของรอบที่ผูกไว้ — เลือกได้ตั้งแต่ตอนกรอก แต่ยังไม่ล็อก
            // (รอบที่ยังไม่รู้ว่าเป็นรอบไหน ไม่มีผังที่ถูกต้องให้เลือก เหมือนจุดขึ้นรถ)
            'seatMap' => $closed ? null : $this->seats->mapFor($schedule, $link->isJoinTrip()),
            // รอบที่ผูกไว้เต็ม/ผ่านไปแล้ว ยังรับข้อมูลอยู่ (ทีมงานเอาไปเสนอรอบอื่นได้)
            // แต่ต้องบอกตั้งแต่ต้นและยื่นรอบอื่นให้เลือกตรงนั้นเลย ไม่ใช่ปล่อยให้
            // กรอกจนจบแล้วค่อยรู้ตอนทีมงานตอบกลับ
            'scheduleOptions' => match (true) {
                $schedule === null => $this->openSchedules($type),
                $closed => $this->siblingRounds($schedule, $type),
                default => collect(),
            },
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $link = $this->resolveLink($token);
        $bound = $link->schedule;

        // ประเภทมาจากลิงก์เสมอ ยกเว้นลิงก์แบบ ask ที่อ่านจากที่ลูกค้าเลือก —
        // ค่าที่ส่งมาจากเบราว์เซอร์แก้ได้ จึงไม่ให้มันล้มล้างลิงก์ที่ล็อกไว้แล้ว
        $type = $link->resolveBookingType($request->input('booking_type'));
        $isJoin = $type === IntakeLink::TYPE_JOIN;

        // เลือกรอบเองได้เมื่อลิงก์ไม่ได้ผูกรอบ หรือรอบที่ผูกไว้ปิดรับไปแล้ว
        $mayChoose = $bound === null || ! $bound->acceptsBookingType($type);
        $schedule = $mayChoose
            ? ($this->pickSchedule($request->input('schedule_id'), $type) ?: $bound)
            : $bound;

        $pickupChoices = $mayChoose ? collect() : $this->pickupChoices($schedule, $isJoin);
        // เลือกที่นั่งได้เฉพาะรอบที่รู้แน่แล้วว่าเป็นรอบไหน — ผังเป็นของรอบ
        $seatSchedule = $mayChoose ? null : $schedule;

        $data = $this->validatePerson($request, $schedule, $pickupChoices, $seatSchedule, $isJoin, [
            'party_size' => ['nullable', 'integer', 'min:1', 'max:'.CustomerIntakeService::MAX_PEOPLE],
            'note' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', Rule::in(['line', 'facebook', 'instagram', 'other'])],
            'schedule_id' => [$mayChoose ? 'nullable' : 'prohibited', 'integer'],
            'booking_type' => $link->locksBookingType()
                ? ['nullable']
                : ['required', Rule::in([IntakeLink::TYPE_NORMAL, IntakeLink::TYPE_JOIN])],
            'consent' => ['accepted'],
        ]);

        if ($error = $this->passportWindowError($data, $schedule)) {
            return back()->withInput()->withErrors(['passport_expires_at' => $error]);
        }

        // ลูกค้าเลือกจอยเองกับรอบที่ไม่ได้เปิดจอย = เลือกสิ่งที่ไม่มีอยู่จริง
        // ต้องบอกกลับ ไม่ใช่เงียบ ๆ เปลี่ยนให้เป็นจองปกติแทนที่เขาไม่ได้ขอ
        // (ลิงก์ที่ล็อกจอยไว้ไม่ผ่านทางนี้ — ข้อมูลลูกค้าสำคัญกว่าลิงก์ที่ออกผิด
        // ทีมงานเห็นในหน้าแอดมินแล้วโทรกลับได้)
        if ($isJoin && ! $link->locksBookingType() && $schedule && ! $schedule->join_trip_enabled) {
            return back()->withInput()->withErrors([
                'booking_type' => 'รอบที่เลือกไม่มีจอยทริป กรุณาเลือกแบบจองปกติ หรือเลือกรอบอื่น',
            ]);
        }

        $data['booking_type'] = $type;

        $intake = $this->intakes->openFromLink($link, $data, $schedule);

        return redirect()
            ->route('public.intake.group.show', $intake->token)
            ->with('intake_just_filled', $data['name']);
    }

    public function groupShow(string $token): View
    {
        $intake = $this->resolveIntake($token);

        return view('intake.group', [
            'intake' => $intake,
            'schedule' => $intake->schedule,
            'trip' => $intake->schedule?->trip,
            // เพื่อนที่ตามมากรอกทีหลังควรรู้ด้วยว่าระหว่างนั้นรอบเต็มไปแล้ว —
            // ข้อมูลยังรับอยู่ เพราะทีมงานเอาไปเสนอรอบอื่นหรือคิวรอให้ได้
            'closed' => $intake->schedule !== null
                && ! $intake->schedule->acceptsBookingType($intake->booking_type),
            // เพื่อนในกลุ่มเป็นประเภทเดียวกับกลุ่มเสมอ — กลุ่มจอยทริปจึงไม่มีใคร
            // ต้องเลือกจุดขึ้นรถ
            'pickupPoints' => $this->pickupChoices($intake->schedule, $intake->isJoinTrip()),
            // ที่นั่งของเพื่อนในกลุ่มเดียวกันติดชื่อเล่นกำกับ — คนที่กลับมาแก้ข้อมูล
            // ของตัวเองจะได้รู้ว่าที่นั่งที่หายไปคือของตัวเอง ไม่ใช่คนแปลกหน้า
            'seatMap' => $this->seats->mapFor($intake->schedule, $intake->isJoinTrip(), $intake),
            // ชื่อเล่นเท่านั้น — ลิงก์นี้อยู่ในแชทกลุ่ม ใครเปิดก็ได้
            'filled' => $intake->people()->get()->map->publicLabel()->all(),
            'justFilled' => session('intake_just_filled'),
        ]);
    }

    public function groupSubmit(Request $request, string $token): RedirectResponse
    {
        $intake = $this->resolveIntake($token);

        if (! $intake->acceptsSubmissions()) {
            return back()->withErrors(['name' => 'กลุ่มนี้ปิดรับข้อมูลแล้ว เพราะทีมงานเปิดการจองให้เรียบร้อยแล้ว']);
        }

        $data = $this->validatePerson(
            $request,
            $intake->schedule,
            $this->pickupChoices($intake->schedule, $intake->isJoinTrip()),
            $intake->schedule,
            $intake->isJoinTrip(),
            ['consent' => ['accepted']],
        );

        if ($error = $this->passportWindowError($data, $intake->schedule)) {
            return back()->withInput()->withErrors(['passport_expires_at' => $error]);
        }

        try {
            $this->intakes->addToGroup($intake, $data);
        } catch (\Exception $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('public.intake.group.show', $intake->token)
            ->with('intake_just_filled', $data['name']);
    }

    /**
     * ชุดกฎของ "หนึ่งคน" ใช้ร่วมกันทั้งหน้าลิงก์ทีมงานและหน้ากลุ่ม
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validatePerson(
        Request $request,
        ?TripSchedule $schedule,
        Collection $pickupPoints,
        ?TripSchedule $seatSchedule = null,
        bool $isJoin = false,
        array $extra = [],
    ): array {
        // ที่นั่งที่ยังเลือกได้ ณ วินาทีนี้ — ไม่ใช่ตอนที่ลูกค้าเปิดหน้าจอ ระหว่างที่
        // เขากรอกอยู่ อาจมีคนจองจริงและจ่ายเงินตัดหน้าไปแล้ว ซึ่งคนนั้นได้สิทธิ์ก่อน
        $selectableSeats = $this->seats->selectableSeatIds($seatSchedule, $isJoin, $request->input('phone'));
        // ทริปต่างประเทศต้องได้เอกสารเดินทางตั้งแต่ตอนนี้ ไม่งั้นแอดมินก็ต้อง
        // กลับไปไล่ถามในแชทอยู่ดี ซึ่งคือปัญหาเดิมที่หน้านี้ตั้งใจแก้
        $isInternational = (bool) $schedule?->trip?->isInternational();
        $passportRule = $isInternational ? 'required' : 'nullable';

        // ทุกช่องในฟอร์มนี้บังคับกรอก — ข้อมูลชุดนี้ไปทำประกันและใช้ดูแลลูกค้า
        // ระหว่างทริป ช่องที่ปล่อยว่างได้คือช่องที่ทีมงานต้องกลับไปไล่ถามในแชท
        // ซึ่งคือปัญหาเดิมที่หน้านี้ตั้งใจแก้ ช่องที่ "ไม่มีจริง ๆ" ก็มีปุ่มให้ตอบว่า
        // ไม่มี (แพ้อาหาร/โรคประจำตัว) และกรุ๊ปเลือดตอบว่าไม่ทราบได้ แต่ต้องตอบ
        $validated = $request->validate([
            'title' => ['required', Rule::in(['นาย', 'นาง', 'นางสาว'])],
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            // อีเมลบังคับกรอก — ใบเสร็จ กำหนดการ และอีเมลยืนยันการจองส่งทางนี้ทางเดียว
            'email' => ['required', 'email', 'max:120'],
            'id_card' => ['required', 'string', 'max:20', new ThaiIdCard],
            'birth_date' => ['required', 'date', 'before:today'],
            // present ไม่ใช่ required — "ไม่ทราบ" เป็นคำตอบที่ยอมรับได้ (ค่าว่าง)
            // แต่ต้องเป็นการกดเลือก ไม่ใช่การข้ามไปเฉย ๆ
            'blood_group' => ['present', Rule::in(['A', 'B', 'AB', 'O', ''])],
            'name_en' => [$passportRule, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s.\'-]+$/'],
            'passport_no' => [$passportRule, 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'passport_expires_at' => [$passportRule, 'nullable', 'date', 'after:today'],
            'emergency_contact' => ['required', 'string', 'max:120'],
            'emergency_phone' => ['required', 'string', 'max:20'],
            'allergies' => ['required', 'string', 'max:500'],
            'health_notes' => ['required', 'string', 'max:500'],
            'halal_food' => ['required', 'boolean'],
            // ที่นั่งบังคับเลือกเฉพาะตอนที่ยังมีที่ให้เลือกจริง — รอบที่ที่นั่งถูกจอง
            // ไปหมดแล้วยังต้องรับข้อมูลลูกค้าไว้ได้ (ทีมงานเอาไปเสนอคิวรอ/รอบอื่น)
            'seat_id' => empty($selectableSeats)
                ? ['nullable', 'string', 'max:10']
                : ['required', Rule::in($selectableSeats)],
            // รอบที่มีจุดรับ ต้องเลือกให้ครบทุกคน — คนที่รู้ว่าตัวเองขึ้นที่ไหนคือ
            // เจ้าตัว ไม่ใช่คนที่กดลิงก์มาก่อน และราคาต่อคนก็ผูกกับจุดที่ขึ้น
            'pickup_point_id' => $pickupPoints->isEmpty()
                ? ['nullable', 'integer']
                : ['required', Rule::in($pickupPoints->pluck('id')->all())],
            ...$extra,
        ], [
            'title.required' => 'กรุณาเลือกคำนำหน้า',
            'title.in' => 'กรุณาเลือกคำนำหน้า',
            'name.required' => 'กรุณากรอกชื่อ-นามสกุล',
            'nickname.required' => 'กรุณากรอกชื่อเล่น (ทีมงานใช้เรียกหน้างาน)',
            'id_card.required' => 'กรุณากรอกเลขบัตรประชาชน (ใช้ทำประกันการเดินทาง)',
            'birth_date.required' => 'กรุณาระบุวันเกิด (ใช้ทำประกันการเดินทาง)',
            'blood_group.present' => 'กรุณาเลือกกรุ๊ปเลือด เลือก "ไม่ทราบ" ได้ถ้าไม่แน่ใจ',
            'emergency_contact.required' => 'กรุณากรอกผู้ติดต่อฉุกเฉิน',
            'emergency_phone.required' => 'กรุณากรอกเบอร์ผู้ติดต่อฉุกเฉิน',
            'allergies.required' => 'กรุณากรอกการแพ้อาหาร/ยา กดปุ่ม "ไม่มี" ได้ถ้าไม่มี',
            'health_notes.required' => 'กรุณากรอกโรคประจำตัว/หมายเหตุสุขภาพ กดปุ่ม "ไม่มี" ได้ถ้าไม่มี',
            'halal_food.required' => 'กรุณาเลือกว่าต้องการอาหารฮาลาลหรือไม่',
            'seat_id.required' => 'กรุณาเลือกที่นั่งของคุณ',
            'seat_id.in' => 'ที่นั่งที่เลือกเพิ่งถูกใช้ไปแล้ว กรุณาเลือกที่นั่งอื่น',
            'pickup_point_id.required' => 'กรุณาเลือกจุดขึ้นรถ',
            'pickup_point_id.in' => 'จุดขึ้นรถนี้ไม่อยู่ในรอบเดินทางนี้',
            'phone.required' => 'กรุณากรอกเบอร์โทรศัพท์',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'birth_date.before' => 'วันเกิดต้องเป็นวันในอดีต',
            'consent.accepted' => 'กรุณายินยอมให้เก็บข้อมูลก่อนส่ง',
            'name_en.required' => 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษให้ตรงกับหน้าพาสปอร์ต',
            'name_en.regex' => 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น',
            'passport_no.required' => 'กรุณากรอกเลขที่พาสปอร์ต',
            'passport_no.regex' => 'เลขที่พาสปอร์ตไม่ถูกต้อง',
            'passport_expires_at.required' => 'กรุณาระบุวันหมดอายุพาสปอร์ต',
            'passport_expires_at.after' => 'พาสปอร์ตหมดอายุแล้ว',
        ]);

        // ที่นั่งเป็นของ "คัน" ไม่ใช่ของรอบ — คันมาจากฝั่งเรา ไม่ใช่จากเบราว์เซอร์
        // รอบที่ไม่มีอะไรให้เลือก ค่าที่หลุดมาจากฟอร์มเก่าต้องไม่ติดไปด้วย
        if (empty($selectableSeats)) {
            unset($validated['seat_id']);
        } elseif (filled($validated['seat_id'] ?? null) && $seatSchedule) {
            $validated['seat_vehicle_option_id'] = (int) ($this->seats->defaultOption($seatSchedule)?->id ?? 0);
        }

        // ไอพีเก็บคู่กับความยินยอม ไม่ได้เอาไปทำอย่างอื่น
        return [...$validated, 'consent_ip' => $request->ip()];
    }

    /** เกณฑ์ 6 เดือนเดียวกับตอนจอง — ตกตั้งแต่ตอนนี้ดีกว่าไปตกตอนออกตั๋ว */
    private function passportWindowError(array $data, ?TripSchedule $schedule): ?string
    {
        if (! $schedule?->trip?->isInternational() || blank($data['passport_expires_at'] ?? null)) {
            return null;
        }

        $departure = $schedule->departure_date;
        if ($departure && Carbon::parse($data['passport_expires_at'])->lt($departure->copy()->addMonths(6))) {
            return 'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง';
        }

        return null;
    }

    /**
     * จุดขึ้นรถที่เลือกได้ของรอบนี้ — ว่างเปล่าเมื่อยังไม่รู้รอบ หรือรอบนี้ไม่มีจุดรับ
     * (รอบที่บินไปใช้จุดนัดพบที่สนามบินแทน ไม่มีอะไรให้เลือก)
     *
     * @return Collection<int, SchedulePickupPoint>
     */
    private function pickupChoices(?TripSchedule $schedule, bool $joinTrip = false): Collection
    {
        // จอยทริป = เดินทางไปเอง ไม่มีรถของเราให้ขึ้น การถามจุดขึ้นรถจึงไม่ใช่แค่
        // เกินจำเป็น แต่ทำให้ลูกค้าเข้าใจผิดว่ามีรถไปรับ
        if (! $schedule || $joinTrip || $schedule->isFlight()) {
            return collect();
        }

        return $schedule->pickupPoints()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * รอบนี้ยังรับกลุ่มแบบนี้ได้ไหม
     *
     * ลิงก์แบบ ask ยังไม่รู้ว่าลูกค้าจะเลือกอะไร ผ่านถ้าทางใดทางหนึ่งยังเปิด —
     * รอบที่รถเต็มแต่โควตาจอยยังว่างคือกรณีที่ต้องไม่หายไปจากลิสต์
     */
    private function roundOpenFor(TripSchedule $schedule, string $type): bool
    {
        if ($type === IntakeLink::TYPE_ASK) {
            return $schedule->acceptsNewCustomers() || $schedule->acceptsNewCustomers(true);
        }

        return $schedule->acceptsBookingType($type);
    }

    /** รอบที่ยังรับคนได้จริง — ใช้กับลิงก์กลางที่ไม่ผูกรอบ */
    private function openSchedules(string $type = IntakeLink::TYPE_NORMAL)
    {
        return TripSchedule::with('trip')
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->orderBy('departure_date')
            ->limit(60)
            ->get()
            // รอบที่เต็มแล้วไม่ควรอยู่ในลิสต์ให้เลือก — เลือกไปก็ได้คำตอบเดียวกัน
            // ลิงก์จอยทริปเห็นเฉพาะรอบที่เปิดจอย ด้วยเหตุผลเดียวกัน
            ->filter(fn (TripSchedule $schedule) => $schedule->trip !== null && $this->roundOpenFor($schedule, $type))
            ->values();
    }

    /** รอบอื่นของทริปเดียวกัน — ทางออกให้ลูกค้าที่เปิดลิงก์ของรอบที่เต็มไปแล้ว */
    private function siblingRounds(TripSchedule $schedule, string $type = IntakeLink::TYPE_NORMAL)
    {
        return TripSchedule::with('trip')
            ->where('trip_id', $schedule->trip_id)
            ->whereKeyNot($schedule->id)
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->orderBy('departure_date')
            ->limit(12)
            ->get()
            ->filter(fn (TripSchedule $other) => $other->trip !== null && $this->roundOpenFor($other, $type))
            ->values();
    }

    private function pickSchedule(mixed $scheduleId, string $type = IntakeLink::TYPE_NORMAL): ?TripSchedule
    {
        if (blank($scheduleId)) {
            return null; // ลูกค้ายังไม่เลือกรอบก็รับข้อมูลไว้ก่อน แอดมินผูกรอบให้ทีหลังได้
        }

        // ถามฐานข้อมูลตรง ๆ ไม่ใช่ค้นจากลิสต์ที่ตัดมา 60 แถว ไม่งั้นรอบที่อยู่
        // ไกลออกไปจะถูกปัดทิ้งเงียบ ๆ กลายเป็นข้อมูลที่ไม่ผูกรอบโดยไม่มีใครรู้
        $schedule = TripSchedule::with('trip')
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->find((int) $scheduleId);

        return $schedule && $this->roundOpenFor($schedule, $type) ? $schedule : null;
    }

    private function resolveLink(string $token): IntakeLink
    {
        $link = IntakeLink::with('schedule.trip')->where('token', $token)->first();

        if (! $link || ! $link->is_active) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $link;
    }

    private function resolveIntake(string $token): CustomerIntake
    {
        $intake = CustomerIntake::with('schedule.trip')->where('token', $token)->first();

        if (! $intake) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $intake;
    }
}

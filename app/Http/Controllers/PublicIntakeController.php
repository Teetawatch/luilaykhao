<?php

namespace App\Http\Controllers;

use App\Models\CustomerIntake;
use App\Models\IntakeLink;
use App\Models\TripSchedule;
use App\Rules\ThaiIdCard;
use App\Services\CustomerIntakeService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(private readonly CustomerIntakeService $intakes) {}

    public function show(string $token): View
    {
        $link = $this->resolveLink($token);
        $schedule = $link->schedule;
        $closed = $schedule !== null && ! $schedule->acceptsNewCustomers();

        return view('intake.form', [
            'link' => $link,
            'schedule' => $schedule,
            'trip' => $schedule?->trip,
            'closed' => $closed,
            // รอบที่ผูกไว้เต็ม/ผ่านไปแล้ว ยังรับข้อมูลอยู่ (ทีมงานเอาไปเสนอรอบอื่นได้)
            // แต่ต้องบอกตั้งแต่ต้นและยื่นรอบอื่นให้เลือกตรงนั้นเลย ไม่ใช่ปล่อยให้
            // กรอกจนจบแล้วค่อยรู้ตอนทีมงานตอบกลับ
            'scheduleOptions' => match (true) {
                $schedule === null => $this->openSchedules(),
                $closed => $this->siblingRounds($schedule),
                default => collect(),
            },
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $link = $this->resolveLink($token);
        $bound = $link->schedule;

        // เลือกรอบเองได้เมื่อลิงก์ไม่ได้ผูกรอบ หรือรอบที่ผูกไว้ปิดรับไปแล้ว
        $mayChoose = $bound === null || ! $bound->acceptsNewCustomers();
        $schedule = $mayChoose
            ? ($this->pickSchedule($request->input('schedule_id')) ?: $bound)
            : $bound;

        $data = $this->validatePerson($request, $schedule, [
            'party_size' => ['nullable', 'integer', 'min:1', 'max:'.CustomerIntakeService::MAX_PEOPLE],
            'note' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', Rule::in(['line', 'facebook', 'instagram', 'other'])],
            'schedule_id' => [$mayChoose ? 'nullable' : 'prohibited', 'integer'],
            'consent' => ['accepted'],
        ]);

        if ($error = $this->passportWindowError($data, $schedule)) {
            return back()->withInput()->withErrors(['passport_expires_at' => $error]);
        }

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
            'closed' => $intake->schedule !== null && ! $intake->schedule->acceptsNewCustomers(),
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

        $data = $this->validatePerson($request, $intake->schedule, [
            'consent' => ['accepted'],
        ]);

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
    private function validatePerson(Request $request, ?TripSchedule $schedule, array $extra = []): array
    {
        // ทริปต่างประเทศต้องได้เอกสารเดินทางตั้งแต่ตอนนี้ ไม่งั้นแอดมินก็ต้อง
        // กลับไปไล่ถามในแชทอยู่ดี ซึ่งคือปัญหาเดิมที่หน้านี้ตั้งใจแก้
        $isInternational = (bool) $schedule?->trip?->isInternational();
        $passportRule = $isInternational ? 'required' : 'nullable';

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            // อีเมลบังคับกรอก — ใบเสร็จ กำหนดการ และอีเมลยืนยันการจองส่งทางนี้ทางเดียว
            'email' => ['required', 'email', 'max:120'],
            'id_card' => ['nullable', 'string', 'max:20', new ThaiIdCard],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O', ''])],
            'name_en' => [$passportRule, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s.\'-]+$/'],
            'passport_no' => [$passportRule, 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'passport_expires_at' => [$passportRule, 'nullable', 'date', 'after:today'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'health_notes' => ['nullable', 'string', 'max:500'],
            'halal_food' => ['nullable', 'boolean'],
            ...$extra,
        ], [
            'name.required' => 'กรุณากรอกชื่อ-นามสกุล',
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

    /** รอบที่ยังรับคนได้จริง — ใช้กับลิงก์กลางที่ไม่ผูกรอบ */
    private function openSchedules()
    {
        return TripSchedule::with('trip')
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->orderBy('departure_date')
            ->limit(60)
            ->get()
            // รอบที่เต็มแล้วไม่ควรอยู่ในลิสต์ให้เลือก — เลือกไปก็ได้คำตอบเดียวกัน
            ->filter(fn (TripSchedule $schedule) => $schedule->trip !== null && $schedule->acceptsNewCustomers())
            ->values();
    }

    /** รอบอื่นของทริปเดียวกัน — ทางออกให้ลูกค้าที่เปิดลิงก์ของรอบที่เต็มไปแล้ว */
    private function siblingRounds(TripSchedule $schedule)
    {
        return TripSchedule::with('trip')
            ->where('trip_id', $schedule->trip_id)
            ->whereKeyNot($schedule->id)
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->orderBy('departure_date')
            ->limit(12)
            ->get()
            ->filter(fn (TripSchedule $other) => $other->trip !== null && $other->acceptsNewCustomers())
            ->values();
    }

    private function pickSchedule(mixed $scheduleId): ?TripSchedule
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

        return $schedule?->acceptsNewCustomers() ? $schedule : null;
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

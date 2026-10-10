<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Services\BookingMemberService;
use App\Services\PickupStatusService;
use App\Services\QrCodeService;
use App\Services\TripAttendanceService;
use App\Services\TripBriefService;
use App\Support\AppLinks;
use App\Support\ThaiDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * "ลิงก์ของเพื่อน" /f/{token} — ลิงก์เดียวที่คนจองส่งให้เพื่อนแต่ละคน
 *
 * ก่อนหน้านี้เพื่อนในใบจองได้ลิงก์สองอันที่ไม่รู้ว่าต่างกันยังไง (กรอกข้อมูล /p/
 * กับเข้าแอป /join/) และไม่มีอะไรเป็นของตัวเองเลยในเช้าวันเดินทาง — QR อยู่ในมือ
 * คนจองคนเดียว หน้านี้รวมทุกอย่างของเพื่อนคนหนึ่งไว้ที่เดียว:
 *
 *   - บัตรขึ้นรถ (QR รายคน) ตั้งแต่วันก่อนเดินทาง — ไม่ต้องมีแอปก็ขึ้นรถได้เอง
 *   - กรอกข้อมูลของตัวเอง (ครั้งเดียว ตามกติกาเดิมของ /p/)
 *   - เข้าห้องแชทของทริปในแอป (คำเชิญที่ผูกกับชื่อตัวเอง)
 *   - แจ้งว่าไปไม่ได้
 *
 * เรื่องความเป็นส่วนตัว: ลิงก์นี้ถูกส่งต่อในกลุ่มแชทได้ จึงไม่แสดงข้อมูลส่วนตัว
 * ใด ๆ ของเจ้าของลิงก์ (เลขบัตร เบอร์ โรคประจำตัว) และไม่มีชื่อคนอื่นในใบจอง
 * ส่วนหน้ากรอกข้อมูลเปิดได้แค่ก่อนเจ้าตัวกรอกครั้งแรกเท่านั้น
 */
class PublicFriendPassController extends Controller
{
    /** อายุลิงก์กรอกข้อมูลที่ออกจากหน้านี้ — เท่ากับที่คนจองออกจากแอป */
    private const FILL_TTL_DAYS = 14;

    public function __construct(
        private QrCodeService $qrCodes,
        private PickupStatusService $pickupStatus,
        private TripAttendanceService $attendance,
        private BookingMemberService $members,
        private TripBriefService $briefs,
    ) {}

    public function show(string $token): Response
    {
        $passenger = $this->resolve($token);

        if (! $passenger) {
            return response()->view('friend-pass', ['pass' => null], 404);
        }

        return response()->view('friend-pass', ['pass' => $this->present($passenger)]);
    }

    /** ไปหน้ากรอกข้อมูล — ใช้ลิงก์เดิมที่ยังไม่หมดอายุ ไม่มีก็ออกให้ */
    public function fill(string $token): RedirectResponse
    {
        $passenger = $this->resolve($token);
        abort_if(! $passenger, 404);

        if ($passenger->self_filled_at !== null) {
            return redirect()
                ->route('public.friend-pass.show', $token)
                ->with('notice', 'คุณกรอกข้อมูลไปแล้ว ถ้าต้องแก้ไข บอกคนจองหรือทักทีมงานได้เลย');
        }

        $valid = filled($passenger->self_fill_token)
            && ($passenger->self_fill_expires_at === null || $passenger->self_fill_expires_at->isFuture());

        if (! $valid) {
            $passenger->forceFill([
                'self_fill_token' => Str::random(40),
                'self_fill_expires_at' => now()->addDays(self::FILL_TTL_DAYS),
            ])->save();
        }

        return redirect()->route('public.passenger-fill.show', $passenger->self_fill_token);
    }

    /** เจ้าตัวบอกว่าไป/ไม่ไป */
    public function attendance(Request $request, string $token): RedirectResponse
    {
        $passenger = $this->resolve($token);
        abort_if(! $passenger, 404);

        $validated = $request->validate(['not_going' => ['required', 'boolean']]);
        $back = redirect()->route('public.friend-pass.show', $token);

        try {
            $this->attendance->setNotGoing($passenger->booking, $passenger, (bool) $validated['not_going']);
        } catch (\Exception $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('notice', $validated['not_going']
            ? 'แจ้งทีมงานแล้วว่าคุณไม่ไป ถ้าเปลี่ยนใจ กดปุ่มด้านล่างได้จนถึงเวลารถออก'
            : 'บันทึกแล้ว เจอกันวันเดินทาง');
    }

    /**
     * ลิงก์ที่หาไม่เจอ/ใบจองที่ไม่ได้ไปแล้ว ตอบเหมือนกันหมด — ไม่บอกว่าโทเคนไหนเคยมีอยู่
     */
    private function resolve(string $token): ?BookingPassenger
    {
        $passenger = BookingPassenger::with([
            'pickupPoint',
            'booking.schedule.trip',
            'booking.pickupPoint',
        ])->where('pass_token', $token)->first();

        $booking = $passenger?->booking;

        if (! $booking || ! $this->briefs->isViewable($booking)) {
            return null;
        }

        return $passenger;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BookingPassenger $passenger): array
    {
        /** @var Booking $booking */
        $booking = $passenger->booking;
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        $point = $this->pointOnSchedule($passenger->pickupPoint, $booking)
            ?? $this->pointOnSchedule($booking->pickupPoint, $booking);

        $confirmed = $booking->status === 'confirmed';
        $checkedIn = $passenger->isCheckedIn();
        $notGoing = $passenger->isNotGoing();
        $inWindow = $schedule && $this->pickupStatus->isWithinWindow($schedule);

        // คำเชิญเข้าแอปที่ผูกกับชื่อนี้ — มีคนรับไปแล้ว (null) แปลว่าเจ้าตัวอยู่ในแอปแล้ว
        $invite = $this->members->pendingInviteForPassenger($booking, $passenger);

        $departs = $schedule?->departs_at;

        return [
            'token' => $passenger->pass_token,
            'name' => $passenger->displayName(),
            'trip_title' => $trip?->title ?? 'ทริปของคุณ',
            'trip_location' => $trip?->location,
            'cover_image' => $trip?->cover_image,
            'departure_label' => $schedule
                ? ThaiDate::full($schedule->departure_date)
                    .($schedule->return_date && ! $schedule->return_date->isSameDay($schedule->departure_date)
                        ? ' – '.ThaiDate::full($schedule->return_date)
                        : '')
                : null,
            // departs_at เก็บเวลาไทยในคอลัมน์ UTC — พิมพ์ตัวเลขตรง ๆ ห้ามแปลงโซน
            'departs_label' => $departs
                ? 'รถออก '.ThaiDate::full($departs).' เวลา '.$departs->format('H:i').' น.'
                : null,
            'pickup_label' => $point ? (trim((string) ($point->pickup_location ?: $point->region_label)) ?: null) : null,
            'pickup_time' => $point?->pickup_time,
            'pickup_map_url' => $point?->map_url,
            'booking_status' => $booking->status,
            'confirmed' => $confirmed,
            'checked_in' => $checkedIn,
            'checked_in_label' => $checkedIn
                ? $passenger->checked_in_at->copy()->timezone('Asia/Bangkok')->format('H:i')
                : null,
            'not_going' => $notGoing,
            'show_qr' => $confirmed && $inWindow && ! $checkedIn && filled($passenger->qr_code),
            'qr_pending' => $confirmed && ! $inWindow && ! $checkedIn,
            'code' => $passenger->qr_code,
            'qr' => $confirmed && $inWindow && ! $checkedIn && filled($passenger->qr_code)
                ? $this->qrCodes->svgDataUri($passenger->qr_code, 240)
                : null,
            'needs_fill' => $passenger->self_filled_at === null,
            'join_url' => $invite ? url('/join/'.$invite->invite_token) : null,
            'in_app_already' => $invite === null,
            'attendance_open' => $this->attendance->isOpen($booking) && ! $checkedIn,
            'ios_url' => AppLinks::ios(),
            'android_url' => AppLinks::android(),
        ];
    }

    /** จุดรับต้องเป็นของรอบนี้ — FK ที่ค้างจากตอนย้ายรอบจะพาเวลารับของรอบเดิมมาแสดง */
    private function pointOnSchedule($point, Booking $booking)
    {
        return $point && (int) $point->schedule_id === (int) $booking->schedule_id ? $point : null;
    }
}

<?php

namespace App\Services;

use App\Mail\CharterQuoteMail;
use App\Models\Booking;
use App\Models\CharterRequest;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ThaiDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * คำขอเหมาทริป — ทุกการเปลี่ยนสถานะผ่านที่นี่ ทุกเมธอดตรวจสถานะใต้ lock
 * กดซ้ำหรือสองฝั่งกดพร้อมกันจึงไม่พาคำขอไปผิดทาง
 *
 * ราคาที่เสนอเป็น "ต่อคน" แล้วคูณจำนวนคนเป็นยอดรวมที่หลังบ้าน — รอบเหมาที่สร้างจาก
 * คำขอตั้งราคารอบ (price_override) เท่าราคาต่อคนนี้ การจองที่ทีมงานลงให้กลุ่มจึงได้
 * ยอดตรงกับใบเสนอราคาโดยไม่ต้องคิดใหม่
 */
class CharterRequestService
{
    // ── ลูกค้า ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  ผ่าน validation ของ controller แล้ว
     */
    public function create(User $user, array $data): CharterRequest
    {
        $request = CharterRequest::create([
            'ref' => CharterRequest::generateRef(),
            'user_id' => $user->id,
            'trip_id' => $data['trip_id'] ?? null,
            'destination' => self::clean($data['destination'] ?? null),
            'preferred_date' => $data['preferred_date'],
            'alternate_date' => $data['alternate_date'] ?? null,
            'flexible_dates' => (bool) ($data['flexible_dates'] ?? false),
            'duration_days' => $data['duration_days'] ?? null,
            'group_size' => (int) $data['group_size'],
            'pickup_area' => self::clean($data['pickup_area'] ?? null),
            'budget_per_person' => $data['budget_per_person'] ?? null,
            'group_type' => $data['group_type'] ?? 'friends',
            'needs_tax_invoice' => (bool) ($data['needs_tax_invoice'] ?? false),
            'contact_name' => trim((string) $data['contact_name']),
            'contact_phone' => trim((string) $data['contact_phone']),
            'contact_line' => self::clean($data['contact_line'] ?? null),
            'note' => self::clean($data['note'] ?? null),
            'status' => CharterRequest::STATUS_NEW,
            // ทริปที่ลูกค้าเลือกคือจุดเริ่มของใบเสนอราคา ทีมงานเปลี่ยนได้
            'quote_trip_id' => $data['trip_id'] ?? null,
        ]);

        $this->notifyTeam(
            $request,
            'charter_request_new',
            'คำขอเหมาทริปใหม่',
            "{$request->contact_name} · {$request->group_size} คน · ".$request->destinationLabel()
                .' · '.ThaiDate::short($request->preferred_date),
        );

        return $request->fresh(['trip']);
    }

    public function accept(CharterRequest $request): CharterRequest
    {
        $accepted = DB::transaction(function () use ($request) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== CharterRequest::STATUS_QUOTED) {
                throw new \Exception('คำขอนี้ไม่ได้อยู่ระหว่างรอตอบรับใบเสนอราคา');
            }
            if ($locked->quoteExpired()) {
                throw new \Exception('ใบเสนอราคานี้หมดอายุแล้ว ขอให้ทีมงานเสนอราคาใหม่ได้ที่แชทหรือกดขอใบเสนอราคาใหม่');
            }

            $locked->update([
                'status' => CharterRequest::STATUS_ACCEPTED,
                'responded_at' => now(),
                'decline_reason' => null,
            ]);

            return $locked;
        });

        $this->notifyTeam(
            $accepted,
            'charter_request_accepted',
            'ลูกค้าตอบรับใบเสนอราคาเหมาทริป ✅',
            "{$accepted->ref} · {$accepted->contact_name} — เปิดรอบเหมาและลงการจองให้กลุ่มได้เลย",
        );

        return $accepted->fresh();
    }

    public function decline(CharterRequest $request, ?string $reason): CharterRequest
    {
        $declined = DB::transaction(function () use ($request, $reason) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== CharterRequest::STATUS_QUOTED) {
                throw new \Exception('คำขอนี้ไม่ได้อยู่ระหว่างรอตอบรับใบเสนอราคา');
            }

            $locked->update([
                'status' => CharterRequest::STATUS_DECLINED,
                'responded_at' => now(),
                'decline_reason' => self::clean($reason),
            ]);

            return $locked;
        });

        $this->notifyTeam(
            $declined,
            'charter_request_declined',
            'ลูกค้าปฏิเสธใบเสนอราคาเหมาทริป',
            "{$declined->ref} · {$declined->contact_name}".($declined->decline_reason ? " — {$declined->decline_reason}" : ''),
        );

        return $declined->fresh();
    }

    /** ลูกค้ายกเลิกคำขอเอง — ได้จนกว่าจะตอบรับใบเสนอราคา */
    public function cancel(CharterRequest $request): CharterRequest
    {
        return DB::transaction(function () use ($request) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_DECLINED], true)) {
                throw new \Exception($locked->status === CharterRequest::STATUS_ACCEPTED
                    ? 'ตอบรับใบเสนอราคาแล้ว ถ้าต้องการยกเลิกกรุณาทักทีมงานครับ'
                    : 'คำขอนี้ปิดไปแล้ว');
            }

            $locked->update(['status' => CharterRequest::STATUS_CANCELLED]);

            return $locked->fresh();
        });
    }

    // ── ทีมงาน ──────────────────────────────────────────────────────

    /**
     * เสนอราคา (หรือเสนอใหม่หลังลูกค้าปฏิเสธ)
     *
     * @param  array{trip_id: int, departure_date: string, return_date: string, group_size: int, price_per_person: float|int|string, includes?: ?string, note?: ?string, valid_until?: ?string}  $quote
     */
    public function quote(CharterRequest $request, User $admin, array $quote): CharterRequest
    {
        $quoted = DB::transaction(function () use ($request, $admin, $quote) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_DECLINED], true)) {
                throw new \Exception('เสนอราคาได้เฉพาะคำขอที่ยังไม่ตอบรับหรือปิดไปแล้ว');
            }

            $perPerson = round((float) $quote['price_per_person'], 2);
            $groupSize = (int) $quote['group_size'];
            $validUntil = filled($quote['valid_until'] ?? null)
                ? Carbon::parse($quote['valid_until'])->toDateString()
                : now('Asia/Bangkok')->addDays(CharterRequest::DEFAULT_QUOTE_VALID_DAYS)->toDateString();

            $locked->update([
                'status' => CharterRequest::STATUS_QUOTED,
                'quote_trip_id' => (int) $quote['trip_id'],
                'quote_departure_date' => $quote['departure_date'],
                'quote_return_date' => $quote['return_date'],
                'quote_group_size' => $groupSize,
                'quote_price_per_person' => $perPerson,
                'quote_total' => round($perPerson * $groupSize, 2),
                'quote_includes' => self::clean($quote['includes'] ?? null),
                'quote_note' => self::clean($quote['note'] ?? null),
                'quote_valid_until' => $validUntil,
                'quoted_at' => now(),
                'quoted_by_id' => $admin->id,
                'responded_at' => null,
                'decline_reason' => null,
            ]);

            return $locked;
        });

        $quoted->load('quoteTrip');

        if ($quoted->user_id) {
            SmartNotification::send(
                $quoted->user_id,
                'charter_quoted',
                'ใบเสนอราคาเหมาทริปมาแล้ว 📝',
                ($quoted->quoteTrip?->title ?? 'ทริปส่วนตัว').' · '.$quoted->quote_group_size.' คน · รวม ฿'
                    .number_format((float) $quoted->quote_total).' — แตะเพื่อดูรายละเอียดและตอบรับ',
                ['charter_request_id' => $quoted->id, 'route' => 'charter_request'],
            );
        }

        $this->sendQuoteEmail($quoted);

        return $quoted->fresh(['trip', 'quoteTrip']);
    }

    public function reject(CharterRequest $request, User $admin, string $reason): CharterRequest
    {
        $rejected = DB::transaction(function () use ($request, $reason) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_DECLINED], true)) {
                throw new \Exception('ปิดคำขอได้เฉพาะคำขอที่ยังไม่ตอบรับใบเสนอราคา');
            }

            $locked->update([
                'status' => CharterRequest::STATUS_REJECTED,
                'reject_reason' => $reason,
            ]);

            return $locked;
        });

        if ($rejected->user_id) {
            SmartNotification::send(
                $rejected->user_id,
                'charter_rejected',
                'ทีมงานยังรับเหมาทริปนี้ไม่ได้',
                "คำขอ {$rejected->ref}: {$reason} — ขอโทษด้วยนะครับ ลองเลือกวันอื่นหรือทักทีมงานได้เลย",
                ['charter_request_id' => $rejected->id, 'route' => 'charter_request'],
            );
        }

        return $rejected->fresh();
    }

    /**
     * เปิดรอบเหมาจากใบเสนอราคาที่ลูกค้าตอบรับแล้ว — เรียกซ้ำได้ คืนรอบเดิม
     *
     * ไม่คัดลอกจุดขึ้นรถจากรอบอื่น: ราคาจุดรับคือ "ราคาต่อคนของโซนนั้น" ที่จะทับ
     * ราคาตามใบเสนอราคา กลุ่มเหมามักนัดจุดเดียวที่ตกลงกันเอง ทีมงานเพิ่มเองได้
     */
    public function createCharterSchedule(CharterRequest $request): TripSchedule
    {
        return DB::transaction(function () use ($request) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if ($locked->schedule_id && ($existing = TripSchedule::find($locked->schedule_id))) {
                return $existing;
            }

            if (! in_array($locked->status, [CharterRequest::STATUS_ACCEPTED, CharterRequest::STATUS_BOOKED], true)) {
                throw new \Exception('เปิดรอบเหมาได้หลังลูกค้าตอบรับใบเสนอราคาแล้วเท่านั้น');
            }

            $trip = Trip::find($locked->quote_trip_id);
            if (! $trip) {
                throw new \Exception('ใบเสนอราคานี้ไม่ได้ระบุทริป กรุณาเสนอราคาใหม่โดยเลือกทริป');
            }

            $transport = TripSchedule::where('trip_id', $trip->id)
                ->orderByDesc('departure_date')
                ->value('transport_type') ?? 'van';

            $schedule = TripSchedule::create([
                'trip_id' => $trip->id,
                'departure_date' => $locked->quote_departure_date->toDateString(),
                'return_date' => $locked->quote_return_date->toDateString(),
                'total_seats' => $locked->quote_group_size,
                'booked_seats' => 0,
                'transport_type' => $transport,
                'status' => 'open',
                'is_charter' => true,
                'price_override' => $locked->quote_price_per_person,
            ]);

            $locked->update(['schedule_id' => $schedule->id]);

            return $schedule;
        });
    }

    /**
     * ผูกการจองที่ลงให้กลุ่มแล้วกลับมาที่คำขอ — ลูกค้าเห็นปุ่มไปหน้าการจอง/ชำระเงิน
     *
     * การจองต้องเป็นของบัญชีที่ส่งคำขอ ไม่งั้นลูกค้าเปิดในแอปไม่เห็น (โอนการจองให้
     * ถูกบัญชีก่อนด้วยปุ่มโอนการจองของหน้าการจอง)
     */
    public function linkBooking(CharterRequest $request, Booking $booking): CharterRequest
    {
        $linked = DB::transaction(function () use ($request, $booking) {
            $locked = CharterRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [CharterRequest::STATUS_ACCEPTED, CharterRequest::STATUS_BOOKED], true)) {
                throw new \Exception('ผูกการจองได้หลังลูกค้าตอบรับใบเสนอราคาแล้วเท่านั้น');
            }
            if ($locked->user_id && $booking->user_id !== $locked->user_id) {
                throw new \Exception('การจอง '.$booking->booking_ref.' เป็นของบัญชีอื่น โอนการจองให้บัญชีของผู้ขอก่อน');
            }
            if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
                throw new \Exception('การจอง '.$booking->booking_ref.' ถูกยกเลิกไปแล้ว');
            }

            $wasBooked = $locked->booking_id === $booking->id;

            $locked->update([
                'status' => CharterRequest::STATUS_BOOKED,
                'booking_id' => $booking->id,
                'schedule_id' => $locked->schedule_id ?? $booking->schedule_id,
                'booked_at' => $locked->booked_at ?? now(),
            ]);

            return $wasBooked ? null : $locked;
        });

        if ($linked && $linked->user_id) {
            SmartNotification::send(
                $linked->user_id,
                'charter_booked',
                'การจองทริปเหมาของคุณพร้อมแล้ว 🎉',
                "เลขการจอง {$booking->booking_ref} — แตะเพื่อดูรายละเอียดและชำระเงิน",
                ['charter_request_id' => $linked->id, 'booking_ref' => $booking->booking_ref, 'route' => 'charter_request'],
            );
        }

        return $request->fresh(['booking', 'schedule']);
    }

    // ── ภายใน ──────────────────────────────────────────────────────

    private function notifyTeam(CharterRequest $request, string $type, string $title, string $body): void
    {
        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($request, $type, $title, $body) {
                SmartNotification::send(
                    $admin->id,
                    $type,
                    $title,
                    $body,
                    ['charter_request_id' => $request->id, 'route' => 'admin.charter_requests'],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('CharterRequestService notifyTeam failed — '.$e->getMessage());
        }
    }

    private function sendQuoteEmail(CharterRequest $request): void
    {
        $email = $request->user?->email;
        $normalised = strtolower(trim((string) $email));

        if ($normalised === ''
            || str_ends_with($normalised, '@social.local')
            || (str_starts_with($normalised, 'manual_') && str_ends_with($normalised, '@luilaykhao.com'))) {
            return;
        }

        try {
            Mail::to($email)->send(new CharterQuoteMail($request));
        } catch (\Throwable $e) {
            Log::error('Failed to send charter quote email', ['ref' => $request->ref, 'error' => $e->getMessage()]);
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

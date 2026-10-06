<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CharterRequest;
use App\Services\CharterRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หลังบ้านคำขอเหมาทริป — เสนอราคา ปิดคำขอ เปิดรอบเหมา ผูกการจอง
 */
class AdminCharterRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private CharterRequestService $charters) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([
                CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_ACCEPTED,
                CharterRequest::STATUS_DECLINED, CharterRequest::STATUS_REJECTED, CharterRequest::STATUS_CANCELLED,
                CharterRequest::STATUS_BOOKED,
            ])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CharterRequest::with(['trip:id,title', 'quoteTrip:id,title', 'user:id,name,email,phone', 'booking:id,booking_ref,status'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('ref', 'like', '%'.$term.'%')
                ->orWhere('contact_name', 'like', '%'.$term.'%')
                ->orWhere('contact_phone', 'like', '%'.$term.'%')
                ->orWhere('destination', 'like', '%'.$term.'%')))
            // งานที่ต้องทำก่อน: คำขอใหม่ และใบที่ลูกค้าตอบรับแล้วรอเปิดการจอง
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($data['per_page'] ?? 30);

        $counts = CharterRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return $this->paginated(
            $page->through(fn (CharterRequest $c) => $this->row($c)),
            meta: ['counts' => $counts],
        );
    }

    public function show(int $charter): JsonResponse
    {
        $model = CharterRequest::with(['trip', 'quoteTrip', 'user', 'quotedBy:id,name', 'schedule', 'booking'])->findOrFail($charter);

        return $this->success($this->row($model, detail: true));
    }

    public function quote(Request $request, int $charter): JsonResponse
    {
        $data = $request->validate([
            'trip_id' => ['required', 'integer', Rule::exists('trips', 'id')],
            'departure_date' => ['required', 'date', 'after_or_equal:today'],
            'return_date' => ['required', 'date', 'after_or_equal:departure_date'],
            'group_size' => ['required', 'integer', 'min:1', 'max:'.CharterRequest::MAX_GROUP_SIZE],
            'price_per_person' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'includes' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        try {
            $model = $this->charters->quote(CharterRequest::findOrFail($charter), $request->user(), $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['trip', 'quoteTrip', 'user', 'booking']), detail: true), 'ส่งใบเสนอราคาให้ลูกค้าแล้ว');
    }

    public function reject(Request $request, int $charter): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']], [
            'reason.required' => 'กรุณาบอกเหตุผล ลูกค้าจะเห็นข้อความนี้',
        ]);

        try {
            $model = $this->charters->reject(CharterRequest::findOrFail($charter), $request->user(), $data['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['trip', 'quoteTrip', 'user', 'booking'])), 'ปิดคำขอและแจ้งลูกค้าแล้ว');
    }

    public function note(Request $request, int $charter): JsonResponse
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:2000']]);
        $model = CharterRequest::findOrFail($charter);
        $model->update(['admin_note' => $data['admin_note'] ?? null]);

        return $this->success($this->row($model->fresh(['trip', 'quoteTrip', 'user', 'booking'])), 'บันทึกแล้ว');
    }

    public function createSchedule(int $charter): JsonResponse
    {
        try {
            $schedule = $this->charters->createCharterSchedule(CharterRequest::findOrFail($charter));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'schedule_id' => $schedule->id,
            'trip_id' => $schedule->trip_id,
            'departure_date' => $schedule->departure_date?->toDateString(),
        ], 'เปิดรอบเหมาแล้ว');
    }

    public function linkBooking(Request $request, int $charter): JsonResponse
    {
        $data = $request->validate(['booking_ref' => ['required', 'string', 'max:40']]);
        $booking = Booking::where('booking_ref', trim($data['booking_ref']))->first();

        if (! $booking) {
            return $this->error('ไม่พบการจอง '.$data['booking_ref'], 422);
        }

        try {
            $model = $this->charters->linkBooking(CharterRequest::findOrFail($charter), $booking);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['trip', 'quoteTrip', 'user', 'booking'])), 'ผูกการจองแล้ว แจ้งลูกค้าให้ชำระเงินแล้ว');
    }

    private function row(CharterRequest $c, bool $detail = false): array
    {
        $row = [
            'id' => $c->id,
            'ref' => $c->ref,
            'status' => $c->status,
            'destination_label' => $c->destinationLabel(),
            'trip_id' => $c->trip_id,
            'trip_title' => $c->trip?->title,
            'preferred_date' => $c->preferred_date?->toDateString(),
            'alternate_date' => $c->alternate_date?->toDateString(),
            'flexible_dates' => (bool) $c->flexible_dates,
            'group_size' => $c->group_size,
            'group_type' => $c->group_type,
            'group_type_label' => CharterRequest::GROUP_TYPE_LABELS[$c->group_type] ?? $c->group_type,
            'contact_name' => $c->contact_name,
            'contact_phone' => $c->contact_phone,
            'contact_line' => $c->contact_line,
            'quote_total' => $c->quote_total !== null ? (float) $c->quote_total : null,
            'quote_expired' => $c->quoteExpired(),
            'booking_ref' => $c->booking?->booking_ref,
            'booking_status' => $c->booking?->status,
            'schedule_id' => $c->schedule_id,
            'created_at' => $c->created_at?->toISOString(),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email, 'phone' => $c->user->phone] : null,
            'destination' => $c->destination,
            'duration_days' => $c->duration_days,
            'pickup_area' => $c->pickup_area,
            'budget_per_person' => $c->budget_per_person !== null ? (float) $c->budget_per_person : null,
            'needs_tax_invoice' => (bool) $c->needs_tax_invoice,
            'note' => $c->note,
            'admin_note' => $c->admin_note,
            'quote' => [
                'trip_id' => $c->quote_trip_id,
                'trip_title' => $c->quoteTrip?->title,
                'departure_date' => $c->quote_departure_date?->toDateString(),
                'return_date' => $c->quote_return_date?->toDateString(),
                'group_size' => $c->quote_group_size,
                'price_per_person' => $c->quote_price_per_person !== null ? (float) $c->quote_price_per_person : null,
                'total' => $c->quote_total !== null ? (float) $c->quote_total : null,
                'includes' => $c->quote_includes,
                'note' => $c->quote_note,
                'valid_until' => $c->quote_valid_until?->toDateString(),
                'quoted_at' => $c->quoted_at?->toISOString(),
                'quoted_by' => $c->quotedBy?->name,
            ],
            'responded_at' => $c->responded_at?->toISOString(),
            'decline_reason' => $c->decline_reason,
            'reject_reason' => $c->reject_reason,
            'schedule' => $c->schedule ? [
                'id' => $c->schedule->id,
                'trip_id' => $c->schedule->trip_id,
                'departure_date' => $c->schedule->departure_date?->toDateString(),
                'total_seats' => $c->schedule->total_seats,
            ] : null,
            'booked_at' => $c->booked_at?->toISOString(),
        ];
    }
}

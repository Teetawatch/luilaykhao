<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้ากรอกวัน/เดือน/ปีเกิดแบบสาธารณะ เข้าผ่านลิงก์เฉพาะคน (birthdate_token)
 * สำหรับเก็บข้อมูลวันเกิดย้อนหลังจากลูกค้าเก่าที่สมัครไว้ก่อนระบบมีฟิลด์นี้
 * ลูกค้าไม่ต้องล็อกอิน — เปิดลิงก์ของตัวเองแล้วกรอกวันเกิดได้เลย
 */
class PublicBirthdateController extends Controller
{
    public function show(string $token): View
    {
        return view('birthdate.form', ['user' => $this->resolveUser($token)]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $user = $this->resolveUser($token);

        $validated = $request->validate([
            'birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
        ], [
            'birth_date.required' => 'กรุณาเลือกวัน/เดือน/ปีเกิด',
            'birth_date.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
            'birth_date.after' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
        ]);

        $user->update(['birth_date' => $validated['birth_date']]);
        $this->propagateToUpcomingTrips($user, $validated['birth_date']);

        return redirect()
            ->route('public.birthdate.show', $token)
            ->with('saved', true);
    }

    /**
     * Best-effort fill of the customer's own passenger rows on upcoming, still-active
     * bookings they made — only where we can confidently identify the row as them
     * (matching ID card, else exact name) so we never overwrite a fellow traveller.
     */
    private function propagateToUpcomingTrips(User $user, string $birthDate): void
    {
        $passengers = BookingPassenger::query()
            ->whereNull('birth_date')
            ->whereHas('booking', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                    ->whereHas('schedule', fn ($s) => $s->whereDate('departure_date', '>=', now()->toDateString()));
            })
            ->get();

        foreach ($passengers as $passenger) {
            $matchesIdCard = $user->id_card && $passenger->id_card === $user->id_card;
            $matchesName = ! $user->id_card && $passenger->name === $user->name;

            if ($matchesIdCard || $matchesName) {
                $passenger->update(['birth_date' => $birthDate]);
            }
        }
    }

    private function resolveUser(string $token): User
    {
        return User::where('birthdate_token', trim($token))->firstOrFail();
    }

    /* ───── Per-booking link — covers everyone in the booking (e.g. booked for 9) ───── */

    public function showBooking(string $token): View
    {
        return view('birthdate.booking', ['booking' => $this->resolveBooking($token)]);
    }

    public function submitBooking(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolveBooking($token);

        $validated = $request->validate([
            'birth_dates' => ['array'],
            'birth_dates.*' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        ], [
            'birth_dates.*.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
            'birth_dates.*.after' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
        ]);

        $dates = $validated['birth_dates'] ?? [];
        // Restrict writes to passengers that actually belong to this booking.
        $passengers = $booking->passengers->keyBy('id');

        foreach ($dates as $passengerId => $date) {
            if (! $date) {
                continue;
            }
            $passenger = $passengers->get((int) $passengerId);
            if ($passenger) {
                $passenger->update(['birth_date' => $date]);
            }
        }

        $this->syncBookerProfile($booking->fresh('passengers'));

        return redirect()
            ->route('public.birthdate.booking.show', $token)
            ->with('saved', true);
    }

    /**
     * Copy the booker's own birth date (their row in the booking, matched by ID card
     * else exact name) up to their user profile so future bookings prefill.
     */
    private function syncBookerProfile(Booking $booking): void
    {
        $user = $booking->user;
        if (! $user) {
            return;
        }

        $own = $booking->passengers->first(function ($passenger) use ($user) {
            return $user->id_card
                ? $passenger->id_card === $user->id_card
                : $passenger->name === $user->name;
        });

        if ($own?->birth_date) {
            $user->update(['birth_date' => $own->birth_date->format('Y-m-d')]);
        }
    }

    private function resolveBooking(string $token): Booking
    {
        return Booking::where('birthdate_token', trim($token))
            ->with(['passengers', 'schedule.trip', 'user'])
            ->firstOrFail();
    }
}

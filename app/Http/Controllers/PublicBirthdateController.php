<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
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

        $request->validate([
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
        ], [
            'birth_day.required' => 'กรุณาเลือกวัน/เดือน/ปีเกิด',
            'birth_month.required' => 'กรุณาเลือกวัน/เดือน/ปีเกิด',
            'birth_year.required' => 'กรุณาเลือกวัน/เดือน/ปีเกิด',
        ]);

        $date = $this->composeBirthDate($request->birth_year, $request->birth_month, $request->birth_day);
        if (! $date) {
            return back()->withInput()->withErrors(['birth_day' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง']);
        }

        $user->update(['birth_date' => $date->toDateString()]);
        $this->propagateToUpcomingTrips($user, $date->toDateString());

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

    /**
     * Build a valid birth date from the day/month/year dropdowns (year submitted
     * as A.D. — the พ.ศ. shown to the user is display-only). Returns null when the
     * combination is incomplete, not a real calendar date, or not in the past.
     */
    private function composeBirthDate($year, $month, $day): ?CarbonImmutable
    {
        if (! $year || ! $month || ! $day) {
            return null;
        }

        $y = (int) $year;
        $m = (int) $month;
        $d = (int) $day;

        if ($y < 1900 || ! checkdate($m, $d, $y)) {
            return null;
        }

        $date = CarbonImmutable::create($y, $m, $d)->startOfDay();

        return $date->lessThan(CarbonImmutable::now()->startOfDay()) ? $date : null;
    }

    /* ───── Per-booking link — covers everyone in the booking (e.g. booked for 9) ───── */

    public function showBooking(string $token): View
    {
        return view('birthdate.booking', ['booking' => $this->resolveBooking($token)]);
    }

    public function submitBooking(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolveBooking($token);

        $days = $request->input('birth_days', []);
        $months = $request->input('birth_months', []);
        $years = $request->input('birth_years', []);

        // Restrict writes to passengers that actually belong to this booking.
        $passengers = $booking->passengers->keyBy('id');

        foreach ($passengers as $passengerId => $passenger) {
            $day = $days[$passengerId] ?? null;
            $month = $months[$passengerId] ?? null;
            $year = $years[$passengerId] ?? null;

            // Leave untouched when this passenger's row was left blank.
            if (! $day && ! $month && ! $year) {
                continue;
            }

            $date = $this->composeBirthDate($year, $month, $day);
            if (! $date) {
                return back()->withInput()->withErrors([
                    'birth_dates' => 'วัน/เดือน/ปีเกิดของผู้เดินทางบางท่านไม่ถูกต้อง กรุณาตรวจสอบ',
                ]);
            }

            $passenger->update(['birth_date' => $date->toDateString()]);
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

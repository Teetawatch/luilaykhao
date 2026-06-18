@extends('birthdate.layout')

@section('title', 'กรอกวันเกิดผู้เดินทาง')

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>วัน/เดือน/ปีเกิดผู้เดินทาง</h1>
            @if ($booking->schedule?->trip)
                <div style="margin-top:8px;font-size:13.5px;opacity:.95;">
                    {{ $booking->schedule->trip->title }}
                    @if ($booking->schedule->departure_date)
                        · {{ $booking->schedule->departure_date->format('d/m/') }}{{ $booking->schedule->departure_date->year + 543 }}
                    @endif
                </div>
            @endif
        </div>
        <div class="card-body">
            @if (session('saved'))
                <div class="alert alert-success">
                    บันทึกวันเกิดเรียบร้อยแล้ว ขอบคุณมากค่ะ 🙏
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="lead">
                กรุณากรอกวัน/เดือน/ปีเกิดของผู้เดินทางทุกท่านในการจองนี้
                (รหัสจอง {{ $booking->booking_ref }}) — กรอกได้เลยทั้งของตัวเองและเพื่อนร่วมทริป
            </p>

            <form method="POST" action="{{ route('public.birthdate.booking.submit', request()->route('token')) }}">
                @csrf
                @foreach ($booking->passengers as $i => $passenger)
                    <label class="field">
                        {{ $i + 1 }}. {{ $passenger->title }} {{ $passenger->name }}
                    </label>
                    @include('birthdate.dob-fields', [
                        'dayName' => "birth_days[$passenger->id]",
                        'monthName' => "birth_months[$passenger->id]",
                        'yearName' => "birth_years[$passenger->id]",
                        'selDay' => old("birth_days.$passenger->id", $passenger->birth_date?->day),
                        'selMonth' => old("birth_months.$passenger->id", $passenger->birth_date?->month),
                        'selYear' => old("birth_years.$passenger->id", $passenger->birth_date?->year),
                    ])
                @endforeach
                <button type="submit" class="btn">บันทึกวันเกิด</button>
            </form>

            <p class="note">ลิงก์นี้สำหรับการจองของคุณเท่านั้น กรุณาอย่าส่งต่อให้ผู้อื่น</p>
        </div>
    </div>
@endsection

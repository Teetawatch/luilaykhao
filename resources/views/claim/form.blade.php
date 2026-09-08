@extends('public.form-layout')

@section('title', 'เปิดใช้บัญชีเพื่อดูการจอง')

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>เปิดใช้บัญชีของคุณ</h1>
        </div>
        <div class="card-body">
            @if ($errors->any())
                <div class="alert alert-error">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="hello">สวัสดีคุณ <strong>{{ $shadow->name ?: 'ลูกค้า' }}</strong> ครับ</p>
            <p class="lead">
                ทีมงานเปิดการจองให้คุณไว้เรียบร้อยแล้ว
                ตั้งรหัสผ่านเพียงครั้งเดียว แล้วคุณจะเห็นการจองทั้งหมดในแอปลุยเลเขาได้ทันที
            </p>

            @if ($bookings->isNotEmpty())
                <div class="pax">
                    <div class="pax-name">การจองที่รออยู่ในบัญชีนี้</div>
                    @foreach ($bookings as $booking)
                        <p class="hello" style="margin-bottom:10px">
                            <strong>{{ $booking->schedule?->trip?->title ?: 'ทริปของคุณ' }}</strong><br>
                            <span style="font-size:13px;color:#64748b">
                                {{ $booking->booking_ref }}
                                @if ($booking->schedule?->departure_date)
                                    · {{ $thaiDate($booking->schedule->departure_date) }}
                                @endif
                            </span>
                        </p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('public.claim.submit', $token) }}">
                @csrf
                <label class="field" for="claim-email">อีเมลของคุณ</label>
                <input id="claim-email" type="email" name="email" value="{{ old('email') }}"
                       inputmode="email" autocomplete="email" placeholder="you@example.com" required>

                <label class="field" for="claim-password">ตั้งรหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
                <input id="claim-password" type="password" name="password"
                       autocomplete="new-password" minlength="8" required>
                <p class="hint">ถ้าคุณเคยสมัครในแอปด้วยอีเมลนี้แล้ว ให้กรอกรหัสผ่านเดิม เราจะย้ายการจองไปรวมให้เอง</p>

                <button type="submit" class="btn">เปิดใช้บัญชี</button>
            </form>

            <p class="note">ลิงก์นี้สำหรับคุณเท่านั้น กรุณาอย่าส่งต่อให้ผู้อื่น</p>
        </div>
    </div>
@endsection

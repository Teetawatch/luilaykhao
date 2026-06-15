@extends('birthdate.layout')

@section('title', 'กรอกวัน/เดือน/ปีเกิด')

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>ยืนยันวัน/เดือน/ปีเกิด</h1>
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

            <p class="hello">สวัสดีคุณ <strong>{{ $user->name ?: 'ลูกค้า' }}</strong></p>
            <p class="lead">
                กรุณาเลือกวัน/เดือน/ปีเกิดของคุณ เพื่อใช้สำหรับการเดินทาง
                (บางสถานที่ท่องเที่ยว/ประกันต้องใช้ข้อมูลนี้)
            </p>

            @if ($user->birth_date)
                <div class="current">
                    วันเกิดปัจจุบันในระบบ: {{ $user->birth_date->format('d/m/Y') }}
                    @if ($user->age !== null) · อายุ {{ $user->age }} ปี @endif
                    <br>เลือกใหม่ด้านล่างได้หากต้องการแก้ไข
                </div>
            @endif

            <form method="POST" action="{{ route('public.birthdate.submit', request()->route('token')) }}">
                @csrf
                <label class="field" for="birth_date">วัน/เดือน/ปีเกิด</label>
                <input
                    type="date"
                    id="birth_date"
                    name="birth_date"
                    max="{{ now()->toDateString() }}"
                    min="1900-01-01"
                    value="{{ old('birth_date', $user->birth_date?->format('Y-m-d')) }}"
                    required
                >
                <button type="submit" class="btn">บันทึกวันเกิด</button>
            </form>

            <p class="note">ลิงก์นี้สำหรับคุณเท่านั้น กรุณาอย่าส่งต่อให้ผู้อื่น</p>
        </div>
    </div>
@endsection

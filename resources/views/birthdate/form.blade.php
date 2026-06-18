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
                    วันเกิดปัจจุบันในระบบ: {{ $user->birth_date->format('d/m/') }}{{ $user->birth_date->year + 543 }}
                    @if ($user->age !== null) · อายุ {{ $user->age }} ปี @endif
                    <br>เลือกใหม่ด้านล่างได้หากต้องการแก้ไข
                </div>
            @endif

            <form method="POST" action="{{ route('public.birthdate.submit', request()->route('token')) }}">
                @csrf
                <label class="field">วัน/เดือน/ปีเกิด</label>
                @include('birthdate.dob-fields', [
                    'dayName' => 'birth_day',
                    'monthName' => 'birth_month',
                    'yearName' => 'birth_year',
                    'selDay' => old('birth_day', $user->birth_date?->day),
                    'selMonth' => old('birth_month', $user->birth_date?->month),
                    'selYear' => old('birth_year', $user->birth_date?->year),
                    'required' => true,
                ])
                <button type="submit" class="btn">บันทึกวันเกิด</button>
            </form>

            <p class="note">ลิงก์นี้สำหรับคุณเท่านั้น กรุณาอย่าส่งต่อให้ผู้อื่น</p>
        </div>
    </div>
@endsection

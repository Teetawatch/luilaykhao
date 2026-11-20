@extends('public.form-layout')

@section('title', __('เปิดใช้บัญชีเรียบร้อย'))

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>{{ __('เรียบร้อยแล้ว 🎉') }}</h1>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                @if ($result['merged'])
                    {{ __('ย้ายการจอง :result รายการไปที่บัญชีของคุณแล้ว', ['result' => $result['count']]) }}
                @else
                    {{ __('เปิดใช้บัญชีเรียบร้อย การจอง :result รายการอยู่ในบัญชีของคุณแล้ว', ['result' => $result['count']]) }}
                @endif
            </div>

            <p class="lead">
                {{ __('เข้าสู่ระบบด้วยอีเมล') }} <strong>{{ $result['email'] }}</strong>
                {{ __('และรหัสผ่านที่คุณเพิ่งตั้ง แล้วการจองจะขึ้นในหน้า "การจองของฉัน" ทันที') }}
            </p>

            {{--
                ก่อนหน้านี้หน้านี้บอกให้ "เข้าสู่ระบบในแอป" แล้วจบแค่นั้น ไม่มีลิงก์
                ให้โหลด — ลูกค้าที่อุตส่าห์กดลิงก์จาก SMS มาตั้งรหัสผ่านจนเสร็จ
                ต้องไปค้นเอาเองในร้านแอป ซึ่งคนส่วนใหญ่เลิกตรงนี้

                จุดนี้คือจังหวะที่ถูกที่สุดของทั้งระบบที่จะชวนโหลด: บัญชีพร้อมแล้ว
                การจองอยู่ในนั้นแล้ว เปิดแอปปุ๊บเห็นของตัวเองทันที ไม่ใช่หน้าว่าง
            --}}
            <div class="app-invite">
                <div class="app-invite-title">{{ __('เปิดในแอปได้เลย') }}</div>
                <p class="app-invite-text">
                    {{ __('ในแอปมีห้องแชทของรอบไว้คุยกับเพื่อนร่วมทริป ชื่อและเบอร์ทีมงาน ที่กดโทรได้ QR สำหรับเช็คอินหน้างาน และติดตามรถแบบเรียลไทม์วันเดินทาง') }}
                </p>
                <div class="app-invite-stores">
                    <a href="{{ \App\Support\AppLinks::ios() }}" target="_blank" rel="noopener">App Store</a>
                    <a href="{{ \App\Support\AppLinks::android() }}" target="_blank" rel="noopener">Google Play</a>
                </div>
            </div>

            <a class="btn" style="text-decoration:none;text-align:center" href="{{ url('/') }}">{{ __('กลับหน้าแรก') }}</a>

            <p class="note">{{ __('ถ้ายังไม่เห็นการจอง ลองดึงหน้าจอลงเพื่อรีเฟรช หรือทักทีมงานได้เลยครับ') }}</p>
        </div>
    </div>

    <style>
        .app-invite {
            border: 1px solid #a7f3d0;
            background: #f0fdf9;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }
        .app-invite-title { font-size: 15px; font-weight: 700; color: #065f46; margin-bottom: 6px; }
        .app-invite-text { font-size: 13px; color: #047857; line-height: 1.7; margin-bottom: 14px; }
        .app-invite-stores { display: flex; gap: 8px; }
        .app-invite-stores a {
            flex: 1; text-align: center; text-decoration: none;
            background: #059669; color: #fff; font-weight: 700; font-size: 14px;
            padding: 12px 8px; border-radius: 10px;
        }
        .app-invite-stores a:active { transform: translateY(1px); }
    </style>
@endsection

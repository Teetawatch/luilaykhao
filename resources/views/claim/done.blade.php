@extends('public.form-layout')

@section('title', 'เปิดใช้บัญชีเรียบร้อย')

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>เรียบร้อยแล้ว 🎉</h1>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                @if ($result['merged'])
                    ย้ายการจอง {{ $result['count'] }} รายการไปที่บัญชีของคุณแล้ว
                @else
                    เปิดใช้บัญชีเรียบร้อย การจอง {{ $result['count'] }} รายการอยู่ในบัญชีของคุณแล้ว
                @endif
            </div>

            <p class="lead">
                เข้าสู่ระบบในแอปลุยเลเขาด้วยอีเมล <strong>{{ $result['email'] }}</strong>
                และรหัสผ่านที่คุณเพิ่งตั้ง แล้วการจองจะขึ้นในหน้า "การจองของฉัน" ทันที
            </p>

            <a class="btn" style="text-decoration:none;text-align:center" href="{{ url('/') }}">กลับหน้าแรก</a>

            <p class="note">ถ้ายังไม่เห็นการจอง ลองดึงหน้าจอลงเพื่อรีเฟรช หรือทักทีมงานได้เลยครับ</p>
        </div>
    </div>
@endsection

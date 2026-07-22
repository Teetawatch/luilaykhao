@extends('passenger-fill.layout')

@section('title', 'บันทึกข้อมูลแล้ว')

@section('content')
<div class="card">
    <div class="done">
        <div class="tick">✅</div>
        <h1>บันทึกข้อมูลเรียบร้อย</h1>
        <p>
            @if ($name)
                ขอบคุณ {{ $name }} — ข้อมูลของคุณถูกส่งให้ทีมงานแล้ว
            @else
                ข้อมูลของคุณถูกส่งให้ทีมงานแล้ว
            @endif
            <br>แล้วเจอกันวันเดินทาง
        </p>
    </div>
</div>
@endsection

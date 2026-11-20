@extends('passenger-fill.layout')

@section('title', __('บันทึกข้อมูลแล้ว'))

@section('content')
<div class="card">
    <div class="done">
        <div class="tick">✅</div>
        <h1>{{ __('บันทึกข้อมูลเรียบร้อย') }}</h1>
        <p>
            @if ($name)
                {{ __('ขอบคุณ :name — ข้อมูลของคุณถูกส่งให้ทีมงานแล้ว', ['name' => $name]) }}
            @else
                {{ __('ข้อมูลของคุณถูกส่งให้ทีมงานแล้ว') }}
            @endif
            <br>{{ __('แล้วเจอกันวันเดินทาง') }}
        </p>
        @if (! empty($passUrl))
            {{-- มาจากลิงก์ของเพื่อน: ต่อไปคือบัตรขึ้นรถของตัวเองและห้องแชทของทริป --}}
            <a href="{{ $passUrl }}" class="btn" style="margin-top: 22px; text-decoration: none;">{{ __('ดูบัตรขึ้นรถและเข้าห้องแชทของทริป') }}</a>
        @endif
    </div>
</div>
@endsection

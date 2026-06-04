@extends('admin.payments.layout')

@section('title', 'เข้าสู่ระบบ')

@section('body')
<div class="login-wrap">
    <div class="card">
        <h1>Luilaykhao Admin</h1>
        <p class="sub">เข้าสู่ระบบเพื่อติดตามการชำระเงิน</p>

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.payments.login') }}">
            @csrf
            <label class="field" for="email">อีเมล</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus>

            <label class="field" for="password">รหัสผ่าน</label>
            <input type="password" name="password" id="password" required>

            <button type="submit" class="btn btn-full">เข้าสู่ระบบ</button>
        </form>
    </div>
</div>
@endsection

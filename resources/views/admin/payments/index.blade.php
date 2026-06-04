@extends('admin.payments.layout')

@section('title', 'ติดตามการชำระเงิน')

@section('body')
<div class="topbar">
    <span class="brand">Luilaykhao · ติดตามการชำระเงิน</span>
    <form method="POST" action="{{ route('admin.payments.logout') }}">
        @csrf
        <button type="submit" class="logout-btn">ออกจากระบบ</button>
    </form>
</div>

<div class="wrap">
    <h1>ลูกค้าที่ยังค้างชำระ</h1>
    <p class="sub">รายการที่ยังมีค่างวดหรือยอดส่วนที่เหลือค้างอยู่ ส่งลิงก์ชำระเงิน (QR PromptPay + แนบสลิป) ให้รายคนได้เลย</p>

    @if (session('flash_success'))
        <div class="alert alert-success">{{ session('flash_success') }}</div>
    @endif
    @if (session('flash_error'))
        <div class="alert alert-error">{{ session('flash_error') }}</div>
    @endif

    <div class="summary">
        <div class="stat">
            <div class="num">{{ $rows->count() }}</div>
            <div class="lbl">รายการค้างชำระ</div>
        </div>
        <div class="stat">
            <div class="num">฿{{ number_format($totalDue, 0) }}</div>
            <div class="lbl">ยอดค้างรวม</div>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.payments.index') }}" class="toolbar">
        <input type="text" name="search" value="{{ $search }}" placeholder="ค้นหา เลขการจอง / ชื่อ / เบอร์โทร">
        @if ($scheduleId)
            <input type="hidden" name="schedule_id" value="{{ $scheduleId }}">
        @endif
        <button type="submit" class="btn btn-ghost btn-sm">ค้นหา</button>
        @if ($search || $scheduleId)
            <a href="{{ route('admin.payments.index') }}" class="btn btn-ghost btn-sm" style="text-decoration:none;">ล้าง</a>
        @endif
    </form>

    @if ($rows->isEmpty())
        <div class="card empty">ไม่มีรายการค้างชำระ 🎉</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>เลขการจอง</th>
                    <th>ลูกค้า</th>
                    <th>ทริป</th>
                    <th>ยอด/งวด</th>
                    <th>ครบกำหนด</th>
                    <th>ลิงก์ชำระ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['booking_ref'] }}</strong>
                            <div>
                                <span class="chip {{ $row['type'] === 'installment' ? 'chip-inst' : 'chip-bal' }}">{{ $row['label'] }}</span>
                                @if ($row['overdue'])
                                    <span class="chip chip-overdue">เลยกำหนด</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            {{ $row['customer_name'] }}
                            @if ($row['phone'])
                                <div class="muted">{{ $row['phone'] }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $row['trip_title'] }}
                            @if ($row['departure_date'])
                                <div class="muted">ออกเดินทาง {{ $row['departure_date'] }}</div>
                            @endif
                        </td>
                        <td><span class="amount">฿{{ number_format($row['amount_due'], 0) }}</span></td>
                        <td>{{ $row['due_date'] ?? '-' }}</td>
                        <td>
                            <div class="link-cell">
                                <a href="{{ $row['pay_url'] }}" target="_blank" rel="noopener" class="muted" style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $row['pay_url'] }}</a>
                                <button type="button" class="copy-btn" data-copy="{{ $row['pay_url'] }}">คัดลอก</button>
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="POST" action="{{ route('admin.payments.send-link', $row['booking_ref']) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm" {{ $row['email'] ? '' : 'disabled title=ไม่มีอีเมล' }}>ส่งอีเมล</button>
                                </form>
                                @if ($row['phone'])
                                    <form method="POST" action="{{ route('admin.payments.send-link', $row['booking_ref']) }}">
                                        @csrf
                                        <input type="hidden" name="channels[]" value="sms">
                                        <button type="submit" class="btn btn-ghost btn-sm">ส่ง SMS</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection

@section('scripts')
<script>
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            navigator.clipboard && navigator.clipboard.writeText(text).then(function () {
                var original = btn.textContent;
                btn.textContent = 'คัดลอกแล้ว';
                setTimeout(function () { btn.textContent = original; }, 1500);
            });
        });
    });
</script>
@endsection

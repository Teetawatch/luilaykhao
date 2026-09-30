{{-- รอบไม่ได้ออกเพราะคนไม่ครบ — ขอรับเงินคืนเต็มจำนวนแทนการเลือกรอบใหม่ --}}
@php($amount = (float) ($fm['refund_amount'] ?? 0))
<details class="refund-box" @if ($open) open @endif>
    <summary>ไม่สะดวกรอบไหนเลย? ขอรับเงินคืนเต็มจำนวน</summary>

    @if ($errors->has('refund'))
        <div class="alert alert-error" style="margin-top:12px;">{{ $errors->first('refund') }}</div>
    @endif

    <form method="POST" action="{{ route('public.reschedule.refund', $token) }}" id="refund-form"
          data-confirm="{{ $amount > 0
              ? 'ยกเลิกการจองและขอรับเงินคืน ฿'.number_format($amount, 0).' ใช่ไหมครับ? ยกเลิกแล้วย้อนกลับไม่ได้'
              : 'ยกเลิกการจองนี้ใช่ไหมครับ? ยกเลิกแล้วย้อนกลับไม่ได้' }}">
        @csrf
        @if ($amount > 0)
            <p class="note" style="text-align:left;margin-top:10px;">
                คืนเต็มจำนวน <strong>฿{{ number_format($amount, 0) }}</strong> (รวมมัดจำ) ภายใน 3–7 วันทำการ
            </p>
            <div class="field">
                <label for="refund-bank">ธนาคาร / พร้อมเพย์</label>
                <select id="refund-bank" name="bank" required>
                    <option value="">เลือก…</option>
                    @foreach ($fm['refund_banks'] as $bank)
                        <option value="{{ $bank }}" @selected(old('bank') === $bank)>{{ $bank }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="refund-number">เลขบัญชี / เบอร์พร้อมเพย์</label>
                <input id="refund-number" name="account_number" inputmode="numeric" autocomplete="off" required
                       value="{{ old('account_number') }}" placeholder="เช่น 123-4-56789-0">
            </div>
            <div class="field">
                <label for="refund-name">ชื่อบัญชี</label>
                <input id="refund-name" name="account_name" required value="{{ old('account_name') }}" placeholder="ชื่อ-นามสกุลตามบัญชี">
            </div>
        @else
            <p class="note" style="text-align:left;margin-top:10px;">การจองนี้ยังไม่มียอดที่ชำระ กดยกเลิกได้เลยครับ</p>
        @endif
        <button type="submit" class="btn btn-outline" id="refund-btn" style="margin-top:14px;">
            {{ $amount > 0 ? 'ยกเลิกและขอรับเงินคืน' : 'ยกเลิกการจอง' }}
        </button>
    </form>
</details>

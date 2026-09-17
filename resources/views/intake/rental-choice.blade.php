{{-- อุปกรณ์ให้เช่าของทริปนี้ — ถามตั้งแต่ตอนกรอก ไม่ใช่ไปถามในแชททีหลัง

     คนที่ไม่มีถุงนอนกับคนที่มีของตัวเองอยู่ในกลุ่มเดียวกันเสมอ คำตอบจึงเป็นของ
     รายคน ไม่ใช่ของกลุ่ม — เพื่อนที่กดลิงก์กลุ่มตามมาเลือกของตัวเองได้อีกชุด
     แล้วทีมงานรวมเป็นยอดเดียวตอนเปิดใบจอง

     พูดให้ชัดเหมือนเรื่องที่นั่ง: เลือกไว้ = แจ้งความต้องการ ยังไม่ใช่การกันของ --}}
@php
    $rentalOld = old('rentals', []);
@endphp

<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'backpack'])</span>
    <h2>อุปกรณ์ให้เช่า</h2>
    <span class="rule"></span>
</div>
<p class="step-note">
    ไม่มีของก็เช่าของเราได้ เลือกเฉพาะที่ <strong>คุณ</strong> ต้องใช้
    ถ้ามีของตัวเองอยู่แล้วก็ข้ามส่วนนี้ไปได้เลย <span class="opt">(ไม่บังคับ)</span>
</p>

<div class="rentals" data-rentals>
    @foreach ($rentalItems as $item)
        @php($quantity = (int) ($rentalOld[$item['key']] ?? 0))
        <div class="rental @if ($quantity > 0) rental--on @endif" data-rental data-price="{{ (float) $item['price'] }}">
            @if ($item['image_url'])
                <img class="rental-photo" src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy">
            @else
                <span class="rental-photo rental-photo--blank">@include('intake.icon', ['name' => 'backpack'])</span>
            @endif

            <div class="rental-body">
                <strong>{{ $item['name'] }}</strong>
                @if ($item['description'])
                    <span class="rental-note">{{ $item['description'] }}</span>
                @endif
                @if (! empty($item['parts']))
                    {{-- ชุดที่มีของหลายชิ้น — ลูกค้าต้องรู้ว่า "ชุดเต็นท์" ได้อะไรมาบ้าง
                         ก่อนตัดสินใจ ไม่ใช่รู้ตอนไปรับของหน้างาน --}}
                    <span class="rental-parts">
                        ในชุดมี
                        {{ collect($item['parts'])->map(fn ($part) => $part['name'].' ×'.$part['quantity'])->implode(' · ') }}
                    </span>
                @endif
                <span class="rental-price">{{ number_format((float) $item['price']) }} บาท / ชิ้น</span>
            </div>

            <div class="stepper-box stepper-box--sm" data-stepper>
                <button type="button" data-step="-1" aria-label="ลดจำนวน {{ $item['name'] }}">@include('intake.icon', ['name' => 'minus'])</button>
                <input type="number" name="rentals[{{ $item['key'] }}]" inputmode="numeric"
                       min="0" max="{{ \App\Http\Controllers\PublicIntakeController::MAX_RENTAL_QUANTITY }}" value="{{ $quantity }}"
                       aria-label="จำนวนที่เช่า {{ $item['name'] }}">
                <button type="button" data-step="1" aria-label="เพิ่มจำนวน {{ $item['name'] }}">@include('intake.icon', ['name' => 'plus'])</button>
            </div>
        </div>
    @endforeach
</div>

<div class="rental-sum" data-rental-sum hidden>
    <span>ค่าเช่าของคุณ</span>
    <strong data-rental-total>0 บาท</strong>
</div>

<p class="hint">
    ค่าเช่าจะรวมอยู่ในยอดที่ทีมงานแจ้งกลับ ของมีจำนวนจำกัด
    ถ้าชิ้นไหนหมด ทีมงานจะติดต่อกลับก่อนเปิดการจอง
</p>

@push('scripts')
    <script>
        // ยอดค่าเช่ารวม — ลูกค้าเลือกหลายชิ้นแล้วควรรู้ทันทีว่ากำลังจะจ่ายเพิ่มเท่าไหร่
        // ไม่ใช่ไปรู้ตอนทีมงานส่งยอดกลับมา (ยอดจริงคิดใหม่ฝั่งเซิร์ฟเวอร์เสมอ)
        (function () {
            var wrap = document.querySelector('[data-rentals]');
            var sum = document.querySelector('[data-rental-sum]');
            if (!wrap || !sum) { return; }

            var out = sum.querySelector('[data-rental-total]');

            function sync() {
                var total = 0;

                wrap.querySelectorAll('[data-rental]').forEach(function (row) {
                    var input = row.querySelector('input');
                    var quantity = parseInt(input.value || '0', 10) || 0;
                    row.classList.toggle('rental--on', quantity > 0);
                    total += quantity * parseFloat(row.dataset.price || '0');
                });

                sum.hidden = total <= 0;
                out.textContent = total.toLocaleString('th-TH') + ' บาท';
            }

            wrap.addEventListener('click', sync);
            wrap.addEventListener('input', sync);
            sync();
        })();
    </script>
@endpush

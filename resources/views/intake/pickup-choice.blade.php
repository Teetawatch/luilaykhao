{{-- จุดขึ้นรถของคนที่กำลังกรอกอยู่ตอนนี้ — คนละคนในกลุ่มขึ้นคนละจุดได้
     รูปคือสิ่งที่ทำให้ลูกค้ามั่นใจว่าไปยืนถูกที่ตอนตีสี่ ชื่อจุดอย่างเดียวไม่พอ --}}
<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'pin'])</span>
    <h2>จุดขึ้นรถ</h2>
    <span class="rule"></span>
</div>
<p class="step-note">เลือกจุดที่ <strong>คุณ</strong> จะขึ้นรถ เพื่อนในกลุ่มเลือกจุดของตัวเองได้ ไม่ต้องตรงกัน</p>

<div class="pickups">
    @foreach ($pickupPoints as $point)
        <label class="pickup" for="pickup_{{ $point->id }}">
            <input type="radio" id="pickup_{{ $point->id }}" name="pickup_point_id"
                   value="{{ $point->id }}" required
                   @checked((int) old('pickup_point_id') === $point->id)>
            <span class="pickup-card">
                @if ($point->image_url)
                    <img class="pickup-photo" src="{{ $point->image_url }}" alt="{{ $point->pickup_location }}" loading="lazy">
                @else
                    <span class="pickup-photo pickup-photo--blank">@include('intake.icon', ['name' => 'pin'])</span>
                @endif
                <span class="pickup-body">
                    <strong>{{ $point->pickup_location }}</strong>
                    <span class="pickup-meta">
                        {{ $point->region_label ?: $point->region }}
                        @if ($point->pickup_time)
                            · นัดหมาย {{ $point->pickup_time }} น.
                        @endif
                    </span>
                    @if ($point->notes)
                        <span class="pickup-note">{{ $point->notes }}</span>
                    @endif
                    {{-- ราคาจุดรับคือราคาต่อคนของโซนนั้น ไม่ใช่ค่าบริการที่บวกเพิ่ม
                         จึงเขียนให้ชัดว่าเป็นราคาต่อท่าน ไม่ใช่ค่ารถไปจุดนั้น --}}
                    @if ($point->price)
                        <span class="pickup-price">{{ number_format((float) $point->price) }} บาท / ท่าน</span>
                    @endif
                </span>
                <span class="pickup-tick">@include('intake.icon', ['name' => 'check'])</span>
            </span>
        </label>
    @endforeach
</div>

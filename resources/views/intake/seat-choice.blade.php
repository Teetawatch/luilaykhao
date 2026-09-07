{{-- ที่นั่งที่ลูกค้าเลือกเอง — เลือกได้จริง แต่ยังไม่ถูกล็อก

     ต้องพูดให้ตรงว่ามันคือ "ที่นั่งที่ขอไว้" ไม่ใช่ "ที่นั่งที่ได้แล้ว" เพราะคนที่กด
     จองเองและจ่ายเงินในหน้าต่างชำระเงินคือคนที่ได้ที่นั่งจริง ๆ ถ้าเขียนกำกวมไว้
     ลูกค้าที่เสียที่นั่งไปจะรู้สึกว่าถูกยึดของที่เป็นของตัวเองแล้ว --}}
@php
    $seatsById = collect($seatMap['seats'])->keyBy('id');
    $seatColumns = $seatMap['columns'] ?: ['A'];
    $selectedSeat = old('seat_id');
    $seatsLeft = (int) $seatMap['available_count'];
@endphp

<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'seat'])</span>
    <h2>เลือกที่นั่งของคุณ</h2>
    <span class="rule"></span>
</div>
<p class="step-note">
    เลือกที่นั่งที่ <strong>คุณ</strong> จะนั่ง เพื่อนในกลุ่มเลือกของตัวเองทีละคน
    ที่นั่งที่มีคนเลือกไว้แล้วจะกดไม่ได้
</p>

@if ($seatsLeft === 0)
    {{-- ที่นั่งหมดแต่ยังรับข้อมูลอยู่ — ต้องบอกว่ากรอกต่อได้ ไม่ใช่ปล่อยให้เห็นผัง
         ที่กดอะไรไม่ได้เลยแล้วเดาเอาเองว่าฟอร์มพัง --}}
    <div class="callout callout--warn">
        @include('intake.icon', ['name' => 'alert'])
        <div>
            <strong>ที่นั่งถูกเลือกไปหมดแล้ว</strong>
            กรอกข้อมูลส่วนที่เหลือต่อได้เลย ทีมงานจะติดต่อกลับเรื่องที่นั่งที่ว่างหรือรอบอื่นให้คุณ
        </div>
    </div>
@else
    <div class="callout callout--warn">
        @include('intake.icon', ['name' => 'alert'])
        <div>
            <strong>ที่นั่งนี้ยังไม่ถูกกันไว้ให้</strong>
            ทีมงานจะกันที่นั่งให้ตอนเปิดการจองให้คุณ ระหว่างนี้ถ้ามีคนกดจองและชำระเงินเอง
            ที่นั่งจะเป็นของคนนั้นก่อน ถ้าที่นั่งที่คุณเลือกถูกใช้ไป ทีมงานจะติดต่อกลับให้เลือกใหม่
        </div>
    </div>
@endif

<div class="f">
    <div class="seat-legend">
        <span><i class="seat-dot seat-dot--free"></i> ว่าง</span>
        <span><i class="seat-dot seat-dot--mine"></i> ที่นั่งของคุณ</span>
        <span><i class="seat-dot seat-dot--taken"></i> มีคนเลือก/จองแล้ว</span>
        <span class="seat-left">เหลือ {{ $seatsLeft }} ที่</span>
    </div>

    <div class="seat-vehicle">
        <div class="seat-front">
            <span>{{ $seatMap['front_label'] }}</span>
            <span class="seat-driver">@include('intake.icon', ['name' => 'wheel']) คนขับ</span>
        </div>

        <div class="seat-grid" style="grid-template-columns: {{ collect($seatColumns)->map(fn ($column) => $column === '' ? '18px' : 'minmax(0, 1fr)')->implode(' ') }}">
            @for ($row = 1; $row <= (int) $seatMap['rows']; $row++)
                @foreach ($seatColumns as $column)
                    @if ($column === '')
                        <span class="seat-aisle"></span>
                    @else
                        @php($seat = $seatsById->get($column.$row))
                        @if (! $seat)
                            <span class="seat-gap"></span>
                        @else
                            @php($taken = $seat['state'] !== 'available')
                            <label class="seat @if ($taken) seat--taken @endif" title="{{ $seat['note'] ?: 'ว่าง' }}">
                                <input type="radio" name="seat_id" value="{{ $seat['id'] }}"
                                       required @disabled($taken)
                                       @checked($selectedSeat === $seat['id'])>
                                <span class="seat-card">
                                    <strong>{{ $seat['label'] ?? $seat['id'] }}</strong>
                                    @if ($seat['note'])
                                        <small>{{ $seat['note'] }}</small>
                                    @endif
                                </span>
                            </label>
                        @endif
                    @endif
                @endforeach
            @endfor
        </div>

        <div class="seat-rear">{{ $seatMap['rear_label'] }}</div>
    </div>

    @if ($seatMap['vehicle_option_label'])
        <p class="hint">ผังของ {{ $seatMap['vehicle_option_label'] }} — ถ้าทีมงานเปลี่ยนคัน จะแจ้งให้เลือกใหม่</p>
    @endif
</div>

{{--
    วัน/เดือน/ปีเกิด แบบ dropdown — ปีแสดงเป็น พ.ศ. (value ยังเป็น ค.ศ. เพื่อให้ฝั่งเซิร์ฟเวอร์เก็บได้ตรง)
    ตัวแปรที่ต้องส่งเข้ามา: $dayName, $monthName, $yearName, $selDay, $selMonth, $selYear, (optional) $required
--}}
@php
    $thaiMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $thisYear = (int) now()->year;
    $isRequired = $required ?? false;
@endphp
<div class="dob-row">
    <select class="dob-day" name="{{ $dayName }}" @if ($isRequired) required @endif>
        <option value="">วัน</option>
        @for ($d = 1; $d <= 31; $d++)
            <option value="{{ $d }}" @selected((int) $selDay === $d)>{{ $d }}</option>
        @endfor
    </select>
    <select class="dob-month" name="{{ $monthName }}" @if ($isRequired) required @endif>
        <option value="">เดือน</option>
        @foreach ($thaiMonths as $num => $name)
            <option value="{{ $num }}" @selected((int) $selMonth === $num)>{{ $name }}</option>
        @endforeach
    </select>
    <select class="dob-year" name="{{ $yearName }}" @if ($isRequired) required @endif>
        <option value="">ปี (พ.ศ.)</option>
        @for ($y = $thisYear; $y >= 1900; $y--)
            <option value="{{ $y }}" @selected((int) $selYear === $y)>{{ $y + 543 }}</option>
        @endfor
    </select>
</div>

{{--
  ใบเสร็จแยกรายบุคคลในอีเมลยืนยันการชำระ
  - ผู้จอง ($receipt = ใบรวม): ลิงก์ใบของทุกคน ไว้ส่งต่อให้เพื่อนที่ไม่ได้กรอกอีเมล
  - เพื่อน ($receipt = ใบของตัวเอง): บอกว่าที่แนบมาเป็นใบในชื่อเขา แยกจากใบรวม
--}}
@props(['receipt' => null, 'personal' => null])

@if($receipt && $personal && $personal->isNotEmpty())
  @if($receipt->isPersonal())
    <p class="body-text">
      ใบเสร็จที่แนบมาออก<strong>ในนามของคุณ</strong> ตามส่วนของคุณในการจองนี้
      สามารถใช้ประกอบการเบิกจ่ายหรือเก็บไว้เป็นหลักฐานได้ครับ
    </p>
  @else
    <p class="section-label">ใบเสร็จแยกรายบุคคล</p>
    <p class="body-text">
      ผู้เดินทางที่ต้องการใบเสร็จในนามของตนเอง (เช่น เพื่อเบิกจ่ายกับบริษัท) สามารถส่งลิงก์ของแต่ละท่านให้ได้ครับ
      ผู้ที่กรอกอีเมลไว้ขณะจองจะได้รับใบเสร็จของตนเองทางอีเมลด้วย
    </p>
    <div class="info-card">
      @foreach($personal as $p)
      <div class="info-row">
        <span class="info-label">{{ data_get($p->snapshot, 'customer.name', '-') }}</span>
        <span class="info-value accent-teal">
          <a href="{{ url('/receipt/'.$p->verify_token) }}">฿{{ number_format((float) $p->amount, 2) }} · เปิดใบเสร็จ</a>
        </span>
      </div>
      @endforeach
    </div>
  @endif
@endif

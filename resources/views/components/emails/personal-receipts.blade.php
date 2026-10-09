{{--
  ใบเสร็จแยกรายบุคคลในอีเมลยืนยันการชำระ
  - ผู้จอง ($receipt = ใบรวม): ลิงก์ใบของทุกคน ไว้ส่งต่อให้เพื่อนที่ไม่ได้กรอกอีเมล
  - เพื่อน ($receipt = ใบของตัวเอง): บอกว่าที่แนบมาเป็นใบในชื่อเขา แยกจากใบรวม
--}}
@props(['receipt' => null, 'personal' => null])

@if($receipt && $personal && $personal->isNotEmpty())
  @if($receipt->isPersonal())
    <p class="body-text">
      ใบเสร็จที่แนบมาออก<strong>ในชื่อของคุณ</strong> ตามส่วนของคุณในการจองนี้
      ใช้เบิกหรือเก็บเป็นหลักฐานได้เลยครับ
    </p>
  @else
    <p class="section-label">ใบเสร็จแยกรายคน</p>
    <p class="body-text">
      เพื่อน ๆ ที่ต้องใช้ใบเสร็จในชื่อตัวเอง (เช่น เบิกบริษัท) ส่งลิงก์ของแต่ละคนให้ได้เลยครับ
      ใครที่กรอกอีเมลไว้ตอนจอง จะได้รับใบของตัวเองทางอีเมลด้วยครับ
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

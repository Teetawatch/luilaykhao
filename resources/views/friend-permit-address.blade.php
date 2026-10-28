{{-- ช่องค้นหา แขวง/ตำบล · เขต/อำเภอ · จังหวัด (ใช้ใน friend-permit) — เลือกจากรายการเท่านั้น --}}
<label class="field" for="addr-search">แขวง/ตำบล · เขต/อำเภอ · จังหวัด</label>
<div class="addr-wrap">
    <input type="search" id="addr-search" class="addr-search" placeholder="พิมพ์ชื่อตำบล อำเภอ หรือรหัสไปรษณีย์" autocomplete="off">
    <ul class="addr-results" id="addr-results"></ul>
    <div class="addr-chosen" id="addr-chosen"></div>
    <input type="hidden" name="extra[sub_district]" value="{{ old('extra.sub_district') }}">
    <input type="hidden" name="extra[district]" value="{{ old('extra.district') }}">
    <input type="hidden" name="extra[province]" value="{{ old('extra.province') }}">
</div>

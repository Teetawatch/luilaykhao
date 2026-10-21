@extends('public.form-layout')

@section('title', 'กรอกข้อมูลพาสปอร์ต')

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="brand">LUILAYKHAO</div>
            <h1>ข้อมูลพาสปอร์ตผู้เดินทาง</h1>
            @if ($booking->schedule?->trip)
                <div style="margin-top:8px;font-size:13.5px;opacity:.95;">
                    {{ $booking->schedule->trip->title }}
                    @if ($booking->schedule->departure_date)
                        · {{ $booking->schedule->departure_date->format('d/m/') }}{{ $booking->schedule->departure_date->year + 543 }}
                    @endif
                </div>
            @endif
        </div>
        <div class="card-body">
            @if (session('saved'))
                <div class="alert alert-success">
                    บันทึกข้อมูลพาสปอร์ตเรียบร้อยแล้ว ขอบคุณมากครับ 🙏
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="lead">
                @if ($booking->schedule?->trip?->offersBorderPass())
                    ทริปนี้ใช้พาสปอร์ตหรือ Border Pass ก็ได้ ท่านที่ไม่มีพาสปอร์ตเลือก "ให้ทีมงานทำ Border Pass" ได้เลย
                    ท่านที่มีพาสปอร์ต
                @else
                    ทริปนี้เดินทางออกนอกประเทศ
                @endif
                ทีมงานต้องใช้ข้อมูลหน้าพาสปอร์ตของผู้เดินทางทุกท่าน
                เพื่อออกตั๋วและยื่นเอกสาร (รหัสจอง {{ $booking->booking_ref }})
                กรุณากรอกให้ตรงกับหน้าพาสปอร์ตทุกตัวอักษรนะครับ
            </p>

            <form method="POST" action="{{ route('public.passport.submit', request()->route('token')) }}" enctype="multipart/form-data">
                @csrf
                @php($trip = $booking->schedule?->trip)
                @foreach ($booking->passengers as $i => $passenger)
                    @php($borderPass = $trip?->resolveTravelDocument($passenger->travel_document, $passenger->nationality) === 'border_pass')
                    @php($idDone = isset($idFiles[$passenger->id][\App\Support\TripDocumentRequirements::BORDER_PASS_FRONT], $idFiles[$passenger->id][\App\Support\TripDocumentRequirements::BORDER_PASS_BACK]))
                    @php($complete = $borderPass ? $idDone : (filled($passenger->name_en) && filled($passenger->passport_no) && filled($passenger->passport_expires_at)))
                    @php($canChoose = $trip?->offersBorderPass() && ($passenger->nationality ?: 'TH') === 'TH')
                    <div class="pax" data-travel-doc>
                        <div class="pax-name">
                            {{ $i + 1 }}. {{ $passenger->title }} {{ $passenger->name }}
                            @if ($complete)
                                <span class="badge">{{ $borderPass ? 'ทำ Border Pass' : 'กรอกแล้ว' }}</span>
                            @endif
                        </div>

                        @if ($canChoose)
                            @php($choice = old("travel_document.$passenger->id", $passenger->travel_document ?: ($complete ? 'passport' : null)))
                            <div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin:4px 0 12px;font-size:14px;">
                                <label><input type="radio" data-doc-choice name="travel_document[{{ $passenger->id }}]" value="passport" @checked($choice === 'passport')> มีพาสปอร์ต</label>
                                <label><input type="radio" data-doc-choice name="travel_document[{{ $passenger->id }}]" value="border_pass" @checked($choice === 'border_pass')> ไม่มี ให้ทีมงานทำ Border Pass</label>
                            </div>
                            <div class="hint" data-border-pass-note hidden>
                                ทีมงานยื่นทำ Border Pass จากเลขบัตรประชาชนที่ให้ไว้ตอนจอง วันเดินทางพกบัตรประชาชนตัวจริงมาด้วย
                                — ด่านขอรูปบัตรประชาชนด้านหน้าและด้านหลังเป็นหลักฐาน
                                @include('partials.border-pass-id-upload', [
                                    'frontName' => "border_pass_front[{$passenger->id}]",
                                    'backName' => "border_pass_back[{$passenger->id}]",
                                    'hasFront' => isset($idFiles[$passenger->id][\App\Support\TripDocumentRequirements::BORDER_PASS_FRONT]),
                                    'hasBack' => isset($idFiles[$passenger->id][\App\Support\TripDocumentRequirements::BORDER_PASS_BACK]),
                                ])
                            </div>
                        @endif

                        <div @if ($canChoose) data-passport-fields @endif>
                        <label class="field" for="name_en_{{ $passenger->id }}">ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต)</label>
                        <input type="text" id="name_en_{{ $passenger->id }}" name="name_en[{{ $passenger->id }}]"
                               maxlength="255" style="text-transform:uppercase" placeholder="SOMCHAI JAIDEE"
                               value="{{ old("name_en.$passenger->id", $passenger->name_en) }}">

                        <label class="field" for="passport_no_{{ $passenger->id }}">เลขที่พาสปอร์ต</label>
                        <input type="text" id="passport_no_{{ $passenger->id }}" name="passport_no[{{ $passenger->id }}]"
                               maxlength="20" style="text-transform:uppercase" placeholder="AA1234567"
                               value="{{ old("passport_no.$passenger->id", $passenger->passport_no) }}">

                        <label class="field" for="passport_expires_at_{{ $passenger->id }}">วันหมดอายุพาสปอร์ต</label>
                        <input type="date" id="passport_expires_at_{{ $passenger->id }}"
                               name="passport_expires_at[{{ $passenger->id }}]" @if ($minExpiry) min="{{ $minExpiry }}" @endif
                               value="{{ old("passport_expires_at.$passenger->id", $passenger->passport_expires_at?->toDateString()) }}">
                        </div>
                    </div>
                @endforeach

                <p class="hint">
                    พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง
                    @if ($minExpiry)
                        (หมดอายุหลัง {{ \App\Support\ThaiDate::short(\Illuminate\Support\Carbon::parse($minExpiry)) }})
                    @endif
                    · ท่านใดยังไม่พร้อมกรอก เว้นว่างไว้แล้วกลับมากรอกทีหลังได้ครับ
                </p>

                <button type="submit" class="btn">บันทึกข้อมูลพาสปอร์ต</button>
            </form>
            @include('partials.travel-document-toggle')

            <p class="note">ลิงก์นี้สำหรับการจองของคุณเท่านั้น กรุณาอย่าส่งต่อให้ผู้อื่น</p>
        </div>
    </div>
@endsection

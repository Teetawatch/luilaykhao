{{--
    สลับช่องพาสปอร์ตตามคำตอบ "มีพาสปอร์ตไหม" ของทริปที่ให้เลือก Border Pass

    ช่องที่ซ่อนถูก disable ด้วย — ทั้งไม่ให้ required ขวางการส่งฟอร์ม และไม่ให้
    ค่าเก่าที่เบราว์เซอร์เติมไว้หลุดไปถึง server (คนเลือก Border Pass ไม่ต้องมี
    เลขพาสปอร์ตในระบบ)

    มาร์กอัปที่ใช้: radio `[data-doc-choice]` + กล่อง `[data-passport-fields]` และ
    `[data-border-pass-note]` อยู่ในกล่อง `[data-travel-doc]` เดียวกัน (หน้าที่มีคน
    เดียวไม่ต้องห่อ ใช้ทั้งฟอร์มเป็นกล่อง)
--}}
<script>
    (function () {
        var scopes = document.querySelectorAll('[data-travel-doc]');
        if (!scopes.length) scopes = document.querySelectorAll('form');

        scopes.forEach(function (scope) {
            var radios = scope.querySelectorAll('input[data-doc-choice]');
            var fields = scope.querySelector('[data-passport-fields]');
            if (!radios.length || !fields) return;
            var note = scope.querySelector('[data-border-pass-note]');

            function sync() {
                var picked = scope.querySelector('input[data-doc-choice]:checked');
                var showPassport = !!picked && picked.value === 'passport';
                fields.hidden = !showPassport;
                fields.querySelectorAll('input').forEach(function (input) {
                    input.disabled = !showPassport;
                });
                var borderPass = !!picked && picked.value === 'border_pass';
                if (note) {
                    note.hidden = !borderPass;
                    // ช่องแนบบัตรประชาชนส่งเฉพาะคนที่เลือก Border Pass
                    note.querySelectorAll('input').forEach(function (input) {
                        input.disabled = !borderPass;
                    });
                }
            }

            radios.forEach(function (radio) { radio.addEventListener('change', sync); });
            sync();
        });
    })();
</script>

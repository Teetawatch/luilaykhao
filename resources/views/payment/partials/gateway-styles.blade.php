{{--
    สไตล์ของ QR เกตเวย์ (payment.partials.gateway-qr) ช่วง "จ่ายแล้ว กำลังรอผล"

    ใช้ร่วมกันระหว่าง payment.layout (ลิงก์ค่างวด/ยอดคงเหลือ/ส่วนแบ่ง) กับหน้าชำระเงิน
    ของลิงก์กลุ่ม (intake.pay) — วางไว้ใน <style> ของหน้าที่เรียก ไม่มีแท็กของตัวเอง
--}}
        /*
            ช่วง "จ่ายแล้ว กำลังรอผล" — ช่วงนี้เคยเป็นหน้าจอนิ่งสนิทที่มีแต่อีโมจิ ⏳
            คนที่เพิ่งกดโอนเงินหลักพันแล้วเห็นหน้าจอไม่ขยับจะสรุปเองว่าจ่ายไม่ผ่านแล้ว
            กดจ่ายซ้ำ ทุกอย่างใต้บรรทัดนี้จึงมีหน้าที่เดียว: ทำให้เห็นว่าระบบยังทำงานอยู่
        */
        .settle { text-align: center; }
        .settle-orb { position: relative; width: 92px; height: 92px; margin: 4px auto 0; }
        .settle-orb span { position: absolute; border-radius: 50%; }
        .settle-wave { inset: 0; background: rgba(5,150,105,.12); animation: settle-wave 2s cubic-bezier(0,0,.2,1) infinite; }
        .settle-wave.delayed { inset: 8px; animation-delay: 1s; }
        .settle-spin { inset: 10px; border: 3px solid #d1fae5; border-top-color: #059669; animation: settle-spin .9s linear infinite; }
        .settle-face { inset: 0; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .settle-title { font-size: 18px; font-weight: 700; margin-top: 14px; }
        .settle-sub { font-size: 13.5px; color: #64748b; margin-top: 4px; }
        .settle-steps { list-style: none; margin: 18px 0 0; text-align: left; }
        .settle-steps li { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 12px; border: 1px solid #f1f5f9; background: #f8fafc; font-size: 13.5px; font-weight: 600; color: #334155; }
        .settle-steps li + li { margin-top: 8px; }
        .settle-steps li.done { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .settle-steps li.todo { color: #94a3b8; }
        .settle-dot { width: 22px; height: 22px; border-radius: 50%; flex: 0 0 22px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .settle-dot.done { background: #059669; color: #fff; }
        .settle-dot.now { border: 3px solid #d1fae5; border-top-color: #059669; animation: settle-spin .9s linear infinite; }
        .settle-dot.todo { background: #e2e8f0; }
        .settle-elapsed { font-size: 12.5px; color: #94a3b8; font-weight: 700; margin-top: 16px; }
        @keyframes settle-spin { to { transform: rotate(360deg); } }
        @keyframes settle-wave {
            0% { transform: scale(.85); opacity: .9; }
            80%, 100% { transform: scale(1.35); opacity: 0; }
        }
        /* ปิดแอนิเมชันให้คนที่ตั้งค่าเครื่องไว้ — ตัวนับวินาทียังบอกได้ว่าระบบทำงานอยู่ */
        @media (prefers-reduced-motion: reduce) {
            .settle-wave { animation: none; opacity: .3; }
            .settle-spin, .settle-dot.now { animation-duration: 3s; }
        }

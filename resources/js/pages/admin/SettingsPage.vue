<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">tune</span> ตั้งค่าระบบ</h1>
        <p class="page-subtitle">ตัวเลขที่เคยต้องแก้ในโค้ด ปรับได้จากที่นี่ มีผลทันทีทั่วทั้งเว็บและแอป</p>
      </div>
      <button class="btn-primary" :disabled="saving || loading" @click="save">
        <span class="material-symbols-rounded">{{ saving ? 'hourglass_top' : 'save' }}</span>
        {{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
      </button>
    </div>

    <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

    <div v-else class="settings-grid">
      <!-- ── เกณฑ์ที่นั่ง ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">event_seat</span>
          <div>
            <h2>เกณฑ์ที่นั่งของรอบเดินทาง</h2>
            <p>กำหนดว่ารอบไหน "การันตีออก" และรอบไหนควรเร่งการจอง</p>
          </div>
        </div>

        <div class="field">
          <label>ที่นั่งขั้นต่ำที่การันตีออกเดินทาง</label>
          <div class="input-row">
            <input v-model.number="form.guarantee_min_seats" type="number" min="1" max="50" />
            <span class="unit">ที่นั่ง</span>
            <button v-if="isChanged('guarantee_min_seats')" class="reset-link" @click="reset('guarantee_min_seats')">
              คืนค่าเดิม ({{ defaults.guarantee_min_seats }})
            </button>
          </div>
          <span class="help">
            จองครบเท่านี้ = ป้าย 🟢 การันตีออกเดินทาง และมีผลกับข้อความ "ขาดอีกกี่ที่นั่ง" ทุกที่ในระบบ
          </span>
        </div>

        <div class="field">
          <label>เหลือกี่ที่นั่งจึงแจ้งเตือน "ใกล้เต็ม"</label>
          <div class="input-row">
            <input v-model.number="form.low_seat_threshold" type="number" min="1" max="20" />
            <span class="unit">ที่นั่ง</span>
            <button v-if="isChanged('low_seat_threshold')" class="reset-link" @click="reset('low_seat_threshold')">
              คืนค่าเดิม ({{ defaults.low_seat_threshold }})
            </button>
          </div>
          <span class="help">ระบบยิง push เร่งการจองเมื่อที่นั่งเหลือเท่านี้หรือน้อยกว่า</span>
        </div>

        <div class="field">
          <label>รอบที่จองน้อยกว่านี้ = เสี่ยงไม่ออก</label>
          <div class="input-row">
            <input v-model.number="form.underfilled_min_seats" type="number" min="1" max="50" />
            <span class="unit">ที่นั่ง</span>
            <button v-if="isChanged('underfilled_min_seats')" class="reset-link" @click="reset('underfilled_min_seats')">
              คืนค่าเดิม ({{ defaults.underfilled_min_seats }})
            </button>
          </div>
          <span class="help">ใช้ส่งอีเมลเตือนลูกค้าล่วงหน้า 7 วันก่อนเดินทางเมื่อคนยังไม่ครบ</span>
        </div>
      </section>

      <!-- ── คิวรอที่นั่ง ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">hourglass_top</span>
          <div>
            <h2>คิวรอที่นั่ง</h2>
            <p>เมื่อมีคนยกเลิก ที่นั่งจะถูกกันไว้ให้คนแรกในคิวก่อน</p>
          </div>
        </div>

        <div class="field">
          <label>เวลาจองหลังได้รับสิทธิ์</label>
          <div class="input-row">
            <input v-model.number="form.waitlist_offer_ttl_minutes" type="number" min="5" max="180" />
            <span class="unit">นาที</span>
            <button
              v-if="isChanged('waitlist_offer_ttl_minutes')"
              class="reset-link"
              @click="reset('waitlist_offer_ttl_minutes')"
            >
              คืนค่าเดิม ({{ defaults.waitlist_offer_ttl_minutes }})
            </button>
          </div>
          <span class="help">
            ช่วงเวลานี้คนอื่นจองที่นั่งนั้นไม่ได้ (เห็นข้อความ "ถูกกันไว้ให้ผู้ที่รอคิวก่อนหน้า")
            หมดเวลาแล้วที่นั่งตกถึงคนถัดไปในคิวอัตโนมัติ
            <br />ตั้งได้ 5–180 นาที · มีผลกับสิทธิ์ที่แจกหลังกดบันทึกเท่านั้น สิทธิ์ที่แจกไปแล้วยังนับตามเวลาเดิม
          </span>
        </div>
      </section>

      <!-- ── ช่วงเวลางดรบกวน ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">bedtime</span>
          <div>
            <h2>ช่วงเวลางดรบกวน</h2>
            <p>กันไม่ให้ push การตลาดไปปลุกลูกค้าตอนดึก</p>
          </div>
        </div>

        <label class="switch-row">
          <input type="checkbox" v-model="form.quiet_hours_enabled" />
          <span class="switch"></span>
          <span>เปิดใช้ช่วงเวลางดรบกวน</span>
        </label>

        <div class="field" :class="{ disabled: !form.quiet_hours_enabled }">
          <label>ช่วงเวลาที่งดส่ง</label>
          <div class="input-row">
            <input v-model.number="form.quiet_start_hour" type="number" min="0" max="23" :disabled="!form.quiet_hours_enabled" />
            <span class="unit">น. ถึง</span>
            <input v-model.number="form.quiet_end_hour" type="number" min="0" max="23" :disabled="!form.quiet_hours_enabled" />
            <span class="unit">น.</span>
          </div>
          <span class="help">
            ข้อความที่ตกในช่วงนี้จะถูกพักไว้ส่งตอน {{ form.quiet_end_hour }}:00 น. แทน
            — ยกเว้นเรื่องเร่งด่วน (Flash Sale, ที่นั่งใกล้เต็ม, ที่นั่งเต็ม)
            และข้อความที่ทีมงานกดส่งเอง ซึ่งไปถึงทันทีเสมอ
          </span>
        </div>
      </section>

      <!-- ── SOS ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">sos</span>
          <div>
            <h2>สัญญาณ SOS</h2>
            <p>ช่องทางแจ้งเตือนตอนลูกค้ากดขอความช่วยเหลือ</p>
          </div>
        </div>

        <label class="switch-row">
          <input type="checkbox" v-model="form.sos_sms_enabled" />
          <span class="switch"></span>
          <span>ส่ง SMS หาสตาฟ คนขับ และทีมออฟฟิศ</span>
        </label>

        <span class="help">
          push กับอีเมลไปถึงเฉพาะเครื่องที่ต่อเน็ตอยู่ ซึ่งบนดอยคือเครื่องที่ไม่มี
          — SMS วิ่งบนช่องสัญญาณเสียงจึงไปถึงตรงที่แอปไปไม่ถึง
          มีค่าใช้จ่ายต่อข้อความ ปิดได้เมื่อเครดิตหมดโดยไม่กระทบ push/อีเมล
        </span>
      </section>

      <!-- ── บัญชีทริป ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">savings</span>
          <div>
            <h2>บัญชีทริป (โหมดเข้มงวด)</h2>
            <p>กติกาที่ทำให้ตัวเลขกำไรในหน้า "บัญชีทริป" เชื่อถือได้</p>
          </div>
        </div>

        <label class="switch-row">
          <input type="checkbox" v-model="form.finance_strict_mode" />
          <span class="switch"></span>
          <span>เปิดโหมดเข้มงวด</span>
        </label>
        <span class="help">
          ปิดสวิตช์นี้แล้วข้อบังคับทั้งหมดด้านล่างหยุดทำงาน เหลือเป็นสมุดจดแบบเดิม
          — การล็อกรอบที่ปิดงบแล้วยังทำงานเสมอไม่ว่าสวิตช์นี้จะเปิดหรือปิด
        </span>

        <div class="field" :class="{ disabled: !form.finance_strict_mode }">
          <label>รายจ่ายเกินกี่บาทต้องแนบสลิป</label>
          <div class="input-row">
            <input v-model.number="form.finance_slip_required_above" type="number" min="0" step="100" :disabled="!form.finance_strict_mode" />
            <span class="unit">บาท</span>
            <button v-if="isChanged('finance_slip_required_above')" class="reset-link" @click="reset('finance_slip_required_above')">
              คืนค่าเดิม ({{ defaults.finance_slip_required_above }})
            </button>
          </div>
          <span class="help">
            เกินยอดนี้แล้วไม่มีสลิป = บันทึกไม่ได้ และรายการเก่าที่ไม่มีสลิปจะกันไม่ให้ปิดงบรอบนั้น
            (0 = ไม่บังคับ) — บังคับเฉพาะฝั่งรายจ่าย เงินที่รับเข้ามาไม่ต้องมีใบเสร็จ
          </span>
        </div>

        <label class="switch-row">
          <input type="checkbox" v-model="form.finance_require_category" :disabled="!form.finance_strict_mode" />
          <span class="switch"></span>
          <span>ทุกรายการต้องระบุหมวด</span>
        </label>

        <label class="switch-row">
          <input type="checkbox" v-model="form.finance_close_requires_expense" :disabled="!form.finance_strict_mode" />
          <span class="switch"></span>
          <span>ปิดงบไม่ได้ถ้ายังไม่มีรายจ่ายสักรายการ</span>
        </label>
        <span class="help">รอบที่ออกทริปไปแล้วแต่ไม่มีใครคีย์ค่าใช้จ่าย จะโชว์กำไร 100% ซึ่งไม่จริง</span>

        <label class="switch-row">
          <input type="checkbox" v-model="form.finance_close_requires_settled" :disabled="!form.finance_strict_mode" />
          <span class="switch"></span>
          <span>ปิดงบไม่ได้ถ้ายังมีลูกค้าค้างชำระ</span>
        </label>

        <div class="field">
          <label>ทริปจบแล้วกี่วันต้องปิดงบให้เสร็จ</label>
          <div class="input-row">
            <input v-model.number="form.finance_close_grace_days" type="number" min="1" max="90" />
            <span class="unit">วัน</span>
            <button v-if="isChanged('finance_close_grace_days')" class="reset-link" @click="reset('finance_close_grace_days')">
              คืนค่าเดิม ({{ defaults.finance_close_grace_days }})
            </button>
          </div>
          <span class="help">
            เลยกำหนดแล้วรอบจะขึ้นเป็น "ค้างปิดงบ" บนเมนู ในคิวงาน และมีอีเมลเตือนทีมงานทุกเช้า 09:15
          </span>
        </div>

        <label class="switch-row">
          <input type="checkbox" v-model="form.finance_block_new_rounds" :disabled="!form.finance_strict_mode" />
          <span class="switch"></span>
          <span>ทริปที่ค้างปิดงบ เปิดรอบใหม่ไม่ได้</span>
        </label>
        <span class="help">
          ตัวบังคับที่แรงที่สุด — กันการปล่อยรอบเก่าค้างไว้แล้วเปิดรอบใหม่ไปเรื่อย ๆ
          ปิดไว้ตามค่าตั้งต้น เพราะมันไปหยุดฝั่งขาย รอบที่ค้างยังขึ้นเตือนบนเมนู
          ในคิวงาน และในอีเมลทุกเช้าอยู่ดี
        </span>
      </section>

      <!-- ── ข้อมูลติดต่อ ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">contact_support</span>
          <div>
            <h2>ข้อมูลติดต่อที่แสดงให้ลูกค้า</h2>
            <p>เว้นว่างไว้ = ใช้ค่าที่ตั้งไว้ตอนติดตั้งระบบ</p>
          </div>
        </div>

        <div class="field">
          <label>เบอร์โทรติดต่อ</label>
          <input v-model="form.support_phone" class="wide" placeholder="เช่น 062-612-6006" />
        </div>
        <div class="field">
          <label>LINE ID</label>
          <input v-model="form.support_line" class="wide" placeholder="เช่น @luilaykhao" />
        </div>
        <div class="field">
          <label>อีเมล</label>
          <input v-model="form.support_email" class="wide" type="email" placeholder="เช่น hello@luilaykhao.com" />
        </div>
      </section>

      <!-- ── ใบอนุญาตนำเที่ยว ── -->
      <section class="setting-card">
        <div class="card-head">
          <span class="material-symbols-rounded">verified_user</span>
          <div>
            <h2>ใบอนุญาตนำเที่ยว</h2>
            <p>เลขที่และรูปใบจริงที่แสดงบนเว็บ ในแอป และใน structured data ของ Google</p>
          </div>
        </div>

        <div class="field">
          <label>เลขที่ใบอนุญาต</label>
          <div class="input-row">
            <input v-model="form.licence_no" placeholder="เช่น 11/13855" />
            <button v-if="isChanged('licence_no')" class="reset-link" @click="reset('licence_no')">
              คืนค่าเดิม ({{ defaults.licence_no }})
            </button>
          </div>
          <span class="help">
            เลขขึ้นต้นด้วย 11 = นำเที่ยวได้ทั้งในและต่างประเทศ · 12 = เฉพาะในประเทศ
          </span>
        </div>

        <div class="field">
          <label>รูปใบอนุญาต</label>

          <div class="licence-preview">
            <img v-if="licenceImageUrl" :src="licenceImageUrl" alt="ใบอนุญาตนำเที่ยว" />
            <div v-else class="licence-empty">
              <span class="material-symbols-rounded">image_not_supported</span>
              ยังไม่มีรูป
            </div>
          </div>

          <div class="licence-actions">
            <label class="upload-btn" :class="{ 'is-busy': uploading }">
              <span class="material-symbols-rounded">{{ uploading ? 'hourglass_top' : 'upload' }}</span>
              {{ uploading ? `กำลังอัปโหลด ${uploadPercent}%` : 'เลือกรูปใบอนุญาต' }}
              <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="uploading" @change="onPickLicence" />
            </label>
            <button v-if="form.licence_image" class="reset-link" :disabled="uploading" @click="clearLicenceImage">
              กลับไปใช้รูปเดิม
            </button>
          </div>

          <span class="help">
            รูปที่ลูกค้ากดดูได้จากแถบ "ผู้ประกอบการนำเที่ยวจดทะเบียน" ในแอป —
            ถ่ายให้อ่านเลขที่และวันหมดอายุออก ไฟล์ JPG/PNG/WebP ไม่เกิน 8 MB
            <br />อัปโหลดแล้วต้องกด "บันทึกการตั้งค่า" ถึงจะมีผล
          </span>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { uploadMedia } from '../../lib/mediaUpload';
import './admin-shared.css';

const toast = useToast();

const form = reactive({
  guarantee_min_seats: 8,
  low_seat_threshold: 3,
  underfilled_min_seats: 8,
  waitlist_offer_ttl_minutes: 15,
  quiet_hours_enabled: true,
  quiet_start_hour: 21,
  quiet_end_hour: 8,
  sos_sms_enabled: true,
  finance_strict_mode: true,
  finance_slip_required_above: 1000,
  finance_require_category: true,
  finance_close_requires_expense: true,
  finance_close_requires_settled: true,
  finance_close_grace_days: 7,
  finance_block_new_rounds: false,
  support_phone: '',
  support_line: '',
  support_email: '',
  licence_no: '',
  licence_image: '',
});

const defaults = ref({});
const loading = ref(false);
const saving = ref(false);

// URL ที่ลูกค้าเห็นอยู่จริง — ต่างจาก form.licence_image ตรงที่เผื่อกรณียังไม่เคย
// อัปโหลดแล้วระบบถอยไปใช้ไฟล์เดิม แอดมินจะได้เห็นรูปที่ใช้อยู่ ไม่ใช่กรอบว่าง
const licenceImageUrl = ref('');
const uploading = ref(false);
const uploadPercent = ref(0);

/** 8 MB — รูปใบอนุญาตคือภาพถ่ายเอกสารหน้าเดียว ใหญ่กว่านี้แปลว่าถ่ายเกินจำเป็น */
const MAX_LICENCE_BYTES = 8 * 1024 * 1024;

async function onPickLicence(event) {
  const file = event.target.files?.[0];
  // เคลียร์ input ทันทีเพื่อให้เลือกไฟล์เดิมซ้ำได้หลังอัปโหลดพลาด
  event.target.value = '';
  if (!file) return;

  if (file.size > MAX_LICENCE_BYTES) {
    toast.error('ไฟล์ใหญ่เกิน 8 MB กรุณาย่อรูปก่อนอัปโหลด');
    return;
  }

  uploading.value = true;
  uploadPercent.value = 0;
  try {
    const url = await uploadMedia(file, (loaded, total) => {
      uploadPercent.value = total ? Math.round((loaded / total) * 100) : 0;
    });
    form.licence_image = url;
    licenceImageUrl.value = url;
    toast.success('อัปโหลดรูปแล้ว — กด "บันทึกการตั้งค่า" เพื่อให้มีผล');
  } catch (e) {
    toast.error(e.response?.data?.message || e.message || 'อัปโหลดไม่สำเร็จ');
  } finally {
    uploading.value = false;
  }
}

/**
 * ล้างค่าที่เก็บไว้ ไม่ได้ลบไฟล์บน R2
 *
 * ตั้งใจ — ถ้าเผลอกดแล้วอยากได้รูปเดิมคืน ยังกู้จาก R2 ได้ และการลบไฟล์ที่หน้าอื่น
 * อาจอ้างอยู่คือความเสียหายที่ย้อนไม่ได้
 */
function clearLicenceImage() {
  form.licence_image = '';
  licenceImageUrl.value = '';
  toast.info('จะกลับไปใช้รูปเดิมเมื่อกดบันทึก');
}

function isChanged(key) {
  return form[key] !== defaults.value[key];
}

function reset(key) {
  form[key] = defaults.value[key];
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/settings/site');
    defaults.value = res.data.data.defaults || {};
    Object.assign(form, res.data.data.settings || {});
    licenceImageUrl.value = res.data.data.licence_image_url || '';
    // ช่องข้อความว่างมาเป็น null จาก API — แปลงเป็นสตริงว่างให้ input ผูกค่าได้
    ['support_phone', 'support_line', 'support_email', 'licence_image'].forEach((k) => {
      form[k] = form[k] ?? '';
    });
  } catch {
    toast.error('โหลดการตั้งค่าไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const res = await api.put('/admin/settings/site', {
      ...form,
      support_phone: form.support_phone?.trim() || null,
      support_line: form.support_line?.trim() || null,
      support_email: form.support_email?.trim() || null,
      licence_no: form.licence_no?.trim(),
      licence_image: form.licence_image?.trim() || null,
    });
    // เซิร์ฟเวอร์คืน URL ที่ใช้จริงหลังบันทึก — รวมกรณีถอยไปใช้รูปเดิม
    licenceImageUrl.value = res.data.data?.licence_image_url || '';
    toast.success('บันทึกการตั้งค่าแล้ว — มีผลทันที');
  } catch (e) {
    const errors = e.response?.data?.errors;
    toast.error(
      errors ? Object.values(errors).flat()[0] : (e.response?.data?.message || 'บันทึกไม่สำเร็จ'),
    );
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); gap: 18px; align-items: start; }

.setting-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px; }

.licence-preview {
  margin: 4px 0 12px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #f9fafb;
  overflow: hidden;
}
.licence-preview img { display: block; width: 100%; max-height: 320px; object-fit: contain; }
.licence-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
  height: 160px; color: #9ca3af; font-size: 13px; font-weight: 600;
}
.licence-empty .material-symbols-rounded { font-size: 32px; }

.licence-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }

.upload-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 9px 16px; border-radius: 10px; cursor: pointer;
  border: 1px solid #d1d5db; background: #fff;
  font-size: 13px; font-weight: 700; color: #374151;
  transition: background .15s, border-color .15s;
}
.upload-btn:hover { background: #f9fafb; border-color: #9ca3af; }
.upload-btn.is-busy { cursor: progress; opacity: .7; }
.upload-btn input[type="file"] { display: none; }
.card-head { display: flex; gap: 13px; padding-bottom: 16px; border-bottom: 1px solid #f3f4f6; }
.card-head .material-symbols-rounded { font-size: 26px !important; color: var(--color-accent); }
.card-head h2 { margin: 0; font-size: 15.5px; font-weight: 700; color: #111827; }
.card-head p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }

.field { margin-top: 18px; }
.field.disabled { opacity: 0.5; }
.field label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 7px; }

.input-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.input-row input {
  width: 88px; padding: 9px 13px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 14px; font-weight: 600; color: #1f2937; text-align: center; font-family: inherit;
}
.field input.wide {
  width: 100%; padding: 9px 13px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 14px; color: #1f2937; font-family: inherit;
}
.input-row input:focus, .field input.wide:focus { outline: none; border-color: var(--color-accent); }
.unit { font-size: 13px; color: #6b7280; }
.reset-link {
  background: none; border: none; cursor: pointer; margin-left: auto;
  font-size: 12px; color: #2563eb; text-decoration: underline;
}
.help { display: block; margin-top: 7px; font-size: 12px; color: #9ca3af; line-height: 1.6; }

.switch-row { display: flex; align-items: center; gap: 10px; margin-top: 18px; cursor: pointer; font-size: 13.5px; color: #374151; }
.switch-row input { display: none; }
.switch {
  width: 40px; height: 22px; border-radius: 999px; background: #d1d5db;
  position: relative; transition: background 0.2s; flex-shrink: 0;
}
.switch::after {
  content: ''; position: absolute; top: 3px; left: 3px;
  width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.2s;
}
.switch-row input:checked + .switch { background: var(--color-accent); }
.switch-row input:checked + .switch::after { transform: translateX(18px); }
</style>

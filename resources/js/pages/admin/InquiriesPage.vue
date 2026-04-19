<template>
  <div class="inquiries-page">
    <div class="page-header">
      <h1 class="page-title">
        <span class="material-symbols-rounded heading-icon">mail</span>
        ข้อความติดต่อจากลูกค้า
      </h1>
      <p class="page-subtitle">จัดการข้อความสอบถามและพาร์ทเนอร์</p>
    </div>

    <div class="table-container bg-white rounded-2xl border border-sand-dark shadow-sm">
      <div v-if="admin.loading" class="p-12 text-center">
        <div class="spinner mx-auto mb-4"></div>
        <p class="text-text-muted">กำลังโหลดข้อความ...</p>
      </div>

      <template v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>สถานะ</th>
              <th>หัวข้อ</th>
              <th>ผู้ติดต่อ</th>
              <th>วันที่</th>
              <th>จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in contacts" :key="item.id" @click="viewDetail(item)" class="cursor-pointer">
              <td>
                <span v-if="!item.read_at" class="status-badge status-new">ใหม่</span>
                <span v-else class="status-badge status-read">อ่านแล้ว</span>
              </td>
              <td>
                <div class="font-bold text-text-dark">{{ subjectLabel(item.subject) }}</div>
                <div class="text-xs text-text-muted truncate max-w-xs">{{ item.message }}</div>
              </td>
              <td>
                <div class="font-bold">{{ item.name }}</div>
                <div class="text-xs text-text-muted">{{ item.phone }}</div>
              </td>
              <td class="text-sm">{{ formatDate(item.created_at) }}</td>
              <td>
                <div class="flex gap-2">
                  <button @click.stop="viewDetail(item)" class="action-btn view">
                    <span class="material-symbols-rounded">visibility</span>
                  </button>
                  <button @click.stop="confirmDelete(item)" class="action-btn delete">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!contacts.length">
              <td colspan="5" class="empty-state">ไม่มีข้อความติดต่อ</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <!-- Detail Modal -->
    <div v-if="selectedItem" class="modal-overlay" @click.self="selectedItem = null">
      <div class="modal-content animate-in fade-in zoom-in duration-300">
        <div class="modal-header">
          <h3>
            <span class="material-symbols-rounded">info</span>
            รายละเอียดข้อความ
          </h3>
          <button @click="selectedItem = null" class="close-btn">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body space-y-6">
          <div class="grid grid-cols-2 gap-6">
            <div class="detail-group">
              <label>ผู้ติดต่อ</label>
              <div class="value">{{ selectedItem.name }}</div>
            </div>
            <div class="detail-group">
              <label>เบอร์โทรศัพท์</label>
              <div class="value">{{ selectedItem.phone }}</div>
            </div>
            <div class="detail-group">
              <label>อีเมล</label>
              <div class="value">{{ selectedItem.email || '-' }}</div>
            </div>
            <div class="detail-group">
              <label>วันที่ส่ง</label>
              <div class="value">{{ formatFullDate(selectedItem.created_at) }}</div>
            </div>
          </div>

          <div class="detail-group">
            <label>หัวข้อ</label>
            <div class="value font-bold text-primary">{{ subjectLabel(selectedItem.subject) }}</div>
          </div>

          <!-- Partnership Details -->
          <div v-if="selectedItem.subject === 'partnership'" class="partnership-box p-4 bg-primary/5 rounded-xl border border-primary/20">
            <h4 class="text-sm font-bold text-primary mb-3 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">handshake</span>
              ข้อมูลพาร์ทเนอร์
            </h4>
            
            <div class="space-y-4">
              <div class="detail-group">
                <label>ประเภท</label>
                <div class="value">{{ partnerLabel(selectedItem.partner_type) }}</div>
              </div>

              <div v-if="selectedItem.van_description" class="detail-group">
                <label>รายละเอียดรถ</label>
                <div class="value bg-white p-3 rounded-lg text-sm">{{ selectedItem.van_description }}</div>
              </div>

              <div v-if="selectedItem.images?.length" class="detail-group">
                <label>รูปภาพแนบ ({{ selectedItem.images.length }} รูป)</label>
                <div class="grid grid-cols-3 gap-2 mt-2">
                  <a v-for="(img, idx) in selectedItem.images" :key="idx" :href="img" target="_blank" class="block aspect-square rounded-lg overflow-hidden border border-sand-dark">
                    <img :src="img" class="w-full h-full object-cover hover:scale-110 transition-transform" />
                  </a>
                </div>
              </div>
            </div>
          </div>

          <div class="detail-group">
            <label>ข้อความ</label>
            <div class="value message-text">{{ selectedItem.message }}</div>
          </div>
        </div>

        <div class="modal-footer">
          <button v-if="!selectedItem.read_at" @click="markAsRead(selectedItem)" class="btn-primary">
            <span class="material-symbols-rounded">check_circle</span>
            ทำเป็นอ่านแล้ว
          </button>
          <button @click="selectedItem = null" class="btn-outline">ปิด</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAdminStore } from '../../stores/admin'

const admin = useAdminStore()
const contacts = computed(() => admin.contacts.data || [])
const selectedItem = ref(null)

const subjectLabel = (value) => {
  const labels = {
    booking: 'สอบถามการจอง',
    payment: 'ปัญหาการชำระเงิน',
    partnership: 'ร่วมเป็นพาร์ทเนอร์',
    general: 'สอบถามทั่วไป',
    other: 'อื่นๆ'
  }
  return labels[value] || value
}

const partnerLabel = (value) => {
  const labels = {
    trekking_staff: 'สตาฟเดินป่า',
    shared_van: 'รถตู้ร่วมบริการ'
  }
  return labels[value] || value
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

const formatFullDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleString('th-TH', { 
    day: 'numeric', month: 'long', year: 'numeric', 
    hour: '2-digit', minute: '2-digit' 
  })
}

const viewDetail = async (item) => {
  selectedItem.value = item
}

const markAsRead = async (item) => {
  try {
    await admin.markContactAsRead(item.id)
    item.read_at = new Date()
    selectedItem.value = null
  } catch (e) {
    alert('เกิดข้อผิดพลาด')
  }
}

const confirmDelete = async (item) => {
  if (confirm('ยืนยันการลบข้อความนี้?')) {
    try {
      await admin.deleteContact(item.id)
      await admin.fetchContacts()
    } catch (e) {
      alert('เกิดข้อผิดพลาด')
    }
  }
}

onMounted(() => {
  admin.fetchContacts()
})
</script>

<style scoped>
@import url('./admin-shared.css');

.inquiries-page {
  animation: fadeIn 0.3s ease;
}

.status-new { background: #fee2e2; color: #dc2626; }
.status-read { background: #f3f4f6; color: #6b7280; }

.action-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-sand-dark);
  background: white;
  transition: all 0.2s;
}

.action-btn.view:hover { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.action-btn.delete:hover { background: #ef4444; color: white; border-color: #ef4444; }

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}

.modal-content {
  background: white;
  width: 100%;
  max-width: 600px;
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}

.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--color-sand-dark);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-header h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--color-text-dark);
}

.modal-body {
  padding: 24px;
}

.detail-group label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 4px;
}

.detail-group .value {
  font-size: 15px;
  color: var(--color-text-dark);
}

.message-text {
  background: var(--color-sand);
  padding: 16px;
  border-radius: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
}

.modal-footer {
  padding: 20px 24px;
  border-top: 1px solid var(--color-sand-dark);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn-primary {
  background: var(--color-primary);
  color: white;
  padding: 10px 20px;
  border-radius: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}

.btn-outline {
  border: 1px solid var(--color-sand-dark);
  padding: 10px 20px;
  border-radius: 10px;
  font-weight: 600;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid var(--color-primary);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>

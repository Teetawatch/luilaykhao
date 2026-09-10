<!-- คนขับ/สตาฟหนึ่งแถวในชีตข้อมูลทริป — ชื่อ รูป และปุ่มโทรเมื่อมีเบอร์ -->
<template>
  <div class="flex items-center gap-3 p-3 rounded-[14px] bg-[#F4F7F6]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
    <img v-if="photo" :src="photo" :alt="name" class="w-10 h-10 rounded-full object-cover shrink-0" />
    <div v-else class="w-10 h-10 rounded-full bg-[#E8EEEF] flex items-center justify-center shrink-0">
      <span class="material-symbols-rounded pr-icon pr-icon-avatar text-[#A0B0B0]">person</span>
    </div>

    <div class="flex-1 min-w-0">
      <p class="text-[13.5px] font-bold text-[#1a1c1c] truncate">{{ name }}</p>
      <p v-if="phone" class="text-[12px] text-[#889696]">{{ phone }}</p>
    </div>

    <a
      v-if="phone"
      :href="`tel:${phone}`"
      class="w-9 h-9 rounded-[10px] bg-[#006565] flex items-center justify-center shrink-0"
      :aria-label="`โทรหา ${name}`"
    >
      <span class="material-symbols-rounded pr-icon pr-icon-call text-white">call</span>
    </a>
  </div>
</template>

<script setup>
defineProps({
  name: { type: String, required: true },
  phone: { type: String, default: null },
  photo: { type: String, default: null },
});
</script>

<style scoped>
/* ดู TripChatPage.vue — ขนาดไอคอนต้องชนะกฎ 24px ที่อยู่นอก cascade layer ใน app.css */
.material-symbols-rounded.pr-icon-avatar { font-size: 20px; }
.material-symbols-rounded.pr-icon-call { font-size: 18px; }
</style>

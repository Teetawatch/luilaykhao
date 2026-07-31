<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8 relative">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          แต้มสะสม
        </h1>
        <p class="text-[#505E5E] text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          สะสมแต้มจากการจองและแลกรับของรางวัลสุดคุ้ม
        </p>
      </section>

      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm" style="font-family: 'DB Heavent', 'Anuphan', sans-serif;">กำลังโหลดข้อมูล...</p>
      </div>

      <template v-else>
        <!-- Account Card -->
        <div class="relative rounded-[24px] overflow-hidden mb-8 text-white transition-colors"
          :class="tierBg">
          <div class="absolute inset-0 opacity-5" style="background:repeating-linear-gradient(45deg,#fff 0,#fff 1px,transparent 0,transparent 50%);background-size:20px 20px;"></div>
          <div class="relative p-6 sm:p-8">
            <div class="flex justify-between items-start mb-6">
              <div>
                <p class="text-white/80 text-[13px] font-bold uppercase tracking-wide mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ระดับสมาชิก</p>
                <p class="text-3xl font-bold" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ account?.tier_label }}</p>
                <p v-if="account?.tier_tagline" class="text-white/75 text-sm mt-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ account.tier_tagline }}</p>
              </div>
              <span class="material-symbols-rounded text-[48px] opacity-90" style="font-variation-settings:'FILL' 1;">landscape</span>
            </div>
            
            <div class="flex flex-wrap gap-8 mb-4">
              <div>
                <p class="text-white/80 text-[13px] font-bold mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">แต้มคงเหลือ</p>
                <p class="text-4xl font-extrabold tracking-tight" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ account?.points?.toLocaleString() }}</p>
                <p v-if="account?.expiring_points" class="text-[12px] font-bold text-white/90 mt-1.5 flex items-center gap-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  <span class="material-symbols-rounded text-[14px]">schedule</span>
                  {{ account.expiring_points.toLocaleString() }} แต้มหมดอายุ {{ thaiShort(account.expiring_at) }}
                </p>
              </div>
              <div>
                <p class="text-white/80 text-[13px] font-bold mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">แต้มสะสมตลอดกาล</p>
                <p class="text-2xl font-bold mt-2 tracking-tight" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ account?.lifetime_points?.toLocaleString() }}</p>
              </div>
              <div>
                <p class="text-white/80 text-[13px] font-bold mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ไปด้วยกันมาแล้ว</p>
                <p class="text-2xl font-bold mt-2 tracking-tight" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ (account?.lifetime_trips ?? 0).toLocaleString() }} ทริป</p>
              </div>
            </div>

            <!-- Progress to next tier -->
            <div v-if="account?.next_tier" class="mt-6 bg-black/10 rounded-[16px] p-4 backdrop-blur-sm">
              <div class="flex justify-between text-[13px] font-bold text-white mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                <span>{{ account.tier_label }}</span>
                <span>{{ account.next_tier.label }} ({{ account.next_tier.at.toLocaleString() }} ทริป)</span>
              </div>
              <div class="h-2.5 bg-black/20 rounded-full overflow-hidden">
                <div
                  class="h-full bg-white rounded-full transition-all duration-1000 ease-out"
                  :style="{ width: tierProgress + '%' }"></div>
              </div>
              <p class="text-[12px] text-white/90 mt-2 font-medium" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                ไปด้วยกันอีก {{ account.next_tier.trips_needed.toLocaleString() }} ทริป เพื่อเลื่อนระดับเป็น {{ account.next_tier.label }}
              </p>
            </div>
            <div v-else class="mt-6">
              <span class="text-[13px] font-bold bg-white/20 rounded-[12px] px-3.5 py-1.5 flex items-center w-fit gap-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">verified</span> ระดับสูงสุด
              </span>
            </div>
          </div>
        </div>

        <!-- สิทธิ์ที่ได้ตอนนี้ + บันไดระดับ — ทั้งชื่อและเกณฑ์มาจาก API ทั้งหมด
             เพื่อให้เว็บกับแอปพูดตรงกันเสมอ -->
        <section v-if="account?.perks?.length" class="mb-8 bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">สิทธิ์ที่คุณได้ตอนนี้</h2>
          <ul class="grid sm:grid-cols-2 gap-2.5">
            <li v-for="perk in account.perks" :key="perk.key" class="flex items-start gap-2.5 text-sm font-medium text-[#1a1c1c]">
              <span class="material-symbols-rounded text-[18px] text-[#0F6B5C] mt-0.5 shrink-0" style="font-variation-settings:'FILL' 1">check_circle</span>
              {{ perk.label }}
            </li>
          </ul>
        </section>

        <section v-if="account?.tiers?.length" class="mb-8 bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden">
          <h2 class="text-[13px] font-bold text-[#505E5E] px-5 sm:px-6 pt-5 pb-3">ระดับสมาชิกทั้งหมด</h2>
          <div v-for="step in account.tiers" :key="step.code"
            class="px-5 sm:px-6 py-4 border-t border-[#F0F4F4]"
            :class="step.code === account.tier ? 'bg-[#F4F7F6]' : ''">
            <div class="flex items-center justify-between gap-3 mb-1">
              <div class="flex items-center gap-2 min-w-0">
                <span class="font-bold text-[#1a1c1c]">{{ step.label }}</span>
                <TierBadge v-if="step.code === account.tier" :tier="step.code" label="ระดับของคุณ" size="sm" />
              </div>
              <span class="text-xs font-bold text-[#889696] shrink-0">
                {{ step.min_trips === 0 ? 'เริ่มต้น' : `${step.min_trips.toLocaleString()} ทริป` }}
              </span>
            </div>
            <p class="text-xs text-[#505E5E] mb-2">{{ step.tagline }}</p>
            <p v-if="step.perks.length" class="text-xs text-[#505E5E]">
              {{ step.perks.map(p => p.label).join(' · ') }}
            </p>
            <p v-else class="text-xs text-[#889696]">เริ่มสะสมแต้มจากทริปแรกได้เลย</p>
          </div>
        </section>

        <!-- Tabs -->
        <div class="flex gap-2 mb-8 bg-[#E8EEEF] p-1.5 rounded-[16px] w-fit">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            @click="activeTab = tab.key"
            class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
            :class="activeTab === tab.key ? 'bg-white text-[#006565]' : 'text-[#505E5E] hover:text-[#006565] hover:bg-white/40'"
            style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            <span class="material-symbols-rounded text-[20px]" :style="activeTab === tab.key ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">
              {{ tab.key === 'rewards' ? 'card_giftcard' : (tab.key === 'coupons' ? 'local_play' : 'history') }}
            </span>
            {{ tab.label }}
          </button>
        </div>

        <!-- Rewards Tab -->
        <div v-if="activeTab === 'rewards'">
          <div v-if="rewards.length === 0" class="text-center py-20 bg-white rounded-[24px] border border-[#E8EEEF] flex flex-col items-center">
            <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
              <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">card_giftcard</span>
            </div>
            <p class="text-[#505E5E] font-bold text-lg" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ยังไม่มีของรางวัลในขณะนี้</p>
          </div>
          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div
              v-for="r in rewards"
              :key="r.id"
              class="bg-white rounded-[20px] p-5 md:p-6 flex flex-col border border-[#E8EEEF] hover:border-[#006565]/30 transition-all group">
              <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 bg-[#F9FAFA] rounded-[12px] flex items-center justify-center border border-[#E8EEEF] group-hover:scale-105 transition-transform">
                  <span class="material-symbols-rounded text-[28px] text-[#006565]">{{ rewardIcon(r.type) }}</span>
                </div>
                <span class="text-[12px] font-bold bg-[#E3F2F2] text-[#006565] rounded-[8px] px-3 py-1.5"
                  style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  ใช้ {{ r.points_required.toLocaleString() }} แต้ม
                </span>
              </div>
              <h3 class="text-[16px] font-bold text-[#1a1c1c] mb-1.5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ r.name }}</h3>
              <p class="text-[14px] text-[#505E5E] flex-1 mb-5 leading-relaxed" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ r.description }}</p>
              <div class="flex justify-between items-center pt-4 border-t border-[#F4F7F6]">
                <span class="text-[14px] font-bold text-[#006565]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  {{ rewardValue(r) }}
                </span>
                <button
                  @click="redeemReward(r)"
                  :disabled="(account?.points ?? 0) < r.points_required || redeeming === r.id"
                  class="bg-[#006565] text-white px-5 py-2.5 rounded-[12px] text-sm font-bold disabled:opacity-50 disabled:cursor-not-allowed hover:bg-[#004f4f] transition-all flex items-center gap-1.5 shadow-sm/20"
                  style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  <span v-if="redeeming === r.id" class="material-symbols-rounded text-[18px] animate-spin">progress_activity</span>
                  {{ redeeming === r.id ? 'กำลังแลก...' : 'แลกรับเลย' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Coupons Tab -->
        <div v-else-if="activeTab === 'coupons'">
          <div v-if="coupons.length === 0" class="text-center py-20 bg-white rounded-[24px] border border-[#E8EEEF] flex flex-col items-center">
            <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
              <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">local_activity</span>
            </div>
            <p class="text-[#505E5E] font-bold text-lg" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ยังไม่มีคูปอง</p>
          </div>
          <div v-else class="space-y-4">
            <div
              v-for="c in coupons"
              :key="c.id"
              class="bg-white rounded-[20px] p-5 flex items-center gap-4 border border-[#E8EEEF]"
              :class="{ 'opacity-60 grayscale-[0.5]': c.is_used || isExpired(c.expires_at) }">
              <div class="w-14 h-14 bg-[#F9FAFA] rounded-[16px] flex items-center justify-center border border-[#E8EEEF] shrink-0">
                 <span class="material-symbols-rounded text-[32px] text-[#006565]">{{ rewardIcon(c.reward_type) }}</span>
              </div>
              <div class="flex-1 min-w-0 pr-4">
                <p class="font-bold text-[15px] text-[#1a1c1c] truncate" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ c.reward_name }}</p>
                <div class="flex items-center gap-3 mt-1">
                  <p class="font-mono text-lg text-[#006565] font-bold tracking-widest bg-[#E3F2F2] px-2 py-0.5 rounded-[6px]">{{ c.coupon_code }}</p>
                </div>
                <p class="text-[12px] text-[#889696] mt-2 flex items-center gap-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  <span class="material-symbols-rounded text-[14px]">event</span>
                  หมดอายุ: {{ formatDate(c.expires_at) }}
                </p>
              </div>
              <span
                class="shrink-0 text-[11px] font-bold rounded-[8px] px-3 py-1.5 flex items-center gap-1 border"
                :class="c.is_used ? 'bg-[#F4F7F6] text-[#A0B0B0] border-[#E8EEEF]' : isExpired(c.expires_at) ? 'bg-[#FEF2F2] text-[#DC2626] border-[#FCA5A5]' : 'bg-[#E3F2F2] text-[#006565] border-[#BCDFDF]'"
                style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                <span v-if="c.is_used" class="material-symbols-rounded text-[14px]">check_circle</span>
                <span v-else-if="isExpired(c.expires_at)" class="material-symbols-rounded text-[14px]">error</span>
                <span v-else class="material-symbols-rounded text-[14px]">verified</span>
                {{ c.is_used ? 'ใช้สิทธิ์แล้ว' : isExpired(c.expires_at) ? 'หมดอายุ' : 'พร้อมใช้งาน' }}
              </span>
            </div>
          </div>
        </div>

        <!-- History Tab -->
        <div v-else-if="activeTab === 'history'">
          <div v-if="!account?.transactions?.length" class="text-center py-20 bg-white rounded-[24px] border border-[#E8EEEF] flex flex-col items-center">
            <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
              <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">history</span>
            </div>
            <p class="text-[#505E5E] font-bold text-lg" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ยังไม่มีประวัติแต้ม</p>
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="t in account?.transactions"
              :key="t.id"
              class="bg-white rounded-[16px] p-4 flex items-center gap-4 border border-[#E8EEEF]">
              <div
                class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 border"
                :class="t.type === 'earn' ? 'bg-[#F0FAFA] border-[#BCDFDF] text-[#006565]' : 'bg-[#FFFBEB] border-[#FDE68A] text-[#D97706]'">
                <span class="material-symbols-rounded text-[24px]">{{ t.type === 'earn' ? 'add' : 'remove' }}</span>
              </div>
              <div class="flex-1 min-w-0 pr-4">
                <p class="text-[14px] font-bold text-[#1a1c1c] truncate" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ t.description }}</p>
                <p class="text-[12px] text-[#A0B0B0] mt-0.5 flex items-center gap-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  <span class="material-symbols-rounded text-[14px]">calendar_today</span>
                  {{ formatDate(t.created_at) }}
                </p>
              </div>
              <div class="text-right shrink-0">
                <p
                  class="font-bold text-lg"
                  :class="t.type === 'earn' ? 'text-[#006565]' : 'text-[#D97706]'"
                  style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  {{ t.type === 'earn' ? '+' : '-' }}{{ t.points }}
                </p>
                <p class="text-[12px] text-[#889696] font-medium mt-0.5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">คงเหลือ {{ t.balance_after }}</p>
              </div>
            </div>
          </div>
        </div>

      </template>

      <!-- Redeem Success Modal -->
      <div v-if="redeemResult" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all pr-4 sm:pr-6 md:pr-0">
        <div class="bg-white rounded-[24px] w-full max-w-sm p-8 text-center relative">
          <div class="w-20 h-20 bg-[#E3F2F2] rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-white">
            <span class="material-symbols-rounded text-[40px] text-[#006565]">check_circle</span>
          </div>
          <h2 class="text-2xl font-bold text-[#1a1c1c] mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">แลกรับสำเร็จ!</h2>
          <p class="text-[#505E5E] mb-5 text-[15px]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ redeemResult.reward?.name }}</p>
          
          <div class="bg-[#F9FAFA] border border-[#E8EEEF] rounded-[16px] px-4 py-4 mb-5 relative overflow-hidden">
             <p class="text-[12px] text-[#889696] font-bold uppercase tracking-wider mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">รหัสคูปองของคุณ</p>
             <p class="text-3xl font-mono font-bold text-[#006565] tracking-widest">
               {{ redeemResult.coupon_code }}
             </p>
          </div>
          
          <p class="text-[14px] text-[#505E5E] mb-6 font-medium" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            แต้มคงเหลือ <span class="font-bold text-[#1a1c1c]">{{ redeemResult.points_remaining?.toLocaleString() }}</span> แต้ม
          </p>
          <button
            @click="redeemResult = null"
            class="w-full bg-[#006565] text-white py-3.5 rounded-[16px] font-bold hover:bg-[#004f4f] transition-all"
            style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            ตกลง
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import TierBadge from '../components/TierBadge.vue';
import api from '../lib/axios';
import { thaiShort } from '../lib/thaiDate';

const loading = ref(true);
const account = ref(null);
const rewards = ref([]);
const coupons = ref([]);
const activeTab = ref('rewards');
const redeeming = ref(null);
const redeemResult = ref(null);

const tabs = [
  { key: 'rewards', label: 'ของรางวัล' },
  { key: 'coupons', label: 'คูปองของฉัน' },
  { key: 'history', label: 'ประวัติแต้ม' },
];

const tierBg = computed(() => ({
  frequent: 'bg-[#0F6B5C]',
  comrade: 'bg-[#1D4E86]',
  insider: 'bg-[#8A5A12]',
}[account.value?.tier] || 'bg-[#006565]'));

/**
 * ความคืบหน้าไปยังระดับถัดไป — วัดจากจำนวนทริประหว่างเกณฑ์ของระดับปัจจุบันกับ
 * ระดับถัดไป เกณฑ์ทั้งหมดมาจาก API (data.tiers) จึงไม่ต้องฮาร์ดโค้ดตัวเลขซ้ำฝั่งนี้
 */
const tierProgress = computed(() => {
  const next = account.value?.next_tier;
  if (!next) return 100;

  const current = (account.value.tiers || []).find(t => t.code === account.value.tier);
  const floor = current?.min_trips ?? 0;
  const span = next.at - floor;

  if (span <= 0) return 100;

  const travelled = (account.value.lifetime_trips ?? 0) - floor;
  return Math.min(100, Math.max(0, (travelled / span) * 100));
});

async function loadData() {
  loading.value = true;
  try {
    const [accRes, rewardsRes, couponsRes] = await Promise.all([
      api.get('/loyalty/account'),
      api.get('/loyalty/rewards'),
      api.get('/loyalty/coupons'),
    ]);
    account.value = accRes.data.data;
    rewards.value = rewardsRes.data.data;
    coupons.value = couponsRes.data.data;
  } finally {
    loading.value = false;
  }
}

async function redeemReward(reward) {
  if (!confirm(`แลกรับ "${reward.name}" ใช้ ${reward.points_required} แต้ม?`)) return;
  redeeming.value = reward.id;
  try {
    const res = await api.post('/loyalty/redeem', { reward_id: reward.id });
    redeemResult.value = res.data.data;
    await loadData();
  } catch (e) {
    alert(e?.response?.data?.message || 'แลกรับไม่สำเร็จ');
  } finally {
    redeeming.value = null;
  }
}

function rewardIcon(type) {
  return {
    discount_percent: 'local_offer',
    discount_fixed: 'payments',
    free_rental: 'backpack',
    free_item: 'card_giftcard',
  }[type] || 'card_giftcard';
}

/** ข้อความมูลค่ามาจาก API (`value_label`) — ความหมายของ discount_value ต่างกันตามชนิด */
function rewardValue(r) {
  return r.value_label || r.name;
}

function isExpired(date) {
  return date && new Date(date) < new Date();
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(loadData);
</script>

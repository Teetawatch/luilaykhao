<template>
  <div class="min-h-screen bg-[#f9f9f9] pt-8 pb-32">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-1" style="font-family:'Anuphan',sans-serif;">
          แต้มสะสม
        </h1>
        <p class="text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">สะสมแต้มจากการจองและแลกรับของรางวัล</p>
      </section>

      <div v-if="loading" class="text-center py-20">
        <div class="inline-block w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <template v-else>
        <!-- Account Card -->
        <div class="relative rounded-2xl overflow-hidden mb-6 text-white"
          :class="tierBg"
          style="box-shadow:0px 20px 40px rgba(0,64,64,0.15);">
          <div class="absolute inset-0 opacity-10" style="background:repeating-linear-gradient(45deg,#fff 0,#fff 1px,transparent 0,transparent 50%);background-size:20px 20px;"></div>
          <div class="relative p-6">
            <div class="flex justify-between items-start mb-6">
              <div>
                <p class="text-white/70 text-sm font-medium" style="font-family:'Anuphan',sans-serif;">ระดับสมาชิก</p>
                <p class="text-2xl font-bold" style="font-family:'Anuphan',sans-serif;">{{ account?.tier_label }}</p>
              </div>
              <div class="text-4xl">{{ tierEmoji }}</div>
            </div>
            <div class="flex gap-8">
              <div>
                <p class="text-white/70 text-sm" style="font-family:'Anuphan',sans-serif;">แต้มคงเหลือ</p>
                <p class="text-4xl font-bold" style="font-family:'Anuphan',sans-serif;">{{ account?.points?.toLocaleString() }}</p>
              </div>
              <div>
                <p class="text-white/70 text-sm" style="font-family:'Anuphan',sans-serif;">แต้มสะสมตลอดกาล</p>
                <p class="text-2xl font-bold" style="font-family:'Anuphan',sans-serif;">{{ account?.lifetime_points?.toLocaleString() }}</p>
              </div>
            </div>

            <!-- Progress to next tier -->
            <div v-if="account?.next_tier" class="mt-5">
              <div class="flex justify-between text-xs text-white/70 mb-1" style="font-family:'Anuphan',sans-serif;">
                <span>{{ account.tier_label }}</span>
                <span>{{ account.next_tier.tier }} ({{ account.next_tier.at.toLocaleString() }} แต้ม)</span>
              </div>
              <div class="h-2 bg-white/20 rounded-full overflow-hidden">
                <div
                  class="h-full bg-white rounded-full transition-all duration-700"
                  :style="{ width: tierProgress + '%' }"></div>
              </div>
              <p class="text-xs text-white/70 mt-1" style="font-family:'Anuphan',sans-serif;">
                อีก {{ account.next_tier.points_needed.toLocaleString() }} แต้ม เพื่อขึ้นระดับ {{ account.next_tier.tier }}
              </p>
            </div>
            <div v-else class="mt-4">
              <span class="text-sm bg-white/20 rounded-full px-3 py-1" style="font-family:'Anuphan',sans-serif;">
                ✦ ระดับสูงสุด
              </span>
            </div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-3 mb-6 bg-[#f3f3f3] p-1.5 rounded-xl w-fit">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            @click="activeTab = tab.key"
            class="px-5 py-2 text-sm font-semibold rounded-lg transition-all"
            :class="activeTab === tab.key ? 'bg-white text-[#006565] shadow-sm' : 'text-[#3e4949] hover:text-[#006565]'"
            style="font-family:'Anuphan',sans-serif;">
            {{ tab.label }}
          </button>
        </div>

        <!-- Rewards Tab -->
        <div v-if="activeTab === 'rewards'">
          <div v-if="rewards.length === 0" class="text-center py-12 text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
            ยังไม่มีของรางวัล
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div
              v-for="r in rewards"
              :key="r.id"
              class="bg-white rounded-2xl p-5 flex flex-col"
              style="box-shadow:0px 4px 16px rgba(0,64,64,0.06);">
              <div class="flex justify-between items-start mb-3">
                <span class="text-2xl">{{ rewardIcon(r.type) }}</span>
                <span class="text-xs font-bold bg-[#006565]/10 text-[#006565] rounded-full px-2.5 py-1"
                  style="font-family:'Anuphan',sans-serif;">
                  {{ r.points_required.toLocaleString() }} แต้ม
                </span>
              </div>
              <h3 class="font-bold text-[#1a1c1c] mb-1" style="font-family:'Anuphan',sans-serif;">{{ r.name }}</h3>
              <p class="text-sm text-[#3e4949] flex-1 mb-4" style="font-family:'Anuphan',sans-serif;">{{ r.description }}</p>
              <div class="flex justify-between items-center">
                <span class="text-sm font-bold text-[#006565]" style="font-family:'Anuphan',sans-serif;">
                  {{ rewardValue(r) }}
                </span>
                <button
                  @click="redeemReward(r)"
                  :disabled="(account?.points ?? 0) < r.points_required || redeeming === r.id"
                  class="bg-[#006565] text-white px-4 py-2 rounded-xl text-sm font-semibold disabled:opacity-40 hover:opacity-90 transition-opacity"
                  style="font-family:'Anuphan',sans-serif;">
                  {{ redeeming === r.id ? '...' : 'แลกรับ' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Coupons Tab -->
        <div v-else-if="activeTab === 'coupons'">
          <div v-if="coupons.length === 0" class="text-center py-12 text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
            ยังไม่มีคูปอง
          </div>
          <div class="space-y-3">
            <div
              v-for="c in coupons"
              :key="c.id"
              class="bg-white rounded-2xl p-5 flex items-center gap-4"
              :class="{ 'opacity-50': c.is_used || isExpired(c.expires_at) }"
              style="box-shadow:0px 4px 16px rgba(0,64,64,0.06);">
              <div class="text-3xl">{{ rewardIcon(c.reward_type) }}</div>
              <div class="flex-1 min-w-0">
                <p class="font-bold text-[#1a1c1c]" style="font-family:'Anuphan',sans-serif;">{{ c.reward_name }}</p>
                <p class="font-mono text-lg text-[#006565] font-bold mt-0.5">{{ c.coupon_code }}</p>
                <p class="text-xs text-[#3e4949] mt-1" style="font-family:'Anuphan',sans-serif;">
                  หมดอายุ: {{ formatDate(c.expires_at) }}
                </p>
              </div>
              <span
                class="shrink-0 text-xs font-bold rounded-full px-3 py-1"
                :class="c.is_used ? 'bg-[#e2e2e2] text-[#3e4949]' : isExpired(c.expires_at) ? 'bg-red-100 text-red-500' : 'bg-[#006565]/10 text-[#006565]'"
                style="font-family:'Anuphan',sans-serif;">
                {{ c.is_used ? 'ใช้แล้ว' : isExpired(c.expires_at) ? 'หมดอายุ' : 'ใช้ได้' }}
              </span>
            </div>
          </div>
        </div>

        <!-- History Tab -->
        <div v-else-if="activeTab === 'history'">
          <div v-if="!account?.transactions?.length" class="text-center py-12 text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
            ยังไม่มีประวัติ
          </div>
          <div class="space-y-2">
            <div
              v-for="t in account?.transactions"
              :key="t.id"
              class="bg-white rounded-xl p-4 flex items-center gap-4"
              style="box-shadow:0px 2px 8px rgba(0,64,64,0.04);">
              <div
                class="w-10 h-10 rounded-full flex items-center justify-center text-lg shrink-0"
                :class="t.type === 'earn' ? 'bg-green-100' : 'bg-orange-100'">
                {{ t.type === 'earn' ? '↑' : '↓' }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-[#1a1c1c] truncate" style="font-family:'Anuphan',sans-serif;">{{ t.description }}</p>
                <p class="text-xs text-[#bdc9c8]" style="font-family:'Anuphan',sans-serif;">{{ formatDate(t.created_at) }}</p>
              </div>
              <div class="text-right shrink-0">
                <p
                  class="font-bold text-base"
                  :class="t.type === 'earn' ? 'text-green-600' : 'text-orange-500'"
                  style="font-family:'Anuphan',sans-serif;">
                  {{ t.type === 'earn' ? '+' : '' }}{{ t.points }}
                </p>
                <p class="text-xs text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">คงเหลือ {{ t.balance_after }}</p>
              </div>
            </div>
          </div>
        </div>

      </template>

      <!-- Redeem Success Modal -->
      <div v-if="redeemResult" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm p-8 text-center shadow-2xl">
          <div class="text-5xl mb-4">🎉</div>
          <h2 class="text-xl font-bold text-[#1a1c1c] mb-2" style="font-family:'Anuphan',sans-serif;">แลกรับสำเร็จ!</h2>
          <p class="text-[#3e4949] mb-4" style="font-family:'Anuphan',sans-serif;">{{ redeemResult.reward?.name }}</p>
          <p class="text-3xl font-mono font-bold text-[#006565] bg-[#f0fafa] rounded-xl px-4 py-3 mb-4">
            {{ redeemResult.coupon_code }}
          </p>
          <p class="text-sm text-[#3e4949] mb-6" style="font-family:'Anuphan',sans-serif;">
            แต้มคงเหลือ {{ redeemResult.points_remaining?.toLocaleString() }} แต้ม
          </p>
          <button
            @click="redeemResult = null"
            class="w-full bg-[#006565] text-white py-3 rounded-xl font-semibold"
            style="font-family:'Anuphan',sans-serif;">
            ตกลง
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../lib/axios';

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

const tierBg = computed(() => {
  if (!account.value) return 'bg-[#006565]';
  return {
    gold: 'bg-gradient-to-br from-yellow-500 to-amber-600',
    silver: 'bg-gradient-to-br from-slate-400 to-slate-600',
    regular: 'bg-gradient-to-br from-[#006565] to-[#004545]',
  }[account.value.tier] || 'bg-[#006565]';
});

const tierEmoji = computed(() => {
  const map = { gold: '🥇', silver: '🥈', regular: '🌿' };
  return map[account.value?.tier] || '🌿';
});

const tierProgress = computed(() => {
  if (!account.value?.next_tier) return 100;
  const { at, points_needed } = account.value.next_tier;
  const earned = at - points_needed;
  const prev = account.value.tier === 'regular' ? 0 : 1500;
  return Math.min(100, Math.max(0, ((earned - prev) / (at - prev)) * 100));
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
  return { discount_percent: '🏷️', discount_fixed: '💰', free_item: '🎁' }[type] || '🎁';
}

function rewardValue(r) {
  if (r.type === 'discount_percent') return `ส่วนลด ${r.discount_value}%`;
  if (r.type === 'discount_fixed') return `ส่วนลด ฿${Number(r.discount_value).toLocaleString()}`;
  return 'ของรางวัลพิเศษ';
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

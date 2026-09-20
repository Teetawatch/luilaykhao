<template>
  <div class="chat-page">
    <!-- ─── Sidebar : room list ─────────────────────── -->
    <div class="chat-sidebar">
      <div class="chat-sidebar-header">
        <h2><i class="fas fa-comments"></i> แชทกลุ่มทริป</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadConversations()" title="รีเฟรช">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div class="sidebar-search">
        <i class="fas fa-search"></i>
        <input v-model="search" type="text" placeholder="ค้นหาทริป / รถ / ทีมงาน..." />
        <button v-if="search" class="clear-search" @click="search = ''"><i class="fas fa-times"></i></button>
      </div>

      <div class="sidebar-tabs">
        <button :class="{ active: tab === 'reply' }" @click="tab = 'reply'">
          รอตอบ
          <span class="tab-badge alert" v-if="needsReplyCount">{{ needsReplyCount }}</span>
        </button>
        <button :class="{ active: tab === 'active' }" @click="tab = 'active'">
          มีข้อความ
          <span class="tab-badge" v-if="activeCount">{{ activeCount }}</span>
        </button>
        <button :class="{ active: tab === 'all' }" @click="tab = 'all'">
          ทุกรอบ
          <span class="tab-badge" v-if="conversations.length">{{ conversations.length }}</span>
        </button>
      </div>

      <div v-if="loadingList && !conversations.length" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!filteredConversations.length" class="empty-hint">
        {{ emptyListHint }}
      </div>

      <ul v-else class="conv-list">
        <li
          v-for="conv in filteredConversations"
          :key="conv.schedule_id"
          class="conv-item"
          :class="{ active: conv.schedule_id === activeId }"
          @click="openConversation(conv)"
        >
          <div class="conv-thumb">
            <img v-if="conv.trip_image" :src="conv.trip_image" alt="" />
            <i v-else class="fas fa-mountain-sun"></i>
          </div>
          <div class="conv-content">
            <div class="conv-top">
              <span class="conv-title">{{ conv.trip_title || 'ทริป' }}</span>
              <span class="conv-time" v-if="conv.last_message">{{ shortTime(conv.last_message.created_at) }}</span>
            </div>
            <div class="conv-sub">
              <span class="day-chip" :class="'day-' + dayTone(conv.days_until)">{{ dayLabel(conv.days_until) }}</span>
              <span>{{ formatDate(conv.departure_date) }}</span>
              <span class="conv-seats">
                <i class="fas fa-user-group"></i> {{ conv.booked_seats }}/{{ conv.total_seats }}
              </span>
              <span v-if="!conv.staff_count" class="conv-nostaff" title="รอบนี้ยังไม่มีทีมงานประจำ">
                <i class="fas fa-user-slash"></i> ไม่มีทีมงาน
              </span>
            </div>
            <div class="conv-bottom">
              <span class="conv-preview" v-if="conv.last_message">
                <template v-if="conv.last_message.sender_name">{{ conv.last_message.sender_name }}: </template>
                <i v-if="conv.last_message.image_url" class="fas fa-image preview-img-icon"></i>
                {{ conv.last_message.body || (conv.last_message.image_url ? 'รูปภาพ' : '') }}
              </span>
              <span class="conv-preview muted" v-else>ยังไม่มีข้อความ — เริ่มแชทได้เลย</span>
              <span class="conv-flags">
                <span class="reply-chip" v-if="conv.needs_reply" title="ลูกค้าพูดคนสุดท้าย">รอตอบ</span>
                <span class="conv-count unread" v-if="conv.unread_count">{{ conv.unread_count }}</span>
                <span class="conv-count" v-else-if="conv.message_count">{{ conv.message_count }}</span>
              </span>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ─── Main : thread ───────────────────────────── -->
    <div class="chat-main">
      <div v-if="!activeId" class="chat-empty">
        <i class="fas fa-comment-dots"></i>
        <p>เลือกห้องแชทเพื่อเริ่มสนทนา</p>
        <span>เลือกรอบเดินทางจากด้านซ้ายได้ทุกรอบ</span>
      </div>

      <template v-else>
        <div class="chat-header">
          <div class="chat-header-info">
            <div class="header-thumb">
              <img v-if="activeConv?.trip_image" :src="activeConv.trip_image" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <div class="header-text">
              <h3>{{ activeConv?.trip_title || 'ทริป' }}</h3>
              <span class="chat-sub">
                เดินทาง {{ formatDate(activeConv?.departure_date) }}
                <span class="day-chip" :class="'day-' + dayTone(activeConv?.days_until)">
                  {{ dayLabel(activeConv?.days_until) }}
                </span>
                <span v-if="activeConv?.vehicle_name" class="header-vehicle">
                  · <i class="fas fa-van-shuttle"></i> {{ activeConv.vehicle_name }}
                </span>
                <span class="header-members" v-if="room">
                  · <i class="fas fa-user-group"></i> {{ room.member_count }} คนในห้อง
                </span>
              </span>
            </div>
          </div>
          <div class="header-actions">
            <span class="ws-pill" :class="{ on: wsConnected }">
              <span class="ws-dot"></span>
              {{ wsConnected ? 'เรียลไทม์' : 'ออฟไลน์' }}
            </span>
            <button class="icon-btn" :class="{ on: showInfo }" @click="showInfo = !showInfo" title="ข้อมูลรอบเดินทาง">
              <i class="fas fa-circle-info"></i>
            </button>
          </div>
        </div>

        <!-- ข้อความที่ปักหมุดไว้ของห้อง -->
        <div v-if="room?.pinned_message" class="pinned-bar" @click="jumpTo(room.pinned_message.id)">
          <i class="fas fa-thumbtack"></i>
          <div class="pinned-text">
            <strong>{{ room.pinned_message.sender_name }}</strong>
            {{ pinnedPreview }}
          </div>
          <button class="pinned-unpin" @click.stop="togglePin({ id: room.pinned_message.id, is_pinned: true })" title="ปลดหมุด">
            <i class="fas fa-times"></i>
          </button>
        </div>

        <div ref="threadEl" class="chat-thread">
          <div v-if="loadingMessages" class="empty-hint">กำลังโหลดข้อความ...</div>
          <template v-else>
            <div v-if="hasMore" class="load-older">
              <button :disabled="loadingOlder" @click="loadOlder">
                <i class="fas" :class="loadingOlder ? 'fa-spinner fa-spin' : 'fa-clock-rotate-left'"></i>
                โหลดข้อความเก่า
              </button>
            </div>
            <div v-if="!messages.length" class="empty-hint">ยังไม่มีข้อความในห้องนี้</div>

            <template v-for="(m, i) in messages" :key="m.id">
              <div v-if="showDateDivider(i)" class="date-divider">
                <span>{{ formatDate(m.created_at) }}</span>
              </div>

              <!-- ข้อความระบบ: กลางจอ เต็มความกว้าง เพราะมักเป็นสรุป/กำหนดการหลายบรรทัด -->
              <div v-if="isSystem(m)" class="system-row" :id="'msg-' + m.id" :class="{ highlight: highlightId === m.id }">
                <div class="system-bubble">
                  <div class="system-head">
                    <span><i class="fas fa-circle-info"></i> ข้อความจากระบบ</span>
                    <span class="msg-actions-inline">
                      <button @click="togglePin(m)" :title="m.is_pinned ? 'ปลดหมุด' : 'ปักหมุด'">
                        <i class="fas fa-thumbtack" :class="{ pinned: m.is_pinned }"></i>
                      </button>
                      <button @click="removeMessage(m)" title="ลบข้อความ"><i class="fas fa-trash"></i></button>
                    </span>
                  </div>
                  <div class="system-body">{{ m.body }}</div>
                  <div class="msg-time">{{ formatTime(m.created_at) }}</div>
                </div>
              </div>

              <div
                v-else
                class="msg-row"
                :class="{ mine: m.is_mine, highlight: highlightId === m.id }"
                :id="'msg-' + m.id"
              >
                <div class="msg-avatar" v-if="!m.is_mine && showAvatar(i)">
                  <img :src="m.user?.avatar_url || fallbackAvatar(m)" :alt="senderName(m)" />
                </div>
                <div class="msg-avatar spacer" v-else-if="!m.is_mine"></div>

                <div class="msg-col">
                  <div class="msg-author" v-if="!m.is_mine && showAvatar(i)">
                    {{ senderName(m) }}
                    <span class="role-tag" :class="'role-' + m.sender_role">{{ roleLabel(m.sender_role) }}</span>
                  </div>

                  <div class="msg-line">
                    <div class="msg-bubble" :class="{ deleted: m.is_deleted }">
                      <!-- กล่อง quote ของข้อความที่ถูกตอบกลับ -->
                      <div v-if="m.reply_to" class="reply-quote" @click="jumpTo(m.reply_to.id)">
                        <span class="reply-author">{{ m.reply_to.sender_name }}</span>
                        <span class="reply-body">
                          {{ m.reply_to.body || (m.reply_to.image_url ? 'รูปภาพ' : 'ข้อความถูกลบแล้ว') }}
                        </span>
                      </div>

                      <div v-if="m.is_deleted" class="msg-body deleted-text">ข้อความนี้ถูกลบแล้ว</div>
                      <template v-else>
                        <div v-if="m.image_url" class="msg-image" @click="lightbox = m.image_url">
                          <img :src="m.image_url" alt="รูปภาพ" />
                        </div>
                        <div v-if="m.body" class="msg-body">{{ m.body }}</div>

                        <!-- โพลในห้อง (แอดมินดูผลและปิดโหวตได้ แต่ไม่ลงคะแนนแทนใคร) -->
                        <div v-if="m.poll" class="poll-card">
                          <div class="poll-question">{{ m.poll.question }}</div>
                          <div v-for="opt in m.poll.options" :key="opt.id" class="poll-option">
                            <div class="poll-bar" :style="{ width: pollPercent(m.poll, opt) + '%' }"></div>
                            <span class="poll-label">{{ opt.label }}</span>
                            <span class="poll-count">{{ opt.vote_count }}</span>
                          </div>
                          <div class="poll-foot">
                            <span>{{ m.poll.voter_count }} คนโหวต{{ m.poll.is_closed ? ' · ปิดแล้ว' : '' }}</span>
                            <button v-if="!m.poll.is_closed" @click="closePoll(m)">ปิดโหวต</button>
                          </div>
                        </div>
                      </template>

                      <div class="msg-time">
                        <span v-if="m.edited_at" class="edited-tag">แก้ไขแล้ว</span>
                        <i v-if="m.is_pinned" class="fas fa-thumbtack pin-mark"></i>
                        {{ formatTime(m.created_at) }}
                      </div>
                    </div>

                    <!-- แถบเครื่องมือของทีมงาน โผล่ตอนชี้เมาส์ -->
                    <div class="msg-actions" v-if="!m.is_deleted">
                      <div class="react-pop">
                        <button
                          v-for="emoji in reactionEmojis"
                          :key="emoji"
                          class="react-choice"
                          @click="react(m, emoji)"
                        >{{ emoji }}</button>
                      </div>
                      <button @click="startReply(m)" title="ตอบกลับ"><i class="fas fa-reply"></i></button>
                      <button class="react-trigger" title="รีแอกชัน"><i class="fas fa-face-smile"></i></button>
                      <button @click="togglePin(m)" :title="m.is_pinned ? 'ปลดหมุด' : 'ปักหมุด'">
                        <i class="fas fa-thumbtack" :class="{ pinned: m.is_pinned }"></i>
                      </button>
                      <button @click="removeMessage(m)" title="ลบข้อความ"><i class="fas fa-trash"></i></button>
                    </div>
                  </div>

                  <div v-if="m.reactions?.length" class="reaction-row" :class="{ mine: m.is_mine }">
                    <button
                      v-for="r in m.reactions"
                      :key="r.emoji"
                      class="reaction-pill"
                      @click="react(m, r.emoji)"
                    >{{ r.emoji }} {{ r.count }}</button>
                  </div>
                </div>
              </div>
            </template>

            <div v-if="readStats.total && lastMessageId" class="read-receipt">
              <i class="fas fa-check-double"></i> อ่านแล้ว {{ readStats.read }} จาก {{ readStats.total }} คน
            </div>
          </template>
        </div>

        <div v-if="typingNames.length" class="typing-line">
          <i class="fas fa-ellipsis"></i> {{ typingNames.join(', ') }} กำลังพิมพ์...
        </div>

        <!-- composer -->
        <div class="chat-composer">
          <div class="quick-actions">
            <button :disabled="posting" @click="postSummary">
              <i class="fas fa-clipboard-list"></i> ส่งสรุปการเดินทาง
            </button>
            <button :disabled="posting || room?.has_itinerary === false" @click="postItinerary">
              <i class="fas fa-route"></i> ส่งกำหนดการ
            </button>
            <span class="quick-hint">ส่งเป็นข้อความระบบให้ทุกคนในห้องเห็นพร้อมกัน</span>
          </div>

          <div v-if="replyTo" class="reply-bar">
            <i class="fas fa-reply"></i>
            <div class="reply-bar-text">
              <strong>ตอบกลับ {{ replyTo.sender_name }}</strong>
              <span>{{ replyTo.body || (replyTo.image_url ? 'รูปภาพ' : '') }}</span>
            </div>
            <button @click="replyTo = null"><i class="fas fa-times"></i></button>
          </div>

          <div v-if="imagePreview" class="image-preview-bar">
            <div class="image-preview">
              <img :src="imagePreview" alt="" />
              <button class="remove-preview" @click="clearPendingImage"><i class="fas fa-times"></i></button>
            </div>
          </div>

          <form class="chat-input" @submit.prevent="send">
            <label class="attach-btn" :class="{ disabled: sending }" title="แนบรูปภาพ">
              <i class="fas fa-image"></i>
              <input type="file" accept="image/*" hidden :disabled="sending" @change="onPickImage" />
            </label>
            <textarea
              ref="textareaEl"
              v-model="draft"
              rows="1"
              placeholder="พิมพ์ข้อความ... (Shift + Enter ขึ้นบรรทัดใหม่)"
              maxlength="2000"
              :disabled="sending"
              @keydown="onComposerKeydown"
              @input="onTyping"
              @paste="onPaste"
            ></textarea>
            <button type="submit" class="send-btn" :disabled="sending || (!draft.trim() && !pendingImage)">
              <i class="fas" :class="sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
        </div>
      </template>
    </div>

    <!-- ─── Info panel : ข้อมูลรอบเดินทาง ───────────── -->
    <aside v-if="activeId && showInfo" class="chat-info">
      <div class="info-head">
        <h4>ข้อมูลรอบเดินทาง</h4>
        <button class="icon-btn" @click="showInfo = false"><i class="fas fa-times"></i></button>
      </div>

      <div v-if="!room" class="empty-hint">กำลังโหลด...</div>

      <template v-else>
        <section class="info-block">
          <div class="info-stats">
            <div class="stat">
              <span class="stat-num">{{ activeConv?.booked_seats ?? 0 }}/{{ activeConv?.total_seats ?? 0 }}</span>
              <span class="stat-label">ที่นั่งที่จองแล้ว</span>
            </div>
            <div class="stat">
              <span class="stat-num">{{ room.member_count }}</span>
              <span class="stat-label">คนในห้องแชท</span>
            </div>
          </div>
          <div class="info-rows">
            <div class="info-row">
              <span>ออกเดินทาง</span>
              <strong>{{ formatDate(room.schedule?.departure_date) }}</strong>
            </div>
            <div class="info-row" v-if="room.schedule?.return_date">
              <span>กลับถึง</span>
              <strong>{{ formatDate(room.schedule.return_date) }}</strong>
            </div>
            <div class="info-row">
              <span>สถานะรอบ</span>
              <strong>{{ statusLabel(room.schedule?.status) }}</strong>
            </div>
          </div>
          <router-link
            v-if="activeConv?.trip_id"
            class="info-link"
            :to="{ path: '/admin/schedules', query: { trip: activeConv.trip_id } }"
          >
            <i class="fas fa-arrow-up-right-from-square"></i> เปิดรอบเดินทางนี้
          </router-link>
        </section>

        <section class="info-block" v-if="room.weather">
          <h5><i class="fas fa-cloud-sun"></i> อากาศวันเดินทาง</h5>
          <div class="weather-line">
            <strong>{{ room.weather.description_th }}</strong>
            <span>{{ Math.round(room.weather.temp_min) }}°–{{ Math.round(room.weather.temp_max) }}°C</span>
            <span v-if="room.weather.pop !== null">โอกาสฝน {{ Math.round(room.weather.pop) }}%</span>
          </div>
        </section>

        <section class="info-block" v-if="room.vehicle">
          <h5><i class="fas fa-van-shuttle"></i> รถและคนขับ</h5>
          <div class="info-rows">
            <div class="info-row"><span>รถ</span><strong>{{ room.vehicle.name || '-' }}</strong></div>
            <div class="info-row" v-if="room.vehicle.license_plate">
              <span>ทะเบียน</span><strong>{{ room.vehicle.license_plate }}</strong>
            </div>
            <div class="info-row" v-if="room.vehicle.driver_name">
              <span>คนขับ</span><strong>{{ room.vehicle.driver_name }}</strong>
            </div>
            <div class="info-row" v-if="room.vehicle.driver_phone">
              <span>เบอร์</span>
              <a :href="'tel:' + room.vehicle.driver_phone"><strong>{{ room.vehicle.driver_phone }}</strong></a>
            </div>
          </div>
        </section>

        <section class="info-block" v-if="room.pickup_points?.length">
          <h5><i class="fas fa-location-dot"></i> จุดขึ้นรถ ({{ room.pickup_points.length }})</h5>
          <div v-for="p in room.pickup_points" :key="p.id" class="pickup-item">
            <div class="pickup-top">
              <strong>{{ p.region_label || p.pickup_location }}</strong>
              <span v-if="p.pickup_time" class="pickup-time">{{ p.pickup_time }}</span>
            </div>
            <div class="pickup-sub" v-if="p.region_label">{{ p.pickup_location }}</div>
            <div class="pickup-note" v-if="p.notes">{{ p.notes }}</div>
            <a v-if="p.map_url" :href="p.map_url" target="_blank" rel="noopener" class="pickup-map">
              <i class="fas fa-map"></i> เปิดแผนที่
            </a>
          </div>
        </section>

        <section class="info-block" v-if="staffMembers.length">
          <h5><i class="fas fa-user-shield"></i> ทีมงานประจำรอบ ({{ staffMembers.length }})</h5>
          <div v-for="m in staffMembers" :key="m.id" class="member-row">
            <img :src="m.avatar_url || memberAvatar(m)" alt="" />
            <div class="member-text">
              <strong>{{ m.nickname || m.name }}</strong>
              <a v-if="m.phone" :href="'tel:' + m.phone" class="member-phone">
                <i class="fas fa-phone"></i> {{ m.phone }}
              </a>
            </div>
            <i v-if="hasRead(m)" class="fas fa-check-double read-mark" title="อ่านข้อความล่าสุดแล้ว"></i>
          </div>
        </section>

        <section class="info-block" v-if="customerMembers.length">
          <h5><i class="fas fa-users"></i> ลูกค้าในห้อง ({{ customerMembers.length }})</h5>
          <div v-for="m in customerMembers" :key="m.id" class="member-row">
            <img :src="m.avatar_url || memberAvatar(m)" alt="" />
            <div class="member-text">
              <strong>{{ m.nickname || m.name }}</strong>
              <span v-if="m.nickname && m.name !== m.nickname" class="member-realname">{{ m.name }}</span>
            </div>
            <i v-if="hasRead(m)" class="fas fa-check-double read-mark" title="อ่านข้อความล่าสุดแล้ว"></i>
          </div>
        </section>
      </template>
    </aside>

    <!-- ─── Image lightbox ──────────────────────────── -->
    <div v-if="lightbox" class="lightbox" @click="lightbox = null">
      <button class="lightbox-close"><i class="fas fa-times"></i></button>
      <img :src="lightbox" alt="รูปภาพ" @click.stop />
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import api from '../../lib/axios';
import { useSwal } from '../../lib/swal';

const swal = useSwal();

const conversations = ref([]);
const loadingList = ref(false);
const search = ref('');
const tab = ref('reply');
const activeId = ref(null);
const activeConv = ref(null);
const room = ref(null);
const showInfo = ref(true);
const messages = ref([]);
const hasMore = ref(false);
const loadingOlder = ref(false);
const loadingMessages = ref(false);
const draft = ref('');
const sending = ref(false);
const posting = ref(false);
const replyTo = ref(null);
const wsConnected = ref(false);
const threadEl = ref(null);
const textareaEl = ref(null);
const pendingImage = ref(null);
const imagePreview = ref(null);
const lightbox = ref(null);
const highlightId = ref(null);
const typingUsers = ref({});
let currentChannel = null;
let listTimer = null;
let lastTypingPing = 0;

const DEFAULT_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

const reactionEmojis = computed(() => room.value?.reaction_emojis || DEFAULT_REACTIONS);
const activeCount = computed(() => conversations.value.filter((c) => c.message_count > 0).length);
const needsReplyCount = computed(() => conversations.value.filter((c) => c.needs_reply).length);
const typingNames = computed(() => Object.values(typingUsers.value).map((t) => t.name));

const emptyListHint = computed(() => {
  if (search.value) return 'ไม่พบรอบเดินทางที่ค้นหา';
  if (tab.value === 'reply') return 'ไม่มีห้องที่รอตอบ — ตอบครบแล้ว';
  if (tab.value === 'active') return 'ยังไม่มีห้องที่มีข้อความ';

  return 'ไม่มีรอบเดินทาง';
});

const filteredConversations = computed(() => {
  let list = conversations.value;
  if (tab.value === 'reply') list = list.filter((c) => c.needs_reply);
  if (tab.value === 'active') list = list.filter((c) => c.message_count > 0);

  const q = search.value.trim().toLowerCase();
  if (q) {
    list = list.filter((c) => [c.trip_title, c.vehicle_name, ...(c.staff_names || [])]
      .some((v) => (v || '').toLowerCase().includes(q)));
  }

  return list;
});

const lastMessageId = computed(() => (messages.value.length ? messages.value[messages.value.length - 1].id : 0));

// "อ่านแล้วกี่คน" ของข้อความล่าสุด — แอดมินจะได้รู้ว่าประกาศไปแล้วถึงใครบ้าง
const readStats = computed(() => {
  const members = (room.value?.members || []).filter((m) => !m.is_me);

  return {
    total: members.length,
    read: members.filter((m) => (m.last_read_message_id || 0) >= lastMessageId.value).length,
  };
});

const staffMembers = computed(() => (room.value?.members || []).filter((m) => m.role === 'staff' || m.role === 'admin'));
const customerMembers = computed(() => (room.value?.members || []).filter((m) => m.role === 'customer'));

const pinnedPreview = computed(() => {
  const pinned = room.value?.pinned_message;
  if (!pinned) return '';

  const text = (pinned.body || (pinned.image_url ? 'รูปภาพ' : '')).replace(/\s+/g, ' ');

  return text.length > 120 ? `${text.slice(0, 120)}...` : text;
});

function roleLabel(role) {
  return { customer: 'ลูกค้า', staff: 'สตาฟ', admin: 'แอดมิน', system: 'ระบบ' }[role] || role;
}

function statusLabel(status) {
  return { open: 'เปิดขาย', closed: 'ปิดรับ', full: 'เต็ม', cancelled: 'ยกเลิก', completed: 'จบแล้ว' }[status] || status || '-';
}

function senderName(m) {
  return (m.user && (m.user.nickname || m.user.name)) || 'ผู้ใช้';
}

function fallbackAvatar(m) {
  return avatarFor(senderName(m));
}

function memberAvatar(m) {
  return avatarFor(m.nickname || m.name || 'ผู้ใช้');
}

function avatarFor(name) {
  return `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=2D7A4F&color=fff`;
}

function isSystem(m) {
  return m.sender_role === 'system';
}

function hasRead(m) {
  return lastMessageId.value > 0 && (m.last_read_message_id || 0) >= lastMessageId.value;
}

function pollPercent(poll, option) {
  const top = Math.max(1, ...poll.options.map((o) => o.vote_count));

  return Math.round((option.vote_count / top) * 100);
}

// แสดงรูป + ชื่อ เฉพาะข้อความแรกของผู้ส่งติดต่อกัน เพื่อความสะอาดตา
function showAvatar(i) {
  if (i === 0) return true;
  const prev = messages.value[i - 1];
  const cur = messages.value[i];

  return prev.is_mine || isSystem(prev) || prev.user?.id !== cur.user?.id;
}

function showDateDivider(i) {
  if (i === 0) return true;
  const prev = messages.value[i - 1]?.created_at;
  const cur = messages.value[i]?.created_at;
  if (!prev || !cur) return false;

  return new Date(prev).toDateString() !== new Date(cur).toDateString();
}

function formatDate(d) {
  if (!d) return '-';

  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTime(d) {
  if (!d) return '';

  return new Date(d).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function shortTime(d) {
  if (!d) return '';
  const date = new Date(d);
  const today = new Date();
  const sameDay = date.toDateString() === today.toDateString();

  return sameDay
    ? date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

/** นับถอยหลังวันเดินทาง — ค่านับมาจาก API ซึ่งคิดตามเวลาไทยแล้ว */
function dayLabel(days) {
  if (days === null || days === undefined) return '';
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days < 0) return `ผ่านมา ${Math.abs(days)} วัน`;

  return `อีก ${days} วัน`;
}

function dayTone(days) {
  if (days === null || days === undefined) return 'none';
  if (days < 0) return 'past';
  if (days <= 1) return 'now';
  if (days <= 3) return 'soon';

  return 'later';
}

async function loadConversations(silent = false) {
  if (!silent) loadingList.value = true;
  try {
    const res = await api.get('/admin/chat/conversations');
    conversations.value = res.data.data || [];

    // รายการถูกสร้างใหม่ทั้งชุด — ผูกห้องที่เปิดอยู่กับออบเจกต์ตัวใหม่
    if (activeId.value) {
      const current = conversations.value.find((c) => c.schedule_id === activeId.value);
      if (current) {
        current.unread_count = 0;
        activeConv.value = current;
      }
    }
  } finally {
    loadingList.value = false;
  }
}

async function openConversation(conv) {
  if (activeId.value === conv.schedule_id) return;
  leaveChannel();
  activeId.value = conv.schedule_id;
  activeConv.value = conv;
  replyTo.value = null;
  room.value = null;
  messages.value = [];
  hasMore.value = false;
  typingUsers.value = {};
  clearPendingImage();
  await Promise.all([loadMessages(), loadRoom()]);
  subscribe(conv.schedule_id);
  markRead();
  conv.unread_count = 0;
}

async function loadMessages() {
  loadingMessages.value = true;
  try {
    const res = await api.get(`/schedules/${activeId.value}/chat/messages`, {
      params: { per_page: 50 },
    });
    messages.value = res.data.data?.messages || [];
    hasMore.value = !!res.data.data?.has_more;
    scrollToBottom();
  } finally {
    loadingMessages.value = false;
  }
}

/** เลื่อนขึ้นดูประวัติเก่า — คงตำแหน่ง scroll ไว้ที่ข้อความเดิมหลังต่อท้ายด้านบน */
async function loadOlder() {
  if (loadingOlder.value || !messages.value.length) return;
  loadingOlder.value = true;
  const el = threadEl.value;
  const previousHeight = el?.scrollHeight ?? 0;
  try {
    const res = await api.get(`/schedules/${activeId.value}/chat/messages`, {
      params: { per_page: 50, before_id: messages.value[0].id },
    });
    const older = res.data.data?.messages || [];
    hasMore.value = !!res.data.data?.has_more;
    messages.value = [...older, ...messages.value];
    nextTick(() => {
      if (el) el.scrollTop = el.scrollHeight - previousHeight;
    });
  } finally {
    loadingOlder.value = false;
  }
}

async function loadRoom() {
  try {
    const res = await api.get(`/schedules/${activeId.value}/chat/room`);
    room.value = res.data.data;
  } catch {
    room.value = null;
  }
}

function onPickImage(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  attachImage(file);
}

// วางรูปจากคลิปบอร์ดได้เลย (แคปหน้าจอสลิป/แผนที่มาตอบลูกค้า)
function onPaste(e) {
  const file = Array.from(e.clipboardData?.items || [])
    .find((item) => item.type.startsWith('image/'))
    ?.getAsFile();

  if (file) {
    e.preventDefault();
    attachImage(file);
  }
}

function attachImage(file) {
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    swal.error('ไฟล์ใหญ่เกินไป', 'ไฟล์รูปต้องไม่เกิน 5MB');

    return;
  }
  clearPendingImage();
  pendingImage.value = file;
  imagePreview.value = URL.createObjectURL(file);
}

function clearPendingImage() {
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
  pendingImage.value = null;
  imagePreview.value = null;
}

function startReply(m) {
  replyTo.value = {
    id: m.id,
    sender_name: isSystem(m) ? 'ระบบ' : senderName(m),
    body: m.body,
    image_url: m.image_url,
  };
  textareaEl.value?.focus();
}

/**
 * Enter = ส่ง, Shift + Enter = ขึ้นบรรทัดใหม่
 *
 * ข้ามระหว่างที่ IME กำลังประกอบคำอยู่ ไม่งั้น Enter ที่ใช้ยืนยันคำจะกลายเป็นการส่ง
 */
function onComposerKeydown(e) {
  if (e.key !== 'Enter' || e.isComposing || e.keyCode === 229) return;
  if (e.shiftKey) return;
  e.preventDefault();
  send();
}

function onTyping() {
  autoGrow();
  const now = Date.now();
  if (!activeId.value || now - lastTypingPing < 3000) return;
  lastTypingPing = now;
  api.post(`/schedules/${activeId.value}/chat/typing`).catch(() => {});
}

function autoGrow() {
  nextTick(() => {
    const el = textareaEl.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 168)}px`;
  });
}

async function send() {
  const body = draft.value.trim();
  if ((!body && !pendingImage.value) || sending.value) return;
  sending.value = true;
  try {
    let res;
    if (pendingImage.value) {
      const fd = new FormData();
      if (body) fd.append('body', body);
      fd.append('image', pendingImage.value);
      if (replyTo.value) fd.append('reply_to_id', replyTo.value.id);
      res = await api.post(`/schedules/${activeId.value}/chat/messages`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
    } else {
      res = await api.post(`/schedules/${activeId.value}/chat/messages`, {
        body,
        ...(replyTo.value ? { reply_to_id: replyTo.value.id } : {}),
      });
    }
    messages.value.push(res.data.data);
    draft.value = '';
    replyTo.value = null;
    clearPendingImage();
    autoGrow();
    scrollToBottom();
    bumpConversation(res.data.data);
  } catch (e) {
    swal.error('ส่งข้อความไม่สำเร็จ', e.response?.data?.message || '');
  } finally {
    sending.value = false;
  }
}

async function postSummary() {
  await postSystemMessage('/chat/trip-summary');
}

async function postItinerary() {
  await postSystemMessage('/chat/trip-itinerary');
}

async function postSystemMessage(path) {
  if (posting.value) return;
  posting.value = true;
  try {
    const res = await api.post(`/schedules/${activeId.value}${path}`);
    messages.value.push(res.data.data);
    scrollToBottom();
    bumpConversation(res.data.data);
  } catch (e) {
    swal.error('ส่งไม่สำเร็จ', e.response?.data?.message || '');
  } finally {
    posting.value = false;
  }
}

async function togglePin(m) {
  const url = `/schedules/${activeId.value}/chat/messages/${m.id}/pin`;
  try {
    const res = m.is_pinned ? await api.delete(url) : await api.post(url);
    applyPinned(res.data.data?.pinned_message ?? null);
  } catch (e) {
    swal.error('ปักหมุดไม่สำเร็จ', e.response?.data?.message || '');
  }
}

/** หนึ่งห้องมีหมุดเดียว — ย้ายธง is_pinned ตามข้อความที่เซิร์ฟเวอร์บอกว่าปักอยู่ */
function applyPinned(pinned) {
  if (room.value) room.value.pinned_message = pinned;
  messages.value.forEach((msg) => {
    msg.is_pinned = pinned ? msg.id === pinned.id : false;
  });
}

async function removeMessage(m) {
  const result = await swal.confirm({
    title: 'ลบข้อความนี้?',
    text: 'ลูกค้าในห้องจะเห็นเป็น "ข้อความนี้ถูกลบแล้ว"',
    icon: 'warning',
    confirmText: 'ลบข้อความ',
  });
  if (!result.isConfirmed) return;

  try {
    const res = await api.delete(`/schedules/${activeId.value}/chat/messages/${m.id}`);
    replaceMessage(res.data.data);
  } catch (e) {
    swal.error('ลบไม่สำเร็จ', e.response?.data?.message || '');
  }
}

async function react(m, emoji) {
  try {
    const res = await api.post(`/schedules/${activeId.value}/chat/messages/${m.id}/react`, { emoji });
    m.reactions = res.data.data?.reactions || [];
  } catch (e) {
    swal.error('รีแอกชันไม่สำเร็จ', e.response?.data?.message || '');
  }
}

async function closePoll(m) {
  try {
    const res = await api.post(`/schedules/${activeId.value}/chat/polls/${m.poll.id}/close`);
    m.poll = res.data.data?.poll || m.poll;
  } catch (e) {
    swal.error('ปิดโพลไม่สำเร็จ', e.response?.data?.message || '');
  }
}

function jumpTo(id) {
  const el = document.getElementById(`msg-${id}`);
  if (!el) {
    swal.alert({ title: 'ข้อความอยู่ในประวัติเก่า', text: 'กด "โหลดข้อความเก่า" ด้านบนก่อนนะครับ' });

    return;
  }
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  highlightId.value = id;
  setTimeout(() => {
    if (highlightId.value === id) highlightId.value = null;
  }, 1600);
}

function replaceMessage(payload) {
  const index = messages.value.findIndex((m) => m.id === payload.id);
  if (index === -1) return;
  // ข้อความที่ broadcast มาไม่รู้ว่าใครเป็นเจ้าของ — คงค่าเดิมของเราไว้
  messages.value[index] = { ...payload, is_mine: messages.value[index].is_mine };
}

// อัปเดต preview/ตัวนับในรายการห้องด้านซ้ายเมื่อมีข้อความใหม่
function bumpConversation(msg) {
  const scheduleId = msg.schedule_id || activeId.value;
  const conv = conversations.value.find((c) => c.schedule_id === scheduleId);
  if (!conv) return;
  conv.message_count = (conv.message_count || 0) + 1;
  conv.needs_reply = msg.sender_role === 'customer';
  conv.last_activity = msg.created_at;
  conv.last_message = {
    body: msg.body,
    image_url: msg.image_url,
    sender_role: msg.sender_role,
    sender_name: msg.user?.nickname || msg.user?.name,
    created_at: msg.created_at,
  };
}

function markRead() {
  api.post(`/schedules/${activeId.value}/chat/read`).catch(() => {});
}

function subscribe(scheduleId) {
  if (!window.Echo) return;
  currentChannel = window.Echo.private(`chat.schedule.${scheduleId}`)
    .listen('.chat.message', (data) => {
      messages.value.push({ ...data, is_mine: false });
      scrollToBottom();
      markRead();
      bumpConversation(data);
    })
    .listen('.chat.message.updated', (data) => replaceMessage(data))
    .listen('.chat.pinned', (data) => applyPinned(data.message ?? null))
    .listen('.chat.reaction', (data) => {
      const target = messages.value.find((m) => m.id === data.message_id);
      if (target) target.reactions = data.reactions || [];
    })
    .listen('.chat.read', (data) => {
      const member = room.value?.members?.find((m) => m.id === data.user_id);
      if (member) member.last_read_message_id = data.last_read_message_id;
    })
    .listen('.chat.typing', (data) => showTyping(data))
    .subscribed(() => { wsConnected.value = true; })
    .error(() => { wsConnected.value = false; });
}

function showTyping(data) {
  const existing = typingUsers.value[data.user_id];
  if (existing) clearTimeout(existing.timer);
  typingUsers.value = {
    ...typingUsers.value,
    [data.user_id]: {
      name: data.name,
      timer: setTimeout(() => {
        const rest = { ...typingUsers.value };
        delete rest[data.user_id];
        typingUsers.value = rest;
      }, 4000),
    },
  };
}

function leaveChannel() {
  if (currentChannel && activeId.value && window.Echo) {
    window.Echo.leave(`chat.schedule.${activeId.value}`);
  }
  currentChannel = null;
  wsConnected.value = false;
}

function scrollToBottom() {
  nextTick(() => {
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight;
  });
}

watch(draft, autoGrow);

onMounted(() => {
  loadConversations();
  // ห้องอื่นไม่ได้ต่อ socket ไว้ — รีเฟรชเงียบ ๆ เพื่อให้ "รอตอบ" ไม่ค้างของเก่า
  listTimer = setInterval(() => loadConversations(true), 60000);
});

onBeforeUnmount(() => {
  leaveChannel();
  clearPendingImage();
  if (listTimer) clearInterval(listTimer);
  Object.values(typingUsers.value).forEach((t) => clearTimeout(t.timer));
});
</script>

<style scoped>
.chat-page {
  display: flex;
  height: calc(100vh - 120px);
  gap: 16px;
}

/* ─── Sidebar ──────────────────────────────────────── */
.chat-sidebar {
  width: 340px;
  flex-shrink: 0;
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.chat-sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px;
  border-bottom: 1px solid #f0f0f0;
}
.chat-sidebar-header h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1f2937; }
.refresh-btn { border: none; background: #f3f4f6; border-radius: 8px; padding: 8px 10px; cursor: pointer; color: #4b5563; }
.refresh-btn:hover { background: #e5e7eb; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.sidebar-search {
  position: relative;
  display: flex;
  align-items: center;
  padding: 10px 12px;
  border-bottom: 1px solid #f5f5f5;
}
.sidebar-search > i.fa-search { position: absolute; left: 22px; color: #9ca3af; font-size: 12px; }
.sidebar-search input {
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 8px 32px;
  font-size: 13px;
  outline: none;
}
.sidebar-search input:focus { border-color: #2D7A4F; }
.clear-search { position: absolute; right: 20px; border: none; background: none; color: #9ca3af; cursor: pointer; }

.sidebar-tabs { display: flex; padding: 8px 12px; gap: 6px; border-bottom: 1px solid #f5f5f5; }
.sidebar-tabs button {
  flex: 1;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 8px;
  padding: 7px 6px;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  transition: all 0.15s;
}
.sidebar-tabs button.active { background: #2D7A4F; border-color: #2D7A4F; color: #fff; }
.tab-badge {
  background: rgba(0,0,0,0.08);
  border-radius: 999px;
  padding: 0 7px;
  font-size: 11px;
  min-width: 18px;
}
.tab-badge.alert { background: #fee2e2; color: #b91c1c; }
.sidebar-tabs button.active .tab-badge { background: rgba(255,255,255,0.25); color: inherit; }

.conv-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; }
.conv-item {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid #f5f5f5;
  cursor: pointer;
  transition: background 0.15s;
}
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: #ecfdf5; box-shadow: inset 3px 0 0 #2D7A4F; }
.conv-thumb {
  width: 48px; height: 48px;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center;
  color: #2D7A4F;
}
.conv-thumb img { width: 100%; height: 100%; object-fit: cover; }
.conv-content { flex: 1; min-width: 0; }
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.conv-title { font-weight: 800; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-time { font-size: 11px; color: #9ca3af; flex-shrink: 0; }
.conv-sub { font-size: 11px; color: #6b7280; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.conv-sub i { font-size: 10px; }
.conv-seats { display: inline-flex; align-items: center; gap: 3px; }
.conv-nostaff { display: inline-flex; align-items: center; gap: 3px; color: #b45309; font-weight: 700; }
.day-chip { border-radius: 999px; padding: 1px 7px; font-size: 10.5px; font-weight: 800; background: #f3f4f6; color: #6b7280; }
.day-now { background: #fee2e2; color: #b91c1c; }
.day-soon { background: #fef3c7; color: #b45309; }
.day-later { background: #eef2ff; color: #4338ca; }
.day-past { background: #f3f4f6; color: #9ca3af; }
.day-none { display: none; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 3px; }
.conv-preview { font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.conv-preview.muted { color: #b0b6c0; font-style: italic; }
.preview-img-icon { font-size: 10px; margin-right: 2px; }
.conv-flags { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; }
.reply-chip { background: #fee2e2; color: #b91c1c; font-size: 10.5px; font-weight: 800; border-radius: 999px; padding: 1px 7px; }
.conv-count {
  background: #e5e7eb;
  color: #4b5563;
  font-size: 11px;
  font-weight: 700;
  border-radius: 999px;
  padding: 1px 8px;
}
.conv-count.unread { background: #2D7A4F; color: #fff; }
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }

/* ─── Main ─────────────────────────────────────────── */
.chat-main { flex: 1; min-width: 0; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.chat-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 10px; }
.chat-empty i { font-size: 48px; }
.chat-empty p { margin: 0; font-size: 15px; font-weight: 700; color: #9ca3af; }
.chat-empty span { font-size: 12.5px; }

.chat-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
.chat-header-info { display: flex; align-items: center; gap: 12px; min-width: 0; }
.header-text { min-width: 0; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.chat-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-sub { font-size: 12px; color: #6b7280; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.header-vehicle, .header-members { color: #2D7A4F; font-weight: 700; }
.header-vehicle i, .header-members i { font-size: 11px; }
.header-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.icon-btn {
  border: 1px solid #e5e7eb; background: #fff; color: #6b7280;
  width: 34px; height: 34px; border-radius: 8px; cursor: pointer;
}
.icon-btn:hover { background: #f3f4f6; }
.icon-btn.on { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }
.ws-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11.5px; font-weight: 700; color: #9ca3af;
  background: #f3f4f6; border-radius: 999px; padding: 4px 10px;
}
.ws-pill.on { color: #15803d; background: #ecfdf5; }
.ws-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
.ws-pill.on .ws-dot { background: #22c55e; }

.pinned-bar {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 20px; background: #fffbeb; border-bottom: 1px solid #fde68a;
  font-size: 12.5px; color: #92400e; cursor: pointer;
}
.pinned-bar > i { flex-shrink: 0; }
.pinned-text { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pinned-text strong { margin-right: 6px; }
.pinned-unpin { border: none; background: none; color: #92400e; cursor: pointer; padding: 2px 4px; }

.chat-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 4px; background: #fafafa; }
.load-older { text-align: center; margin-bottom: 10px; }
.load-older button {
  border: 1px solid #e5e7eb; background: #fff; color: #4b5563;
  border-radius: 999px; padding: 6px 16px; font-size: 12px; font-weight: 700; cursor: pointer;
}
.load-older button:hover { background: #f3f4f6; }
.date-divider { text-align: center; margin: 12px 0 6px; }
.date-divider span { background: #eceff1; color: #6b7280; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 3px 12px; }

.msg-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 6px; border-radius: 12px; }
.msg-row.mine { justify-content: flex-end; }
.msg-row.highlight, .system-row.highlight { background: #fff7cc; }
.msg-avatar { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0; }
.msg-avatar img { width: 100%; height: 100%; object-fit: cover; }
.msg-avatar.spacer { background: transparent; }
.msg-col { display: flex; flex-direction: column; max-width: 70%; min-width: 0; }
.msg-author { font-size: 11.5px; font-weight: 800; color: #374151; margin-bottom: 3px; margin-left: 2px; display: flex; gap: 6px; align-items: center; }
.role-tag { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
.role-tag.role-staff { background: #ecfeff; color: #0e7490; }
.role-tag.role-admin { background: #fef3c7; color: #b45309; }
.role-tag.role-customer { background: #eef2ff; color: #4338ca; }

.msg-line { display: flex; align-items: center; gap: 6px; position: relative; }
.msg-row.mine .msg-line { flex-direction: row-reverse; }
.msg-bubble { padding: 9px 13px; border-radius: 14px; background: #fff; border: 1px solid #eceff1; min-width: 0; }
.msg-row.mine .msg-bubble { background: #2D7A4F; color: #fff; border-color: #2D7A4F; }
.msg-bubble.deleted { background: #f3f4f6; border-color: #e5e7eb; }
.msg-row.mine .msg-bubble.deleted { background: #e5e7eb; color: #6b7280; }
.deleted-text { font-style: italic; opacity: 0.8; }
.msg-image { margin: -2px 0 4px; cursor: pointer; border-radius: 10px; overflow: hidden; }
.msg-image img { display: block; max-width: 240px; max-height: 240px; width: 100%; object-fit: cover; transition: transform 0.2s; }
.msg-image:hover img { transform: scale(1.02); }
.msg-body { font-size: 13.5px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.msg-time { font-size: 10px; opacity: 0.65; margin-top: 4px; text-align: right; display: flex; gap: 5px; justify-content: flex-end; align-items: center; }
.edited-tag { font-style: italic; }
.pin-mark { font-size: 9px; }

.reply-quote {
  border-left: 3px solid #2D7A4F;
  background: rgba(45,122,79,0.07);
  border-radius: 6px;
  padding: 4px 8px;
  margin-bottom: 5px;
  font-size: 11.5px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.msg-row.mine .reply-quote { border-left-color: rgba(255,255,255,0.7); background: rgba(255,255,255,0.15); }
.reply-author { font-weight: 800; }
.reply-body { opacity: 0.85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }

.poll-card { margin-top: 6px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 10px; background: #fff; color: #1f2937; }
.poll-question { font-size: 12.5px; font-weight: 800; margin-bottom: 6px; }
.poll-option { position: relative; padding: 5px 8px; margin-bottom: 4px; border-radius: 6px; background: #f3f4f6; display: flex; justify-content: space-between; font-size: 12px; overflow: hidden; }
.poll-bar { position: absolute; inset: 0 auto 0 0; background: #d1f0df; }
.poll-label, .poll-count { position: relative; }
.poll-foot { display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: #6b7280; margin-top: 4px; }
.poll-foot button { border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-size: 11px; padding: 2px 8px; cursor: pointer; }

.msg-actions {
  display: none;
  align-items: center;
  gap: 2px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 2px 4px;
  position: relative;
}
.msg-row:hover .msg-actions { display: flex; }
.msg-actions button { border: none; background: none; color: #6b7280; cursor: pointer; font-size: 11.5px; padding: 4px 6px; border-radius: 50%; }
.msg-actions button:hover { background: #f3f4f6; color: #1f2937; }
.msg-actions .fa-thumbtack.pinned { color: #d97706; }
.react-pop {
  display: none;
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 4px 6px;
  gap: 2px;
  z-index: 5;
}
.msg-actions:hover .react-pop { display: flex; }
.react-choice { font-size: 15px; line-height: 1; padding: 3px 4px; }

.reaction-row { display: flex; gap: 4px; margin-top: 3px; margin-left: 4px; }
.reaction-row.mine { justify-content: flex-end; margin-right: 4px; }
.reaction-pill {
  border: 1px solid #e5e7eb; background: #fff; border-radius: 999px;
  padding: 1px 8px; font-size: 11.5px; cursor: pointer; color: #4b5563;
}
.reaction-pill:hover { background: #f3f4f6; }

.system-row { display: flex; justify-content: center; margin: 10px 0; border-radius: 12px; }
.system-bubble {
  background: #eef6f1; border: 1px solid #d1f0df; border-radius: 12px;
  padding: 10px 14px; max-width: 85%; width: 100%; color: #1f513a;
}
.system-head { display: flex; align-items: center; justify-content: space-between; font-size: 11px; font-weight: 800; opacity: 0.8; margin-bottom: 5px; }
.msg-actions-inline button { border: none; background: none; color: #1f513a; cursor: pointer; font-size: 11px; padding: 2px 5px; }
.msg-actions-inline .fa-thumbtack.pinned { color: #d97706; }
.system-body { font-size: 13px; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }

.read-receipt { text-align: right; font-size: 11px; color: #9ca3af; margin-top: 6px; padding-right: 4px; }
.typing-line { padding: 4px 20px 0; font-size: 11.5px; color: #9ca3af; font-style: italic; }

/* ─── Composer ─────────────────────────────────────── */
.chat-composer { border-top: 1px solid #f0f0f0; }
.quick-actions { display: flex; align-items: center; gap: 8px; padding: 10px 16px 0; flex-wrap: wrap; }
.quick-actions button {
  border: 1px solid #d1f0df; background: #f4fbf7; color: #1f513a;
  border-radius: 999px; padding: 5px 12px; font-size: 12px; font-weight: 700; cursor: pointer;
}
.quick-actions button:hover:not(:disabled) { background: #e7f5ee; }
.quick-actions button:disabled { opacity: 0.5; cursor: not-allowed; }
.quick-hint { font-size: 11px; color: #9ca3af; }

.reply-bar { display: flex; align-items: center; gap: 10px; margin: 10px 16px 0; padding: 8px 12px; background: #f3f4f6; border-radius: 10px; border-left: 3px solid #2D7A4F; }
.reply-bar > i { color: #2D7A4F; font-size: 12px; }
.reply-bar-text { flex: 1; min-width: 0; display: flex; flex-direction: column; font-size: 12px; color: #4b5563; }
.reply-bar-text span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-bar button { border: none; background: none; color: #9ca3af; cursor: pointer; }

.image-preview-bar { padding: 10px 16px 0; }
.image-preview { position: relative; display: inline-block; }
.image-preview img { max-height: 80px; border-radius: 10px; border: 1px solid #e5e7eb; }
.remove-preview {
  position: absolute; top: -8px; right: -8px;
  width: 22px; height: 22px; border-radius: 50%;
  border: none; background: #ef4444; color: #fff; cursor: pointer;
  font-size: 11px; display: flex; align-items: center; justify-content: center;
}
.chat-input { display: flex; align-items: flex-end; gap: 10px; padding: 12px 16px 14px; }
.attach-btn {
  width: 40px; height: 40px; border-radius: 50%;
  background: #f3f4f6; color: #4b5563;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0; transition: background 0.15s;
}
.attach-btn:hover { background: #e5e7eb; }
.attach-btn.disabled { opacity: 0.5; cursor: not-allowed; }
.chat-input textarea {
  flex: 1;
  border: 1px solid #d1d5db;
  border-radius: 18px;
  padding: 11px 16px;
  font-size: 14px;
  font-family: inherit;
  line-height: 1.5;
  outline: none;
  resize: none;
  max-height: 168px;
  overflow-y: auto;
}
.chat-input textarea:focus { border-color: #2D7A4F; }
.send-btn { border: none; background: #2D7A4F; color: #fff; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; flex-shrink: 0; }
.send-btn:disabled { background: #cbd5e1; cursor: not-allowed; }

/* ─── Info panel ───────────────────────────────────── */
.chat-info {
  width: 300px;
  flex-shrink: 0;
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}
.info-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f0f0f0; position: sticky; top: 0; background: #fff; z-index: 2; }
.info-head h4 { margin: 0; font-size: 14px; font-weight: 800; color: #1f2937; }
.info-block { padding: 14px 16px; border-bottom: 1px solid #f5f5f5; }
.info-block h5 { margin: 0 0 10px; font-size: 12px; font-weight: 800; color: #6b7280; display: flex; align-items: center; gap: 6px; }
.info-stats { display: flex; gap: 8px; margin-bottom: 12px; }
.stat { flex: 1; background: #f9fafb; border-radius: 10px; padding: 8px 10px; }
.stat-num { display: block; font-size: 17px; font-weight: 800; color: #2D7A4F; }
.stat-label { font-size: 10.5px; color: #9ca3af; }
.info-rows { display: flex; flex-direction: column; gap: 6px; }
.info-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12.5px; color: #6b7280; }
.info-row strong { color: #1f2937; font-weight: 700; text-align: right; }
.info-row a { text-decoration: none; color: #2D7A4F; }
.info-link {
  display: inline-flex; align-items: center; gap: 6px; margin-top: 12px;
  font-size: 12px; font-weight: 700; color: #2D7A4F; text-decoration: none;
}
.info-link:hover { text-decoration: underline; }

.weather-line { display: flex; flex-direction: column; gap: 2px; font-size: 12.5px; color: #6b7280; }
.weather-line strong { color: #1f2937; }

.pickup-item { border-top: 1px solid #f5f5f5; padding-top: 8px; margin-top: 8px; }
.pickup-item:first-of-type { border-top: none; padding-top: 0; margin-top: 0; }
.pickup-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12.5px; color: #1f2937; }
.pickup-time { background: #ecfdf5; color: #15803d; border-radius: 6px; padding: 1px 7px; font-size: 11px; font-weight: 800; flex-shrink: 0; }
.pickup-sub, .pickup-note { font-size: 11.5px; color: #6b7280; margin-top: 2px; }
.pickup-note { font-style: italic; }
.pickup-map { display: inline-block; margin-top: 4px; font-size: 11.5px; color: #2D7A4F; text-decoration: none; }

.member-row { display: flex; align-items: center; gap: 9px; padding: 5px 0; }
.member-row img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.member-text { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.member-text strong { font-size: 12.5px; color: #1f2937; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.member-realname { font-size: 11px; color: #9ca3af; }
.member-phone { font-size: 11.5px; color: #2D7A4F; text-decoration: none; }
.read-mark { font-size: 11px; color: #22c55e; flex-shrink: 0; }

/* ─── Lightbox ─────────────────────────────────────── */
.lightbox {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.85);
  display: flex; align-items: center; justify-content: center;
  padding: 40px;
}
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 10px; }
.lightbox-close {
  position: absolute; top: 24px; right: 28px;
  background: rgba(255,255,255,0.15); color: #fff; border: none;
  width: 44px; height: 44px; border-radius: 50%; cursor: pointer; font-size: 18px;
}
.lightbox-close:hover { background: rgba(255,255,255,0.28); }

@media (max-width: 1400px) {
  .chat-sidebar { width: 300px; }
  .chat-info { width: 270px; }
}
</style>

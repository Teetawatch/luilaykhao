<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">savings</span> บัญชีทริป</h1>
        <p class="page-subtitle">รายรับจริง + รับหน้างาน − ค่าใช้จ่าย = กำไร ต่อทริปและต่อรอบเดินทาง</p>
      </div>
      <div class="view-switch">
        <button :class="{ active: !showMonthsModal }" @click="showMonthsModal = false">ต่อทริป</button>
        <button :class="{ active: showMonthsModal }" @click="showMonths">รายเดือน</button>
      </div>
    </div>

    <!-- โหมดเข้มงวดกำลังบังคับอะไรอยู่ — บอกไว้ก่อนที่คนจะไปเจอ error ตอนกดบันทึก -->
    <div class="strict-banner" v-if="rules.strict">
      <span class="material-symbols-rounded">verified_user</span>
      <span>
        โหมดบัญชีเข้มงวด:
        <template v-if="rules.require_category">ทุกรายการต้องระบุหมวด · </template>
        <template v-if="rules.slip_required_above">รายจ่ายเกิน {{ formatMoney(rules.slip_required_above) }} ต้องแนบสลิป · </template>
        ปิดงบแล้วแก้ได้เฉพาะแอดมินและต้องมีเหตุผล
      </span>
    </div>

    <!-- รอบที่ค้างปิดงบ — งานค้างที่ต้องเคลียร์ก่อนตัวเลขจะเชื่อได้ -->
    <div class="overdue-card" v-if="overdue.count">
      <div class="overdue-head">
        <span class="material-symbols-rounded">lock_clock</span>
        <b>ค้างปิดงบ {{ overdue.count }} รอบ</b>
        <span class="overdue-meta">
          เดินทางจบเกิน {{ overdue.grace_days }} วันแล้วแต่ยังไม่ปิดงบ
          <template v-if="overdue.blocks_new_rounds"> · ทริปเหล่านี้เปิดรอบใหม่ไม่ได้จนกว่าจะเคลียร์</template>
        </span>
      </div>
      <div class="overdue-list">
        <button v-for="r in overdue.rounds" :key="r.schedule_id" class="overdue-row" @click="jumpToRound(r)">
          <span class="overdue-trip">{{ r.trip_title }}</span>
          <span class="overdue-date">{{ r.departure_label }}</span>
          <span class="overdue-days">จบมาแล้ว {{ r.days_since_end }} วัน</span>
          <span v-if="!r.expense_items_count" class="warn-text">ยังไม่มีรายการค่าใช้จ่ายเลย</span>
          <span class="material-symbols-rounded overdue-go">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="form-group">
        <label>จากวันเดินทาง</label>
        <input v-model="filters.from" type="date" />
      </div>
      <div class="form-group">
        <label>ถึงวันเดินทาง</label>
        <input v-model="filters.to" type="date" />
      </div>
      <button class="btn-primary" @click="loadSummary" :disabled="loading">
        <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">{{ loading ? 'sync' : 'search' }}</span>
        แสดงผล
      </button>
      <button class="btn-secondary" @click="clearFilters" v-if="filters.from || filters.to">ล้างช่วงวัน</button>
    </div>

    <!-- Summary cards -->
    <div class="summary-cards" v-if="summary">
      <div class="sc-item">
        <span class="sc-label">รายรับจริง</span>
        <span class="sc-val money-green">{{ formatMoney(summary.paid_revenue) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">รับหน้างาน</span>
        <span class="sc-val money-green">{{ formatMoney(summary.onsite_income) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">ค่าใช้จ่ายรวม</span>
        <span class="sc-val money-orange">{{ formatMoney(summary.expense_total) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">กำไรสุทธิ</span>
        <span class="sc-val" :class="profitClass(summary.profit)">{{ formatMoney(summary.profit) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">ค้างรับจากลูกค้า</span>
        <span class="sc-val money-red">{{ formatMoney(summary.outstanding) }}</span>
        <span class="sc-hint">เก็บครบแล้วกำไรจะเป็น {{ formatMoney(summary.potential_profit) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">รอบที่ยังไม่ปิดงบ</span>
        <span class="sc-val" :class="summary.open_rounds ? 'money-orange' : 'money-green'">{{ summary.open_rounds }}</span>
        <span class="sc-hint">{{ summary.period }}</span>
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <!-- Trip table -->
    <div class="table-card" v-if="!loading && trips.length">
      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th></th>
              <th>ทริป</th>
              <th class="num">จอง</th>
              <th class="num">ผู้เดินทาง</th>
              <th class="num">รายรับจริง</th>
              <th class="num">รับหน้างาน</th>
              <th class="num">ค่าใช้จ่าย</th>
              <th class="num">ค้างรับ</th>
              <th class="num">กำไร</th>
              <th class="num">มาร์จิน</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="t in trips" :key="t.trip_id">
              <tr class="trip-row" @click="toggleTrip(t)">
                <td><span class="material-symbols-rounded expand-icon" :class="{ open: expanded === t.trip_id }">chevron_right</span></td>
                <td class="trip-title">{{ t.title }}</td>
                <td class="num">{{ t.bookings_count }}</td>
                <td class="num">{{ t.passengers_count }}</td>
                <td class="num money-green">{{ formatMoney(t.paid_revenue) }}</td>
                <td class="num money-green">{{ formatMoney(t.onsite_income) }}</td>
                <td class="num money-orange">{{ formatMoney(t.expense_total) }}</td>
                <td class="num" :class="t.outstanding > 0 ? 'money-red' : ''">{{ t.outstanding > 0 ? formatMoney(t.outstanding) : '-' }}</td>
                <td class="num" :class="profitClass(t.profit)">
                  {{ formatMoney(t.profit) }}
                  <!-- ยังมีรอบที่ยังไม่ปิดงบ = ตัวเลขนี้ยังขยับได้ อย่าเพิ่งเอาไปใช้ตัดสินใจ -->
                  <span v-if="t.open_rounds" class="tmpl-chip chip-open" :title="`${t.open_rounds} รอบยังไม่ปิดงบ`">ยังไม่ปิด {{ t.open_rounds }}</span>
                </td>
                <td class="num">{{ t.margin_percent != null ? t.margin_percent + '%' : '-' }}</td>
                <td>
                  <button class="btn-icon" title="รายการประจำของทริป" @click.stop="openTemplates(t)">
                    <span class="material-symbols-rounded" style="font-size:18px;">repeat</span>
                  </button>
                </td>
              </tr>

              <!-- Expanded: schedules -->
              <tr v-if="expanded === t.trip_id" class="detail-row">
                <td :colspan="11">
                  <div class="schedule-loading" v-if="detailLoading"><div class="spinner spinner-sm"></div></div>
                  <div v-else-if="detail" class="schedule-list">
                    <div v-if="!detail.schedules.length" class="empty-state">
                      <span class="material-symbols-rounded">event_busy</span>
                      <p>ยังไม่มีรอบเดินทาง</p>
                    </div>
                    <template v-else>
                      <div class="sched-list-head">
                        <span class="sched-count">{{ detail.schedules.length }} รอบเดินทาง (รวมรอบที่ผ่านมาแล้ว)</span>
                      </div>
                      <div class="sched-scroll">
                        <div v-for="s in detail.schedules" :key="s.schedule_id" class="sched-card">
                          <div class="sched-head">
                            <div class="sched-date">
                              <span class="material-symbols-rounded">calendar_month</span>
                              {{ s.departure_label }}
                              <span class="status-badge" :class="`status-${s.status}`">{{ s.status }}</span>
                              <span v-if="s.is_closed" class="lock-badge" :title="`ปิดงบโดย ${s.closed_by_name || '-'}`">
                                <span class="material-symbols-rounded">lock</span> ปิดงบแล้ว
                              </span>
                            </div>
                            <div class="sched-actions">
                              <button class="btn-secondary btn-xs" @click="openExpenses(t, s)">
                                <span class="material-symbols-rounded" style="font-size:16px;">receipt_long</span>
                                จัดการค่าใช้จ่าย ({{ s.expenses.length }})
                              </button>
                              <button class="btn-secondary btn-xs" @click="openAudits(s)" title="ปูมการแก้ไข">
                                <span class="material-symbols-rounded" style="font-size:16px;">history</span>
                              </button>
                              <button class="btn-secondary btn-xs" @click="exportCsv(s)" title="ดาวน์โหลด CSV">
                                <span class="material-symbols-rounded" style="font-size:16px;">download</span>
                              </button>
                            </div>
                          </div>
                          <div class="sched-figures">
                            <span>รายรับจริง <b class="money-green">{{ formatMoney(s.paid_revenue) }}</b></span>
                            <span v-if="s.onsite_income">รับหน้างาน <b class="money-green">{{ formatMoney(s.onsite_income) }}</b></span>
                            <span>ค่าใช้จ่าย <b class="money-orange">{{ formatMoney(s.expense_total) }}</b></span>
                            <span v-if="s.outstanding > 0">ค้างรับ <b class="money-red">{{ formatMoney(s.outstanding) }}</b></span>
                            <span>กำไร <b :class="profitClass(s.profit)">{{ formatMoney(s.profit) }}</b></span>
                            <span class="sched-pax">{{ s.passengers_count }} คน · {{ s.bookings_count }} จอง</span>
                          </div>
                          <div class="sched-figures sched-sub">
                            <span v-if="s.cost_per_pax != null">ต้นทุนต่อหัว <b>{{ formatMoney(s.cost_per_pax) }}</b></span>
                            <span v-if="s.break_even_pax != null">คุ้มทุนที่ <b>{{ s.break_even_pax }}</b> ที่นั่ง</span>
                            <span v-if="s.budget != null">
                              งบ <b>{{ formatMoney(s.budget) }}</b>
                              <b :class="s.over_budget ? 'money-red' : 'money-green'">
                                ({{ s.over_budget ? 'เกิน' : 'เหลือ' }} {{ formatMoney(Math.abs(s.budget_variance)) }})
                              </b>
                            </span>
                            <span v-if="s.missing_slip_count" class="warn-text">{{ s.missing_slip_count }} รายการไม่มีสลิป</span>
                            <span v-if="s.uncategorised_count" class="warn-text">{{ s.uncategorised_count }} รายการไม่มีหมวด</span>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="!loading && !trips.length" class="empty-state">
      <span class="material-symbols-rounded">query_stats</span>
      <p>ยังไม่มีข้อมูลรายรับหรือค่าใช้จ่ายในช่วงที่เลือก</p>
    </div>

    <!-- ─── Expense editor modal ─── -->
    <div class="modal-overlay" v-if="showExpenses">
      <div class="modal-card">
        <div class="modal-header">
          <h2>ค่าใช้จ่าย — {{ activeSchedule?.departure_label }}</h2>
          <button class="modal-close" @click="closeExpenses"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="exp-summary" v-if="expSummary">
            <span>ยอดจองเต็ม <b>{{ formatMoney(expSummary.booked_total) }}</b></span>
            <span>รายรับจริง <b class="money-green">{{ formatMoney(expSummary.paid_revenue) }}</b></span>
            <span v-if="expSummary.outstanding > 0">ค้างรับ <b class="money-red">{{ formatMoney(expSummary.outstanding) }}</b></span>
            <span>รับหน้างาน <b class="money-green">{{ formatMoney(expSummary.onsite_income) }}</b></span>
            <span>ค่าใช้จ่าย <b class="money-orange">{{ formatMoney(expSummary.expense_total) }}</b></span>
            <span>กำไร <b :class="profitClass(expSummary.profit)">{{ formatMoney(expSummary.profit) }}</b></span>
          </div>

          <!-- ปิดงบ: เงื่อนไขที่ยังค้างอยู่ต้องเคลียร์ก่อน ไม่ใช่กดแล้วเด้ง error -->
          <div class="close-panel" :class="{ locked: expSummary?.is_closed }" v-if="expSummary">
            <template v-if="expSummary.is_closed">
              <div class="close-head">
                <span class="material-symbols-rounded">lock</span>
                <b>ปิดงบแล้ว</b>
                <span class="close-meta">{{ formatDateTime(expSummary.closed_at) }} · {{ expSummary.closed_by_name || '-' }}</span>
                <button class="btn-secondary btn-xs" @click="doReopen" :disabled="expBusy">เปิดงบกลับ</button>
              </div>
              <p class="close-hint">ตัวเลขของรอบนี้ล็อกแล้ว การแก้ไขต่อจากนี้ต้องกรอกเหตุผลในฟอร์มด้านล่าง และถูกบันทึกลงปูมทุกครั้ง</p>
            </template>
            <template v-else>
              <div class="close-head">
                <span class="material-symbols-rounded" :class="closeCheck?.can_close ? 'ok' : 'warn'">
                  {{ closeCheck?.can_close ? 'task_alt' : 'error' }}
                </span>
                <b>{{ closeCheck?.can_close ? 'พร้อมปิดงบ' : 'ยังปิดงบไม่ได้' }}</b>
                <button class="btn-primary btn-xs" @click="doClose" :disabled="expBusy || !closeCheck?.can_close">ปิดงบรอบนี้</button>
              </div>
              <ul class="close-list" v-if="closeCheck">
                <li v-for="b in closeCheck.blockers" :key="b.code" class="blocker">
                  <span class="material-symbols-rounded">block</span>{{ b.message }}
                </li>
                <li v-for="w in closeCheck.warnings" :key="w.code" class="warning">
                  <span class="material-symbols-rounded">info</span>{{ w.message }}
                </li>
              </ul>
            </template>
          </div>

          <div class="exp-toolbar">
            <button class="btn-secondary btn-xs" @click="applyTemplates" :disabled="expBusy || expSummary?.is_closed">
              <span class="material-symbols-rounded" style="font-size:16px;">playlist_add</span>
              ใช้รายการประจำ
            </button>
            <button class="btn-secondary btn-xs" @click="applyStaffCost" :disabled="expBusy || expSummary?.is_closed" title="ลงค่าตอบแทนทีมงานจากเรตต่อวันที่ตั้งไว้">
              <span class="material-symbols-rounded" style="font-size:16px;">groups</span>
              ลงค่าทีมงาน
            </button>
            <button class="btn-secondary btn-xs" @click="openCopy" :disabled="expBusy || !selectedIds.length" title="เลือกรายการในตารางก่อน">
              <span class="material-symbols-rounded" style="font-size:16px;">content_copy</span>
              คัดลอกไปรอบอื่น<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
            </button>
            <div class="budget-box">
              <label>งบรอบนี้</label>
              <input v-model.number="budgetInput" type="number" step="0.01" min="0" placeholder="ตามรายการประจำ" />
              <button class="btn-secondary btn-xs" @click="saveBudget" :disabled="expBusy">บันทึก</button>
              <b v-if="expSummary?.budget != null" :class="expSummary.over_budget ? 'money-red' : 'money-green'">
                ใช้ไป {{ expSummary.budget_used_percent }}%
              </b>
            </div>
          </div>

          <div class="loading-state" v-if="expLoading"><div class="spinner spinner-sm"></div></div>
          <table class="data-table exp-table" v-else>
            <thead>
              <tr>
                <th class="check-col"><input type="checkbox" :checked="allSelected" @change="toggleSelectAll" :disabled="!expenses.length" /></th>
                <th>รายการ</th><th class="num">จำนวนเงิน</th><th>หมายเหตุ</th><th>สลิป</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in expenses" :key="e.id" :class="{ 'row-selected': selectedIds.includes(e.id) }">
                <td class="check-col"><input type="checkbox" :value="e.id" v-model="selectedIds" /></td>
                <td>
                  {{ e.name }}
                  <span v-if="e.kind === 'income'" class="tmpl-chip chip-income" title="รายรับหน้างาน">รายรับ</span>
                  <span v-if="e.expense_template_id" class="tmpl-chip" title="มาจากรายการประจำ">ประจำ</span>
                  <span v-if="e.category_label" class="tmpl-chip">{{ e.category_label }}</span>
                  <!-- สตาฟจดจากหน้างาน: บอกวันที่ใช้เงินกับคนจด เพื่อไล่ที่มาได้ -->
                  <small v-if="e.spent_at || e.created_by_name" class="exp-meta">
                    {{ [e.spent_at, e.created_by_name].filter(Boolean).join(' · ') }}
                  </small>
                </td>
                <td class="num" :class="e.kind === 'income' ? 'money-green' : 'money-orange'">
                  {{ e.kind === 'income' ? '+' : '−' }}{{ formatMoney(e.amount) }}
                </td>
                <td class="exp-note">{{ e.note || '-' }}</td>
                <td>
                  <!-- signed URL อายุสั้น เปิดแท็บใหม่ไปดูรูปเต็ม -->
                  <a v-if="e.slip_url" :href="e.slip_url" target="_blank" rel="noopener" class="btn-icon" title="ดูสลิป">
                    <span class="material-symbols-rounded" style="font-size:16px;">receipt_long</span>
                  </a>
                  <span v-else-if="needsSlip(e)" class="warn-text" title="ยอดเกินเกณฑ์แต่ไม่มีหลักฐาน — ปิดงบไม่ได้">ต้องมีสลิป</span>
                  <span v-else class="exp-note">-</span>
                </td>
                <td class="action-btns">
                  <button class="btn-icon btn-edit" title="แก้ไข" @click="startEdit(e)"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" title="ลบ" @click="removeExpense(e)"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </td>
              </tr>
              <tr v-if="!expenses.length"><td colspan="6" class="exp-empty">ยังไม่มีรายการค่าใช้จ่าย</td></tr>
            </tbody>
          </table>

          <!-- Add / edit form -->
          <form class="exp-form" @submit.prevent="submitExpense">
            <div class="form-group">
              <label>{{ expForm.id ? 'แก้ไขรายการ' : 'เพิ่มรายการ' }}</label>
              <input v-model="expForm.name" placeholder="เช่น ค่าน้ำมัน" required />
            </div>
            <div class="form-group exp-kind">
              <label>ประเภท</label>
              <select v-model="expForm.kind">
                <option value="expense">รายจ่าย</option>
                <option value="income">รายรับหน้างาน</option>
              </select>
            </div>
            <div class="form-group exp-kind">
              <label>หมวด<span class="req" v-if="rules.require_category"> *</span></label>
              <select v-model="expForm.category">
                <option :value="null">— ไม่ระบุ —</option>
                <option v-for="c in categoryOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </div>
            <div class="form-group exp-amount">
              <label>จำนวนเงิน (บาท)</label>
              <input v-model.number="expForm.amount" type="number" step="0.01" min="0" required />
            </div>
            <div class="form-group exp-amount">
              <label>วันที่ใช้เงิน</label>
              <input v-model="expForm.spent_at" type="date" />
            </div>
            <div class="form-group">
              <label>หมายเหตุ</label>
              <input v-model="expForm.note" placeholder="ไม่บังคับ" />
            </div>
            <div class="form-group exp-slip">
              <label :class="{ req: slipRequired }">
                สลิป/ใบเสร็จ<template v-if="slipRequired"> * จำเป็น</template>
              </label>
              <input type="file" accept="image/*" @change="onSlipPicked" />
              <small v-if="expForm.id && expForm.hasSlip && !expForm.slip" class="exp-meta">มีสลิปเดิมอยู่แล้ว — เลือกไฟล์ใหม่เพื่อแทนที่</small>
            </div>
            <!-- ปิดงบไปแล้ว: ทุกการแก้ต้องมีเหตุผล เก็บลงปูมพร้อมยอดก่อน/หลัง -->
            <div class="form-group" v-if="expSummary?.is_closed">
              <label class="req">เหตุผลที่แก้รอบที่ปิดงบแล้ว *</label>
              <input v-model="expForm.reason" placeholder="เช่น ใบเสร็จค่าที่พักมาทีหลัง" />
            </div>
            <div class="exp-form-actions">
              <button type="button" class="btn-secondary btn-xs" v-if="expForm.id" @click="resetExpForm">ยกเลิก</button>
              <button type="submit" class="btn-primary btn-xs" :disabled="expBusy">
                <span class="material-symbols-rounded animate-spin" v-if="expBusy" style="font-size:16px;">sync</span>
                {{ expForm.id ? 'บันทึก' : 'เพิ่ม' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ─── Copy-to-other-rounds modal ─── -->
    <div class="modal-overlay" v-if="showCopy">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>คัดลอกไปรอบอื่น</h2>
          <button class="modal-close" @click="showCopy = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="page-subtitle" style="margin-bottom:12px;">คัดลอก {{ selectedIds.length }} รายการที่เลือก ไปยังรอบเดินทางที่ติ๊กด้านล่าง</p>

          <div v-if="!copyTargets.length" class="exp-empty">ทริปนี้ไม่มีรอบเดินทางอื่นให้คัดลอกไป</div>
          <template v-else>
            <label class="copy-row copy-all">
              <input type="checkbox" :checked="allTargetsSelected" @change="toggleAllTargets" />
              <span>เลือกทุกรอบ</span>
            </label>
            <div class="copy-list">
              <label v-for="s in copyTargets" :key="s.schedule_id" class="copy-row">
                <input type="checkbox" :value="s.schedule_id" v-model="targetIds" />
                <span>{{ s.departure_label }}</span>
                <span class="status-badge" :class="`status-${s.status}`">{{ s.status }}</span>
              </label>
            </div>
          </template>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopy = false">ยกเลิก</button>
          <button class="btn-primary" :disabled="copyBusy || !targetIds.length" @click="doCopy">
            <span class="material-symbols-rounded animate-spin" v-if="copyBusy" style="font-size:16px;">sync</span>
            คัดลอก
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Audit log modal ─── -->
    <div class="modal-overlay" v-if="showAudits">
      <div class="modal-card">
        <div class="modal-header">
          <h2>ปูมการแก้ไข — {{ auditSchedule?.departure_label }}</h2>
          <button class="modal-close" @click="showAudits = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="page-subtitle" style="margin-bottom:12px;">ทุกครั้งที่ตัวเลขเงินของรอบนี้ขยับจะมีบรรทัดที่นี่ — ใครแก้ จากเท่าไรเป็นเท่าไร เพราะอะไร</p>
          <div class="loading-state" v-if="auditLoading"><div class="spinner spinner-sm"></div></div>
          <div v-else class="audit-list">
            <div v-for="a in audits" :key="a.id" class="audit-row" :class="`audit-${a.action}`">
              <div class="audit-head">
                <b>{{ a.action_label }}</b>
                <span>{{ a.after?.name || a.before?.name || '' }}</span>
                <span class="audit-meta">{{ formatDateTime(a.created_at) }} · {{ a.user_name || 'ระบบ' }}</span>
              </div>
              <div class="audit-diff" v-if="a.action === 'updated'">
                {{ formatMoney(a.before?.amount) }} → <b>{{ formatMoney(a.after?.amount) }}</b>
              </div>
              <div class="audit-diff" v-else-if="a.action === 'created'">+{{ formatMoney(a.after?.amount) }}</div>
              <div class="audit-diff" v-else-if="a.action === 'deleted'">−{{ formatMoney(a.before?.amount) }}</div>
              <div class="audit-diff" v-else-if="a.action === 'closed'">
                ปิดที่กำไร <b>{{ formatMoney(a.after?.profit) }}</b> · ค่าใช้จ่าย {{ formatMoney(a.after?.expense_total) }}
              </div>
              <div class="audit-reason" v-if="a.reason">เหตุผล: {{ a.reason }}</div>
            </div>
            <div v-if="!audits.length" class="exp-empty">ยังไม่มีการแก้ไข</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Monthly dashboard modal ─── -->
    <div class="modal-overlay" v-if="showMonthsModal">
      <div class="modal-card">
        <div class="modal-header">
          <h2>กำไรรายเดือน</h2>
          <button class="modal-close" @click="showMonthsModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="loading-state" v-if="monthsLoading"><div class="spinner spinner-sm"></div></div>
          <table class="data-table exp-table" v-else>
            <thead>
              <tr>
                <th>เดือน</th><th class="num">รอบ</th><th class="num">คน</th>
                <th class="num">รายรับ</th><th class="num">ค่าใช้จ่าย</th><th class="num">กำไร</th><th class="num">ค้างรับ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in months" :key="m.month">
                <td>{{ m.label }}</td>
                <td class="num">{{ m.rounds }}<small class="exp-meta" v-if="m.closed_rounds < m.rounds">ปิดงบ {{ m.closed_rounds }}</small></td>
                <td class="num">{{ m.passengers }}</td>
                <td class="num money-green">{{ formatMoney(m.paid_revenue + m.onsite_income) }}</td>
                <td class="num money-orange">{{ formatMoney(m.expense_total) }}</td>
                <td class="num" :class="profitClass(m.profit)">{{ formatMoney(m.profit) }}</td>
                <td class="num" :class="m.outstanding > 0 ? 'money-red' : ''">{{ m.outstanding > 0 ? formatMoney(m.outstanding) : '-' }}</td>
              </tr>
              <tr v-if="!months.length"><td colspan="7" class="exp-empty">ไม่มีรอบเดินทางในช่วงนี้</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─── Templates manager modal ─── -->
    <div class="modal-overlay" v-if="showTemplates">
      <div class="modal-card">
        <div class="modal-header">
          <h2>รายการประจำ — {{ activeTrip?.title }}</h2>
          <button class="modal-close" @click="showTemplates = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="page-subtitle" style="margin-bottom:12px;">รายการที่ตั้งไว้ที่นี่ดึงเข้ารอบเดินทางได้เร็วด้วยปุ่ม "ใช้รายการประจำ"</p>

          <div class="loading-state" v-if="tmplLoading"><div class="spinner spinner-sm"></div></div>
          <table class="data-table exp-table" v-else>
            <thead>
              <tr><th>รายการ</th><th>หมวด</th><th class="num">จำนวนตั้งต้น</th><th>สถานะ</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="tm in templates" :key="tm.id">
                <td>{{ tm.name }}</td>
                <td>{{ categoryLabel(tm.category) }}</td>
                <td class="num">{{ tm.default_amount != null ? formatMoney(tm.default_amount) : '-' }}</td>
                <td><span class="status-badge" :class="tm.is_active ? 'status-active' : 'status-inactive'">{{ tm.is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                <td class="action-btns">
                  <button class="btn-icon btn-edit" title="แก้ไข" @click="startEditTmpl(tm)"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" title="ลบ" @click="removeTemplate(tm)"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </td>
              </tr>
              <tr v-if="!templates.length"><td colspan="5" class="exp-empty">ยังไม่มีรายการประจำ</td></tr>
            </tbody>
          </table>

          <form class="exp-form" @submit.prevent="submitTemplate">
            <div class="form-group">
              <label>{{ tmplForm.id ? 'แก้ไขรายการประจำ' : 'เพิ่มรายการประจำ' }}</label>
              <input v-model="tmplForm.name" placeholder="เช่น ค่าน้ำมัน" required />
            </div>
            <div class="form-group exp-kind">
              <label>หมวด</label>
              <select v-model="tmplForm.category">
                <option :value="null">— ไม่ระบุ —</option>
                <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </div>
            <div class="form-group exp-amount">
              <label>จำนวนตั้งต้น</label>
              <input v-model.number="tmplForm.default_amount" type="number" step="0.01" min="0" placeholder="ไม่บังคับ" />
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="tmplForm.is_active">
                <option :value="true">ใช้งาน</option>
                <option :value="false">ปิด</option>
              </select>
            </div>
            <div class="exp-form-actions">
              <button type="button" class="btn-secondary btn-xs" v-if="tmplForm.id" @click="resetTmplForm">ยกเลิก</button>
              <button type="submit" class="btn-primary btn-xs" :disabled="tmplBusy">
                <span class="material-symbols-rounded animate-spin" v-if="tmplBusy" style="font-size:16px;">sync</span>
                {{ tmplForm.id ? 'บันทึก' : 'เพิ่ม' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

const filters = reactive({ from: '', to: '' });
const loading = ref(false);
const summary = ref(null);
const trips = ref([]);

// ข้อบังคับของโหมดเข้มงวด — เซิร์ฟเวอร์เป็นคนบอกว่าบังคับอะไรอยู่ หน้าเว็บแค่สะท้อน
const rules = ref({ strict: false, slip_required_above: null, require_category: false });
const categories = ref({ expense: [], income: [] });

// ปิดงบ / ปูม / งบ / รายเดือน
const overdue = ref({ count: 0, rounds: [], grace_days: 7, blocks_new_rounds: false });
const closeCheck = ref(null);
const budgetInput = ref(null);
const showAudits = ref(false);
const auditSchedule = ref(null);
const audits = ref([]);
const auditLoading = ref(false);
const showMonthsModal = ref(false);
const months = ref([]);
const monthsLoading = ref(false);

// expanded trip detail
const expanded = ref(null);
const detail = ref(null);
const detailLoading = ref(false);

// expense modal state
const showExpenses = ref(false);
const activeTrip = ref(null);
const activeSchedule = ref(null);
const expenses = ref([]);
const expSummary = ref(null);
const expLoading = ref(false);
const expBusy = ref(false);
const expForm = reactive({ id: null, name: '', amount: null, note: '', kind: 'expense', category: null, spent_at: '', slip: null, hasSlip: false, reason: '' });

const expenseCategories = computed(() => categories.value.expense || []);
const categoryOptions = computed(() => (expForm.kind === 'income' ? categories.value.income : categories.value.expense) || []);

/** ยอดที่กำลังกรอกเกินเพดานหลักฐานหรือยัง — บอกก่อนกดบันทึก ไม่ใช่หลังโดนปฏิเสธ */
const slipRequired = computed(() => {
  const above = rules.value.slip_required_above;
  if (!above || expForm.kind === 'income') return false;
  return Number(expForm.amount || 0) > Number(above);
});

// row selection + copy-to-other-rounds state
const selectedIds = ref([]);
const allSelected = computed(() => expenses.value.length > 0 && selectedIds.value.length === expenses.value.length);
const showCopy = ref(false);
const targetIds = ref([]);
const copyBusy = ref(false);
const copyTargets = computed(() => (detail.value?.schedules || []).filter(s => s.schedule_id !== activeSchedule.value?.schedule_id));
const allTargetsSelected = computed(() => copyTargets.value.length > 0 && targetIds.value.length === copyTargets.value.length);

// templates modal state
const showTemplates = ref(false);
const templates = ref([]);
const tmplLoading = ref(false);
const tmplBusy = ref(false);
const tmplForm = reactive({ id: null, name: '', category: null, default_amount: null, is_active: true });

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}
function profitClass(v) {
  return v < 0 ? 'money-red' : 'money-green';
}
function formatDateTime(iso) {
  if (!iso) return '-';
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}
function categoryLabel(value) {
  if (!value) return '-';
  const all = [...(categories.value.expense || []), ...(categories.value.income || [])];
  return all.find(c => c.value === value)?.label || value;
}
/** รายการนี้ยอดเกินเพดานแต่ยังไม่มีสลิป — คือสิ่งที่กันไม่ให้ปิดงบ */
function needsSlip(e) {
  const above = rules.value.slip_required_above;
  return !!above && e.kind !== 'income' && Number(e.amount) > Number(above) && !e.has_slip;
}

async function loadSummary() {
  loading.value = true;
  expanded.value = null;
  detail.value = null;
  try {
    const data = await admin.fetchFinanceTrips({
      from: filters.from || undefined,
      to: filters.to || undefined,
    });
    summary.value = data.summary;
    trips.value = data.trips;
    if (data.rules) rules.value = data.rules;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดข้อมูลได้');
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  filters.from = '';
  filters.to = '';
  loadSummary();
}

async function toggleTrip(t) {
  if (expanded.value === t.trip_id) {
    expanded.value = null;
    return;
  }
  expanded.value = t.trip_id;
  detail.value = null;
  detailLoading.value = true;
  try {
    detail.value = await admin.fetchTripScheduleProfit(t.trip_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดรอบเดินทางได้');
    expanded.value = null;
  } finally {
    detailLoading.value = false;
  }
}

// refresh the row + summary numbers after editing expenses for a schedule
async function refreshAfterExpenseChange() {
  if (expanded.value) {
    detail.value = await admin.fetchTripScheduleProfit(expanded.value);
  }
  const data = await admin.fetchFinanceTrips({ from: filters.from || undefined, to: filters.to || undefined });
  summary.value = data.summary;
  trips.value = data.trips;
}

// ─── Expense editor ───
async function openExpenses(t, s) {
  activeTrip.value = t;
  activeSchedule.value = s;
  showExpenses.value = true;
  resetExpForm();
  await loadExpenses();
}

async function loadExpenses() {
  expLoading.value = true;
  try {
    const data = await admin.fetchScheduleExpenses(activeSchedule.value.schedule_id);
    expenses.value = data.expenses;
    expSummary.value = data.summary;
    if (data.rules) rules.value = data.rules;
    if (data.categories) categories.value = data.categories;
    budgetInput.value = data.summary?.budget ?? null;
    await loadCloseCheck();
    // keep selection only for rows that still exist
    const ids = expenses.value.map(e => e.id);
    selectedIds.value = selectedIds.value.filter(id => ids.includes(id));
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดค่าใช้จ่ายได้');
  } finally {
    expLoading.value = false;
  }
}

function toggleSelectAll(ev) {
  selectedIds.value = ev.target.checked ? expenses.value.map(e => e.id) : [];
}

// ─── Copy selected expenses to other rounds ───
function openCopy() {
  if (!selectedIds.value.length) return;
  targetIds.value = [];
  showCopy.value = true;
}

function toggleAllTargets(ev) {
  targetIds.value = ev.target.checked ? copyTargets.value.map(s => s.schedule_id) : [];
}

async function doCopy() {
  if (!targetIds.value.length) return;
  copyBusy.value = true;
  try {
    const res = await admin.copyExpensesTo(activeSchedule.value.schedule_id, {
      expense_ids: [...selectedIds.value],
      target_schedule_ids: [...targetIds.value],
    });
    showCopy.value = false;
    selectedIds.value = [];
    alert(res.message);
    // target rounds changed — refresh trip detail + summary figures
    await refreshAfterExpenseChange();
  } catch (e) {
    alert(e.response?.data?.message || 'คัดลอกไม่สำเร็จ');
  } finally {
    copyBusy.value = false;
  }
}

function closeExpenses() {
  showExpenses.value = false;
  refreshAfterExpenseChange();
}

function resetExpForm() {
  expForm.id = null;
  expForm.name = '';
  expForm.amount = null;
  expForm.note = '';
  expForm.kind = 'expense';
  expForm.category = null;
  expForm.spent_at = '';
  expForm.slip = null;
  expForm.hasSlip = false;
  expForm.reason = '';
}
function startEdit(e) {
  expForm.id = e.id;
  expForm.name = e.name;
  expForm.amount = e.amount;
  expForm.note = e.note || '';
  expForm.kind = e.kind || 'expense';
  expForm.category = e.category || null;
  expForm.spent_at = e.spent_at || '';
  expForm.slip = null;
  expForm.hasSlip = !!e.has_slip;
  expForm.reason = '';
}

function onSlipPicked(ev) {
  expForm.slip = ev.target.files?.[0] || null;
}

/**
 * ฟอร์มเป็น FormData เฉพาะตอนแนบไฟล์ — ไม่งั้นส่ง JSON ตามเดิม
 * (FormData ส่ง null มาเป็นสตริง "null" ฝั่ง PHP จึงตัดค่าว่างทิ้งก่อน)
 */
function expensePayload() {
  const fields = {
    name: expForm.name,
    amount: expForm.amount,
    note: expForm.note || null,
    kind: expForm.kind,
    category: expForm.category || null,
    spent_at: expForm.spent_at || null,
    reason: expForm.reason || null,
  };

  if (!expForm.slip) return fields;

  const form = new FormData();
  Object.entries(fields).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, value);
  });
  form.append('slip', expForm.slip);
  return form;
}

async function submitExpense() {
  expBusy.value = true;
  try {
    const id = activeSchedule.value.schedule_id;
    const payload = expensePayload();
    const data = expForm.id
      ? await admin.updateScheduleExpense(id, expForm.id, payload)
      : await admin.createScheduleExpense(id, payload);
    expSummary.value = data.summary;
    resetExpForm();
    await loadExpenses();
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function removeExpense(e) {
  if (!confirm(`ลบรายการ "${e.name}" ?`)) return;

  // รอบที่ปิดงบแล้วต้องมีเหตุผลกำกับเสมอ — ถามตรงนี้ ไม่ปล่อยให้ยิงไปโดนปฏิเสธ
  let reason = null;
  if (expSummary.value?.is_closed) {
    reason = prompt('รอบนี้ปิดงบแล้ว — ลบเพราะอะไร?');
    if (!reason) return;
  }

  expBusy.value = true;
  try {
    const data = await admin.deleteScheduleExpense(activeSchedule.value.schedule_id, e.id, reason);
    expSummary.value = data.summary;
    await loadExpenses();
  } catch (err) {
    alert(err.response?.data?.message || 'ลบไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function applyTemplates() {
  expBusy.value = true;
  try {
    const res = await admin.applyExpenseTemplates(activeSchedule.value.schedule_id);
    if (res.data) {
      expenses.value = res.data.expenses;
      expSummary.value = res.data.summary;
    }
    alert(res.message);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถใช้รายการประจำได้');
  } finally {
    expBusy.value = false;
  }
}

// ─── Templates manager ───
async function openTemplates(t) {
  activeTrip.value = t;
  showTemplates.value = true;
  resetTmplForm();
  tmplLoading.value = true;
  try {
    templates.value = await admin.fetchExpenseTemplates(t.trip_id);
    if (!categories.value.expense?.length && detail.value?.schedules?.length) {
      const data = await admin.fetchScheduleExpenses(detail.value.schedules[0].schedule_id);
      categories.value = data.categories || categories.value;
    }
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดรายการประจำได้');
  } finally {
    tmplLoading.value = false;
  }
}

function resetTmplForm() {
  tmplForm.id = null;
  tmplForm.name = '';
  tmplForm.category = null;
  tmplForm.default_amount = null;
  tmplForm.is_active = true;
}
function startEditTmpl(tm) {
  tmplForm.id = tm.id;
  tmplForm.name = tm.name;
  tmplForm.category = tm.category || null;
  tmplForm.default_amount = tm.default_amount != null ? Number(tm.default_amount) : null;
  tmplForm.is_active = !!tm.is_active;
}

async function submitTemplate() {
  tmplBusy.value = true;
  try {
    const tripId = activeTrip.value.trip_id;
    const payload = { name: tmplForm.name, category: tmplForm.category, default_amount: tmplForm.default_amount, is_active: tmplForm.is_active };
    if (tmplForm.id) {
      await admin.updateExpenseTemplate(tripId, tmplForm.id, payload);
    } else {
      await admin.createExpenseTemplate(tripId, payload);
    }
    resetTmplForm();
    templates.value = await admin.fetchExpenseTemplates(tripId);
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    tmplBusy.value = false;
  }
}

async function removeTemplate(tm) {
  if (!confirm(`ลบรายการประจำ "${tm.name}" ?`)) return;
  tmplBusy.value = true;
  try {
    await admin.deleteExpenseTemplate(activeTrip.value.trip_id, tm.id);
    templates.value = await admin.fetchExpenseTemplates(activeTrip.value.trip_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ลบไม่สำเร็จ');
  } finally {
    tmplBusy.value = false;
  }
}

// ─── ปิดงบ / เปิดกลับ ───
async function loadCloseCheck() {
  if (!activeSchedule.value) return;
  try {
    closeCheck.value = await admin.fetchFinanceCloseCheck(activeSchedule.value.schedule_id);
  } catch {
    // เช็กไม่ได้ก็ไม่ควรทำให้หน้าค่าใช้จ่ายพัง — แค่ไม่โชว์แผงปิดงบ
    closeCheck.value = null;
  }
}

async function doClose() {
  const note = prompt('บันทึกกำกับการปิดงบ (ไม่บังคับ)') ?? '';
  expBusy.value = true;
  try {
    const res = await admin.closeScheduleFinance(activeSchedule.value.schedule_id, { note: note || null });
    expSummary.value = res.data.summary;
    alert(res.message);
    await loadExpenses();
    await loadOverdue();
  } catch (e) {
    alert(e.response?.data?.message || 'ปิดงบไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function doReopen() {
  const reason = prompt('เปิดงบกลับเพราะอะไร? (บังคับ — เก็บลงปูม)');
  if (!reason) return;
  expBusy.value = true;
  try {
    const res = await admin.reopenScheduleFinance(activeSchedule.value.schedule_id, { reason });
    expSummary.value = res.data.summary;
    alert(res.message);
    await loadExpenses();
    await loadOverdue();
  } catch (e) {
    alert(e.response?.data?.message || 'เปิดงบกลับไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function saveBudget() {
  expBusy.value = true;
  try {
    const data = await admin.updateScheduleBudget(activeSchedule.value.schedule_id, {
      finance_budget: budgetInput.value === '' ? null : budgetInput.value,
    });
    expSummary.value = data.summary;
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกงบไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function applyStaffCost() {
  expBusy.value = true;
  try {
    const res = await admin.applyScheduleStaffCost(activeSchedule.value.schedule_id);
    alert(res.message);
    await loadExpenses();
  } catch (e) {
    alert(e.response?.data?.message || 'ลงค่าทีมงานไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

// ─── ปูมการแก้ไข ───
async function openAudits(s) {
  auditSchedule.value = s;
  showAudits.value = true;
  auditLoading.value = true;
  try {
    audits.value = await admin.fetchScheduleFinanceAudits(s.schedule_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดปูมได้');
  } finally {
    auditLoading.value = false;
  }
}

async function exportCsv(s) {
  try {
    await admin.downloadScheduleFinanceCsv(s.schedule_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ดาวน์โหลดไม่สำเร็จ');
  }
}

// ─── กำไรรายเดือน ───
async function showMonths() {
  showMonthsModal.value = true;
  monthsLoading.value = true;
  try {
    const data = await admin.fetchFinanceDashboard({
      from: filters.from || undefined,
      to: filters.to || undefined,
    });
    months.value = data.months;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดกำไรรายเดือนได้');
  } finally {
    monthsLoading.value = false;
  }
}

async function loadOverdue() {
  try {
    overdue.value = await admin.fetchFinanceOverdue();
  } catch {
    // โหลดงานค้างไม่ได้ไม่ควรทำให้ทั้งหน้าพัง — หน้าสรุปยังใช้งานได้ตามปกติ
  }
}

/** กดจากรายการค้าง → กางทริปนั้นแล้วเปิดหน้าค่าใช้จ่ายของรอบนั้นให้เลย */
async function jumpToRound(row) {
  const trip = trips.value.find(t => t.trip_id === row.trip_id) || { trip_id: row.trip_id, title: row.trip_title };
  if (expanded.value !== row.trip_id) await toggleTrip(trip);
  const schedule = detail.value?.schedules?.find(s => s.schedule_id === row.schedule_id);
  if (schedule) await openExpenses(trip, schedule);
}

onMounted(() => {
  loadSummary();
  loadOverdue();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0; }
.sc-item { background: #fff; border: 1px solid #eef0f3; border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.sc-label { font-size: 12px; color: #8b909a; font-weight: 700; }
.sc-val { font-size: 20px; font-weight: 800; }
.sc-period { font-size: 14px; color: #444; }

.money-green { color: #16a34a !important; }
.money-orange { color: #ea580c !important; }
.money-red { color: #dc2626 !important; }

td.num, th.num { text-align: right; white-space: nowrap; }

.trip-row { cursor: pointer; }
.trip-row:hover { background: #f7f8fa; }
.trip-title { font-weight: 700; }
.expand-icon { transition: transform .15s ease; color: #9aa0aa; vertical-align: middle; }
.expand-icon.open { transform: rotate(90deg); }

.detail-row > td { background: #f7f8fa; padding: 12px 16px; }
.schedule-loading { display: flex; justify-content: center; padding: 16px; }
.spinner-sm { width: 22px; height: 22px; }
.schedule-list { display: flex; flex-direction: column; gap: 10px; }
.sched-list-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.sched-count { font-size: 13px; font-weight: 700; color: #6b7280; }
.sched-scroll { display: flex; flex-direction: column; gap: 10px; max-height: min(60vh, 520px); overflow-y: auto; padding-right: 4px; }
.sched-card { background: #fff; border: 1px solid #eef0f3; border-radius: 10px; padding: 12px 14px; }
.sched-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
.sched-date { display: flex; align-items: center; gap: 8px; font-weight: 700; }
.sched-date .material-symbols-rounded { font-size: 18px; color: #9aa0aa; }
.sched-figures { display: flex; flex-wrap: wrap; gap: 16px; font-size: 14px; color: #555; }
.sched-figures b { font-weight: 800; }
.sched-pax { color: #8b909a; margin-left: auto; }

.btn-xs { padding: 6px 10px; font-size: 13px; display: inline-flex; align-items: center; gap: 4px; }

.exp-summary { display: flex; flex-wrap: wrap; gap: 16px; padding: 10px 12px; background: #f7f8fa; border-radius: 10px; font-size: 14px; margin-bottom: 12px; }
.exp-summary b { font-weight: 800; }
.exp-toolbar { margin-bottom: 10px; }
.exp-table th, .exp-table td { padding: 8px 10px; }
.exp-note { color: #8b909a; max-width: 220px; }
.exp-empty { text-align: center; color: #9aa0aa; padding: 16px; }
.tmpl-chip { display: inline-block; margin-left: 6px; font-size: 11px; font-weight: 700; color: #2563eb; background: #eff6ff; border-radius: 6px; padding: 1px 6px; }
.chip-income { color: #047857; background: #ecfdf5; }
/* บรรทัดเล็กใต้ชื่อรายการ — วันที่ใช้เงิน + คนจด (มาจากสตาฟหน้างาน) */
.exp-meta { display: block; margin-top: 2px; font-size: 11px; color: #94a3b8; }

.exp-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e5e7eb; }
.exp-form .form-group { flex: 1; min-width: 140px; margin: 0; }
.exp-form .exp-amount { flex: 0 0 140px; }
.exp-form .exp-kind { flex: 0 0 150px; }
.exp-form-actions { display: flex; gap: 8px; }

.check-col { width: 36px; text-align: center; }
.check-col input { width: 16px; height: 16px; cursor: pointer; }
.exp-table tr.row-selected { background: #eff6ff; }

.copy-all { font-weight: 700; border-bottom: 1px solid #eef0f3; margin-bottom: 6px; }
.copy-list { display: flex; flex-direction: column; gap: 2px; max-height: 280px; overflow-y: auto; }
.copy-row { display: flex; align-items: center; gap: 10px; padding: 8px 6px; border-radius: 8px; cursor: pointer; }
.copy-row:hover { background: #f7f8fa; }
.copy-row input { width: 16px; height: 16px; cursor: pointer; }
.copy-row .status-badge { margin-left: auto; }

/* ── โหมดเข้มงวด ─────────────────────────────────────── */
.view-switch { display: inline-flex; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
.view-switch button { border: 0; background: #fff; padding: 8px 16px; font-size: 13px; font-weight: 700; color: #6b7280; cursor: pointer; }
.view-switch button.active { background: #111827; color: #fff; }

.strict-banner { display: flex; align-items: center; gap: 8px; background: #f0f9ff; border: 1px solid #bae6fd; color: #075985; border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 4px; }
.strict-banner .material-symbols-rounded { font-size: 18px; }

.sc-hint { font-size: 11px; color: #9aa0aa; font-weight: 600; }
.warn-text { color: #b45309; font-weight: 700; font-size: 12px; }
.chip-open { color: #b45309; background: #fffbeb; }

.sched-actions { display: flex; gap: 6px; }
.sched-sub { font-size: 13px; color: #6b7280; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #eef0f3; }
.lock-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #475569; background: #f1f5f9; border-radius: 6px; padding: 2px 8px; }
.lock-badge .material-symbols-rounded { font-size: 14px; }

.close-panel { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; background: #fff; }
.close-panel.locked { background: #f8fafc; }
.close-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.close-head .material-symbols-rounded { font-size: 18px; }
.close-head .ok { color: #16a34a; }
.close-head .warn { color: #dc2626; }
.close-head button { margin-left: auto; }
.close-meta { font-size: 12px; color: #8b909a; }
.close-hint { font-size: 12px; color: #6b7280; margin: 8px 0 0; }
.close-list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.close-list li { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.close-list .material-symbols-rounded { font-size: 16px; }
.close-list .blocker { color: #dc2626; }
.close-list .warning { color: #b45309; }

.budget-box { display: flex; align-items: center; gap: 6px; margin-left: auto; font-size: 13px; }
.budget-box label { font-weight: 700; color: #6b7280; }
.budget-box input { width: 120px; padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 8px; }
.exp-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.exp-form .exp-slip { flex: 0 0 220px; }
.exp-form label.req, .exp-form .req { color: #b45309; }

.audit-list { display: flex; flex-direction: column; gap: 8px; max-height: min(60vh, 520px); overflow-y: auto; }
.audit-row { border: 1px solid #eef0f3; border-left: 3px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.audit-row.audit-deleted { border-left-color: #dc2626; }
.audit-row.audit-created { border-left-color: #16a34a; }
.audit-row.audit-closed { border-left-color: #111827; }
.audit-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 14px; }
.audit-meta { margin-left: auto; font-size: 12px; color: #8b909a; }
.audit-diff { font-size: 13px; color: #475569; margin-top: 2px; }
.audit-reason { font-size: 12px; color: #b45309; margin-top: 4px; }

.overdue-card { border: 1px solid #fecaca; background: #fef2f2; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; }
.overdue-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: #b91c1c; }
.overdue-head .material-symbols-rounded { font-size: 20px; }
.overdue-meta { font-size: 12px; color: #7f1d1d; font-weight: 600; }
.overdue-list { display: flex; flex-direction: column; gap: 2px; margin-top: 8px; max-height: 220px; overflow-y: auto; }
.overdue-row { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; border: 0; background: transparent; padding: 8px 6px; border-radius: 8px; cursor: pointer; font-size: 13px; }
.overdue-row:hover { background: #fee2e2; }
.overdue-trip { font-weight: 800; color: #111827; }
.overdue-date { color: #6b7280; }
.overdue-days { color: #b91c1c; font-weight: 700; }
.overdue-go { margin-left: auto; color: #b91c1c; }
</style>

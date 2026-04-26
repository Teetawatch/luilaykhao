<template>
  <div class="admin-page edit-trip-page">
    <div class="page-header">
      <div class="header-left">
        <button class="btn-back" @click="router.push({ name: backRouteName })">
          <span class="material-symbols-rounded">arrow_back</span>
        </button>
        <div>
          <h1 class="page-title">
            <span class="material-symbols-rounded heading-icon">{{ isVanMode ? 'airport_shuttle' : (isEdit ? 'edit_square' : 'add_circle') }}</span>
            {{ isVanMode ? (isEdit ? 'แก้ไขบริการรถตู้' : 'เพิ่มบริการรถตู้ใหม่') : (isEdit ? 'แก้ไขทริป' : 'เพิ่มทริปใหม่') }}
          </h1>
          <p class="page-subtitle">{{ form.title || (isEdit ? 'กำลังโหลด...' : (isVanMode ? 'ระบุรายละเอียดบริการรถตู้' : 'ระบุรายละเอียดทริปของคุณ')) }}</p>
        </div>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" @click="router.push({ name: backRouteName })">ยกเลิก</button>
        <button class="btn-primary" @click="submitForm" :disabled="submitting || uploading">
          <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
          <span class="material-symbols-rounded" v-else>save</span>
          {{ isEdit ? 'บันทึกการเปลี่ยนแปลง' : (isVanMode ? 'สร้างบริการรถตู้' : 'สร้างทริป') }}
        </button>
      </div>
    </div>

    <div class="edit-grid" v-if="!loading">
      <!-- Main Content -->
      <div class="edit-main">
        <!-- Title Section -->
        <div class="card section-card">
          <div class="form-group full-width">
            <label>ชื่อทริป *</label>
            <input v-model="form.title" required placeholder="เช่น เดินป่าดอยอินทนนท์ 2 วัน 1 คืน" class="title-input" />
          </div>
          <div class="form-group full-width mt-4">
            <label>รายละเอียด</label>
            <textarea v-model="form.description" rows="6" placeholder="อธิบายทริปให้น่าสนใจ..."></textarea>
          </div>
        </div>

        <!-- Location Section -->
        <div class="card section-card">
          <h3 class="section-title"><span class="material-symbols-rounded">location_on</span> สถานที่และจุดนัดพบ</h3>
          <div class="form-grid pt-2">
            <div class="form-group">
              <label>สถานที่ *</label>
              <input v-model="form.location" required placeholder="เช่น เชียงใหม่" />
            </div>
            <div class="form-group">
              <label>จุดขึ้นรถ/เรือ</label>
              <input v-model="form.departure_point" placeholder="เช่น ประตูท่าแพ เชียงใหม่" />
            </div>
            <div class="form-group">
              <label>Latitude</label>
              <input v-model.number="form.latitude" type="number" step="any" placeholder="8.0863" />
            </div>
            <div class="form-group">
              <label>Longitude</label>
              <input v-model.number="form.longitude" type="number" step="any" placeholder="98.3706" />
            </div>
          </div>
          <div class="map-preview-container mt-4" v-if="form.latitude && form.longitude">
            <iframe
              :src="mapEmbedUrl"
              width="100%"
              height="300"
              style="border:0; border-radius: 12px;"
              allowfullscreen
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
            ></iframe>
          </div>
        </div>

        <!-- Highlights Section -->
        <div class="card section-card">
          <div class="section-header-flex">
            <h3 class="section-title text-[var(--color-primary)]">
              <span class="material-symbols-rounded">star</span> จุดเด่นของทริป
            </h3>
            <div class="section-actions-mini">
              <button type="button" @click="openCopyModal('highlights')" title="คัดลอกไปยังทริปอื่น">
                <span class="material-symbols-rounded">move_to_inbox</span>
              </button>
              <button type="button" @click="copySection('highlights')" title="คัดลอกส่วนนี้">
                <span class="material-symbols-rounded">content_copy</span>
              </button>
              <button type="button" @click="pasteSection('highlights')" title="วางข้อมูล">
                <span class="material-symbols-rounded">content_paste</span>
              </button>
            </div>
          </div>
          <div class="highlights-editor space-y-4">
            <div v-for="(hi, idx) in form.highlights" :key="idx" class="highlight-item bg-gray-50 p-5 rounded-2xl border border-gray-100 flex gap-4 items-start relative group">
              <div class="highlight-icon-selector">
                <div class="w-12 h-12 rounded-xl bg-white border border-gray-200 flex items-center justify-center text-[var(--color-accent)] cursor-pointer hover:bg-gray-50 transition-colors"
                  @click="toggleIconPicker(idx)">
                  <span class="material-symbols-rounded text-2xl">{{ hi.icon || 'star' }}</span>
                </div>
                <div v-if="activeIconPicker === idx" class="icon-picker-dropdown absolute top-full left-0 z-50 bg-white border border-gray-200 shadow-xl rounded-xl p-3 grid grid-cols-5 gap-2 mt-2 w-64">
                  <button v-for="icon in commonIcons" :key="icon" type="button" @click="selectIcon(idx, icon)"
                    class="w-10 h-10 rounded-lg hover:bg-[var(--color-sand)] flex items-center justify-center transition-colors">
                    <span class="material-symbols-rounded text-xl">{{ icon }}</span>
                  </button>
                  <div class="col-span-5 pt-2 border-t mt-1">
                    <input v-model="hi.icon" placeholder="ระบุชื่อไอคอน (Google)" class="text-xs p-2 w-full border rounded-md" />
                  </div>
                </div>
              </div>
              <div class="flex-1 space-y-3">
                <input v-model="hi.title" placeholder="หัวข้อจุดเด่น (เช่น ประกันภัยการเดินทาง)" class="font-bold w-full bg-white px-3 py-2 border rounded-lg focus:ring-2 ring-[var(--color-accent)]/20" />
                <textarea v-model="hi.desc" rows="2" placeholder="คำอธิบาย (เช่น คุ้มครองอุบัติเหตุตลอดการเดินทาง...)" class="text-sm w-full bg-white px-3 py-2 border rounded-lg focus:ring-2 ring-[var(--color-accent)]/20"></textarea>
              </div>
              <button type="button" class="remove-highlight-btn text-red-400 hover:text-red-600 p-2" @click="removeItem('highlights', idx)">
                <span class="material-symbols-rounded">delete</span>
              </button>
            </div>
            <button type="button" class="btn-add-dashed" @click="addItem('highlights')">
              <span class="material-symbols-rounded">add_circle</span> เพิ่มจุดเด่นใหม่
            </button>
          </div>
        </div>

        <!-- Itinerary Section -->
        <div class="card section-card">
          <div class="section-header-flex">
            <h3 class="section-title text-[var(--color-primary)]">
              <span class="material-symbols-rounded">event_note</span> กำหนดการเดินทาง (Itinerary)
            </h3>
            <div class="section-actions-mini">
              <button type="button" @click="openCopyModal('itinerary')" title="คัดลอกไปยังทริปอื่น">
                <span class="material-symbols-rounded">move_to_inbox</span>
              </button>
              <button type="button" @click="copySection('itinerary')" title="คัดลอกส่วนนี้">
                <span class="material-symbols-rounded">content_copy</span>
              </button>
              <button type="button" @click="pasteSection('itinerary')" title="วางข้อมูล">
                <span class="material-symbols-rounded">content_paste</span>
              </button>
            </div>
          </div>
          <div class="itinerary-editor space-y-8">
            <div v-for="(sector, sIdx) in form.itinerary" :key="sIdx" class="itinerary-sector-card bg-white p-6 rounded-[2rem] border-2 border-gray-100 relative group transition-all hover:border-[var(--color-accent)]/20 shadow-sm">
              <div class="flex items-center gap-4 mb-6">
                <div class="flex-1">
                  <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 block">ชื่อภาค / ช่วงการเดินทาง</label>
                  <input v-model="sector.sector" placeholder="เช่น ภาคใต้ (ภูเก็ต-กระบี่) หรือ วันที่ 1-3" class="w-full font-black text-xl bg-gray-50 px-5 py-3 rounded-2xl border-none focus:ring-2 ring-[var(--color-accent)]/20" />
                </div>
                <button type="button" class="text-red-400 hover:text-red-600 p-2 mt-6" @click="removeItem('itinerary_sector', sIdx)" title="ลบทั้งภาค">
                  <span class="material-symbols-rounded">delete_sweep</span>
                </button>
              </div>

              <div class="space-y-4 ml-4 md:ml-8 border-l-2 border-dashed border-gray-100 pl-4 md:pl-8">
                <div v-for="(item, idx) in sector.items" :key="idx" class="itinerary-item bg-gray-50 p-6 rounded-2xl border border-gray-100 relative group">
                  <div class="flex items-center gap-4 mb-4">
                    <div class="day-badge bg-[var(--color-accent)] text-white px-4 py-1 rounded-full font-bold text-sm shrink-0">
                      วันที่ {{ item.day }}
                    </div>
                    <input v-model="item.title" placeholder="หัวข้อของวันนี้ (เช่น เดินทางถึงจุดหมาย)" class="flex-1 font-bold bg-white px-4 py-2 border rounded-xl focus:ring-2 ring-[var(--color-accent)]/20" />
                    <button type="button" class="text-red-400 hover:text-red-600 p-2" @click="removeItem('itinerary_item', { sIdx, idx })">
                      <span class="material-symbols-rounded">delete</span>
                    </button>
                  </div>
                  <textarea v-model="item.description" rows="3" placeholder="รายละเอียดกิจกรรมในวันนี้..." class="text-sm w-full bg-white px-4 py-3 border rounded-xl focus:ring-2 ring-[var(--color-accent)]/20"></textarea>
                </div>
                
                <button type="button" class="w-full py-4 border-2 border-dashed border-gray-200 rounded-2xl text-gray-400 font-bold hover:bg-white hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2 group" @click="addItem('itinerary_item', sIdx)">
                  <span class="material-symbols-rounded group-hover:scale-110 transition-transform">add_circle</span> เพิ่มวันเดินทางในส่วนนี้
                </button>
              </div>
            </div>

            <button type="button" class="btn-add-sector w-full py-6 bg-[var(--color-sand)] rounded-[2rem] border-2 border-dashed border-[var(--color-accent)]/30 text-[var(--color-accent)] font-black flex items-center justify-center gap-3 hover:bg-[var(--color-accent)]/5 hover:border-[var(--color-accent)] transition-all" @click="addItem('itinerary_sector')">
              <span class="material-symbols-rounded text-3xl">add_location_alt</span>
              เพิ่มภาค / ช่วงการเดินทางใหม่
            </button>
          </div>
        </div>

        <!-- Preparations Section -->
        <div class="card section-card">
          <div class="section-header-flex">
            <h3 class="section-title text-[var(--color-primary)]">
              <span class="material-symbols-rounded">backpack</span> การเตรียมตัว และสิ่งที่สมาชิกต้องเตรียม
            </h3>
            <div class="section-actions-mini">
              <button type="button" @click="openCopyModal('preparations')" title="คัดลอกไปยังทริปอื่น">
                <span class="material-symbols-rounded">move_to_inbox</span>
              </button>
              <button type="button" @click="copySection('preparations')" title="คัดลอกส่วนนี้">
                <span class="material-symbols-rounded">content_copy</span>
              </button>
              <button type="button" @click="pasteSection('preparations')" title="วางข้อมูล">
                <span class="material-symbols-rounded">content_paste</span>
              </button>
            </div>
          </div>
          <div class="preparations-editor space-y-3">
            <div v-for="(item, idx) in form.preparations" :key="idx" class="flex gap-3 items-center">
              <span class="material-symbols-rounded text-[var(--color-accent)]">check_circle</span>
              <input v-model="form.preparations[idx]" placeholder="เช่น เสื้อกันหนาว, รองเท้าเดินป่า" class="flex-1 px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 ring-[var(--color-accent)]/20" />
              <button type="button" @click="removeItem('preparations', idx)" class="text-red-400 hover:text-red-600 p-2">
                <span class="material-symbols-rounded">delete</span>
              </button>
            </div>
            <button type="button" class="btn-add-dashed" @click="addItem('preparations')">
              <span class="material-symbols-rounded">add_circle</span> เพิ่มรายการเตรียมตัว
            </button>
          </div>
        </div>

        <!-- Inclusions / Exclusions -->
        <div class="card section-card">
          <div class="list-editor-container">
            <div class="list-editor">
              <div class="flex items-center justify-between mb-4 pr-1">
                <label class="list-editor-label text-green-700 !mb-0">
                  <span class="material-symbols-rounded">check_circle</span> สิ่งที่รวมในทริป
                </label>
                <div class="section-actions-mini">
                  <button type="button" @click="openCopyModal('inclusions')" title="คัดลอกไปยังทริปอื่น">
                    <span class="material-symbols-rounded text-sm">move_to_inbox</span>
                  </button>
                  <button type="button" @click="copySection('inclusions')" title="คัดลอก">
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                  </button>
                  <button type="button" @click="pasteSection('inclusions')" title="วาง">
                    <span class="material-symbols-rounded text-sm">content_paste</span>
                  </button>
                </div>
              </div>
              <div class="list-items">
                <div v-for="(item, idx) in form.inclusions" :key="idx" class="list-item">
                  <input v-model="form.inclusions[idx]" placeholder="เช่น ค่าธรรมเนียมเข้าอุทยาน" />
                  <button type="button" class="remove-item-btn" @click="removeItem('inclusions', idx)">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <button type="button" class="add-item-btn" @click="addItem('inclusions')">
                  <span class="material-symbols-rounded">add</span> เพิ่มรายการ
                </button>
              </div>
            </div>
            <div class="list-editor">
              <div class="flex items-center justify-between mb-4 pr-1">
                <label class="list-editor-label text-red-600 !mb-0">
                  <span class="material-symbols-rounded">cancel</span> สิ่งที่ไม่รวม
                </label>
                <div class="section-actions-mini">
                  <button type="button" @click="openCopyModal('exclusions')" title="คัดลอกไปยังทริปอื่น">
                    <span class="material-symbols-rounded text-sm">move_to_inbox</span>
                  </button>
                  <button type="button" @click="copySection('exclusions')" title="คัดลอก">
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                  </button>
                  <button type="button" @click="pasteSection('exclusions')" title="วาง">
                    <span class="material-symbols-rounded text-sm">content_paste</span>
                  </button>
                </div>
              </div>
              <div class="list-items">
                <div v-for="(item, idx) in form.exclusions" :key="idx" class="list-item">
                  <input v-model="form.exclusions[idx]" placeholder="เช่น ค่าใช้จ่ายส่วนตัว" />
                  <button type="button" class="remove-item-btn" @click="removeItem('exclusions', idx)">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <button type="button" class="add-item-btn" @click="addItem('exclusions')">
                  <span class="material-symbols-rounded">add</span> เพิ่มรายการ
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Must Know -->
        <div class="card section-card">
          <div class="section-header-flex">
            <h3 class="section-title text-amber-600">
              <span class="material-symbols-rounded">campaign</span> ข้อควรรู้สำหรับทริปนี้
            </h3>
            <div class="section-actions-mini">
              <button type="button" @click="openCopyModal('must_know')" title="คัดลอกไปยังทริปอื่น">
                <span class="material-symbols-rounded">move_to_inbox</span>
              </button>
              <button type="button" @click="copySection('must_know')" title="คัดลอกส่วนนี้">
                <span class="material-symbols-rounded">content_copy</span>
              </button>
              <button type="button" @click="pasteSection('must_know')" title="วางข้อมูล">
                <span class="material-symbols-rounded">content_paste</span>
              </button>
            </div>
          </div>
          <div class="must-know-editor bg-amber-50 p-6 rounded-[2rem] border border-amber-100 space-y-6">
            <div class="space-y-4">
              <label class="text-sm font-black text-amber-700 uppercase tracking-widest pl-1 mb-1 block">รายการเพิ่มเติม / ราคาพิเศษ</label>
              <div class="space-y-3">
                <div v-for="(item, idx) in form.must_know.items" :key="idx" class="flex gap-3 items-center">
                  <input v-model="item.name" placeholder="ชื่อรายการ (เช่น ข้าวไข่เจียว)" class="flex-1 px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20" />
                  <div class="flex items-center gap-2">
                    <span class="text-gray-400 font-bold">฿</span>
                    <input v-model.number="item.price" type="number" placeholder="ราคา" class="w-24 px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20" />
                  </div>
                  <button type="button" @click="removeItem('must_know_items', idx)" class="text-red-400 hover:text-red-600 p-2">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
                <button type="button" @click="addItem('must_know_items')" class="w-full py-4 border-2 border-dashed border-amber-200 rounded-2xl text-amber-600 font-bold hover:bg-white hover:border-amber-400 transition-all flex items-center justify-center gap-2 group">
                  <span class="material-symbols-rounded group-hover:scale-110 transition-transform">add_circle</span> เพิ่มรายการใหม่
                </button>
              </div>
            </div>
            <div class="pt-4 border-t border-amber-200 space-y-3">
              <label class="text-sm font-black text-amber-700 uppercase tracking-widest pl-1 mb-1 block">หมายเหตุเพิ่มเติม</label>
              <textarea v-model="form.must_know.remarks" rows="2" placeholder="เช่น กรุณาแจ้งล่วงหน้า 1 วันหากต้องการสั่งอาหารเพิ่มเติม" class="w-full px-4 py-4 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20 resize-none font-bold text-gray-700"></textarea>
            </div>
          </div>
        </div>

        <!-- Gallery -->
        <div class="card section-card">
          <h3 class="section-title"><span class="material-symbols-rounded">photo_library</span> รูปภาพเพิ่มเติมในแกลเลอรี่</h3>
          <div class="gallery-grid-editor mt-4">
            <div v-for="(img, idx) in form.gallery" :key="idx" class="gallery-item-preview">
              <img :src="img" />
              <button type="button" class="remove-gallery-img" @click="removeItem('gallery', idx)">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
            <div class="gallery-add-btn" @click="triggerGalleryUpload">
              <span class="material-symbols-rounded" style="font-size:32px;" v-if="!galleryUploading">add_photo_alternate</span>
              <span class="material-symbols-rounded animate-spin" v-else>sync</span>
              <span>เพิ่มรูป</span>
            </div>
          </div>
          <input ref="galleryInput" type="file" multiple accept="image/*" class="hidden-file-input" @change="handleGallerySelect" />
        </div>
      </div>

      <!-- Sidebar Content -->
      <div class="edit-sidebar">
        <!-- Publish Card -->
        <div class="card sidebar-card publish-card">
          <h3 class="sidebar-title">การเผยแพร่</h3>
          <div class="sidebar-body">
            <div class="form-group mb-4">
              <label>สถานะ</label>
              <select v-model="form.status" class="status-select" :class="`status-${form.status}`">
                <option value="active">ใช้งาน</option>
                <option value="inactive">ปิด</option>
                <option value="full">เต็ม</option>
              </select>
            </div>
            <div class="toggles-list">
              <label class="toggle-item">
                <input type="checkbox" v-model="form.is_featured" />
                <span class="toggle-content">
                  <span class="material-symbols-rounded text-amber-500">star</span>
                  แนะนำบนหน้าหลัก
                </span>
              </label>
              <label class="toggle-item">
                <input type="checkbox" v-model="form.is_women_only" />
                <span class="toggle-content">
                  <span class="material-symbols-rounded text-pink-500">woman</span>
                  ทริปสำหรับผู้หญิงเท่านั้น
                </span>
              </label>
            </div>
          </div>
          <div class="sidebar-footer">
            <button class="btn-primary w-full" @click="submitForm" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ isEdit ? 'บันทึกการเปลี่ยนแปลง' : (isVanMode ? 'สร้างบริการรถตู้' : 'สร้างทริป') }}
            </button>
          </div>
        </div>

        <!-- Details Card -->
        <div class="card sidebar-card">
          <h3 class="sidebar-title">ข้อมูลพื้นฐาน</h3>
          <div class="sidebar-body space-y-4">
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required>
                <option v-for="cat in categoriesStore.categories" :key="cat.id" :value="cat.slug">
                  {{ cat.name }}
                </option>
              </select>
            </div>
            <div class="form-group">
              <label>ระดับความยาก *</label>
              <select v-model="form.difficulty" required>
                <option value="easy">ง่าย</option>
                <option value="medium">ปานกลาง</option>
                <option value="hard">ยาก</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="form-group">
                <label>จำนวนวัน *</label>
                <input v-model.number="form.duration_days" type="number" min="1" required />
              </div>
              <div class="form-group">
                <label>จำนวนคนสูงสุด *</label>
                <input v-model.number="form.max_participants" type="number" min="1" required />
              </div>
            </div>
          </div>
        </div>

        <!-- Pricing Card -->
        <div class="card sidebar-card bg-green-50/30 border-green-100">
          <h3 class="sidebar-title text-green-800">ราคา</h3>
          <div class="sidebar-body">
            <div class="form-group">
              <label>ราคาต่อคน (฿) *</label>
              <div class="price-input-wrapper">
                <span class="currency-symbol">฿</span>
                <input v-model.number="form.price_per_person" type="number" min="0" required class="price-input" />
              </div>
            </div>
          </div>
        </div>

        <!-- Cover Image Card -->
        <div class="card sidebar-card">
          <h3 class="sidebar-title">รูปปกทริป</h3>
          <div class="sidebar-body">
            <div class="cover-upload-area">
              <div class="cover-preview" v-if="imagePreview || form.cover_image" @click="triggerFileInput">
                <img :src="imagePreview || form.cover_image" alt="Cover preview" />
                <div class="cover-overlay">
                  <span class="material-symbols-rounded">change_circle</span>
                  เปลี่ยนรูป
                </div>
                <button type="button" class="remove-cover-btn" @click.stop="removeImage">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <div class="cover-dropzone" v-else @click="triggerFileInput" :class="{ dragging: isDragging }"
                @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop">
                <span class="material-symbols-rounded">cloud_upload</span>
                <p>คลิกเพื่อเลือกรูป</p>
              </div>
              <input ref="fileInput" type="file" accept="image/*" class="hidden-file-input" @change="handleFileSelect" />
            </div>
            <div class="upload-progress mt-2" v-if="uploading">
              <div class="upload-progress-bar"><div class="upload-progress-fill"></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div class="loading-state" v-else>
      <div class="spinner"></div>
      <p>กำลังโหลดข้อมูลทริป...</p>
    </div>
  </div>

  <!-- Copy to Other Trips Modal -->
  <div v-if="showCopyModal" class="modal-overlay">
    <div class="modal-container copy-trips-modal">
      <div class="modal-header">
        <h3 class="modal-title">คัดลอกข้อมูลไปยังทริปอื่น</h3>
        <button class="btn-close" @click="showCopyModal = false">
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>
      <div class="modal-body">
        <p class="text-sm text-gray-500 mb-4">
          เลือกทริปที่ต้องการนำข้อมูล <strong class="text-[var(--color-primary)]">"{{ targetField }}"</strong> ไปวางทับ
        </p>
        
        <div class="search-box mb-4">
          <span class="material-symbols-rounded">search</span>
          <input v-model="searchTripQuery" placeholder="ค้นหาทริป..." />
        </div>

        <div class="trips-selection-list">
          <label v-for="trip in filteredTrips" :key="trip.id" class="trip-selection-item" :class="{ active: selectedTripsIds.includes(trip.id) }">
            <input type="checkbox" v-model="selectedTripsIds" :value="trip.id" class="hidden" />
            <div class="item-content">
              <div class="trip-thumb" v-if="trip.cover_image">
                <img :src="trip.cover_image" />
              </div>
              <div class="trip-info">
                <div class="trip-title">{{ trip.title }}</div>
                <div class="trip-meta">{{ trip.location }} • {{ trip.duration_days }} วัน</div>
              </div>
              <div class="selection-status">
                <span class="material-symbols-rounded">{{ selectedTripsIds.includes(trip.id) ? 'check_circle' : 'radio_button_unchecked' }}</span>
              </div>
            </div>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <div class="selected-count">เลือกแล้ว {{ selectedTripsIds.length }} ทริป</div>
        <div class="modal-actions">
          <button class="btn-secondary" @click="showCopyModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="confirmBulkCopy" :disabled="bulkCopying || selectedTripsIds.length === 0">
            <span class="material-symbols-rounded animate-spin" v-if="bulkCopying">sync</span>
            <span class="material-symbols-rounded" v-else>content_paste_go</span>
            วางข้อมูลทันที
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminStore } from '../../stores/admin';
import { useCategoriesStore } from '../../stores/categories';
import api from '../../lib/axios';

const route = useRoute();
const router = useRouter();
const admin = useAdminStore();
const categoriesStore = useCategoriesStore();

const isEdit = computed(() => !!route.params.id);
const isVanMode = computed(() => route.name?.startsWith('admin-van-trip'));
const backRouteName = computed(() => isVanMode.value ? 'admin-van-trips' : 'admin-trips');
const loading = ref(false);
const submitting = ref(false);

const form = reactive({
  title: '', type: 'trekking', location: '', description: '',
  difficulty: 'medium', duration_days: 1, max_participants: 10,
  price_per_person: 0, departure_point: '', status: 'active', cover_image: '',
  latitude: null, longitude: null, is_featured: false, is_women_only: false,
  gallery: [], inclusions: [], exclusions: [],
  highlights: [],
  must_know: { items: [], remarks: '' },
  itinerary: [],
  preparations: [],
});

// Image upload state
const fileInput = ref(null);
const imagePreview = ref(null);
const uploading = ref(false);
const isDragging = ref(false);
const galleryInput = ref(null);
const galleryUploading = ref(false);
const activeIconPicker = ref(null);

// Bulk Copy state
const showCopyModal = ref(false);
const allTrips = ref([]);
const targetField = ref('');
const selectedTripsIds = ref([]);
const searchTripQuery = ref('');
const bulkCopying = ref(false);

const commonIcons = [
  'shield_person', 'restaurant', 'scuba_diving', 'directions_boat', 'photo_camera',
  'hiking', 'camping', 'airport_shuttle', 'badge', 'hotel', 'explore', 'terrain',
  'schedule', 'verified_user', 'map', 'stars', 'local_taxi', 'groups', 'eco', 'waves'
];

const mapEmbedUrl = computed(() => {
  if (!form.latitude || !form.longitude) return '';
  return `https://www.google.com/maps?q=${form.latitude},${form.longitude}&z=14&output=embed`;
});

const toggleIconPicker = (idx) => {
  activeIconPicker.value = activeIconPicker.value === idx ? null : idx;
};

const selectIcon = (idx, icon) => {
  form.highlights[idx].icon = icon;
  activeIconPicker.value = null;
};

const addItem = (field, extra = null) => {
  if (field === 'highlights') {
    if (!form.highlights) form.highlights = [];
    form.highlights.push({ title: '', desc: '', icon: 'star' });
  } else if (field === 'itinerary_sector') {
    if (!form.itinerary) form.itinerary = [];
    form.itinerary.push({ sector: '', items: [] });
  } else if (field === 'itinerary_item') {
    const sIdx = extra;
    const sector = form.itinerary[sIdx];
    let nextDay = 1;
    // Calculate next day based only on this sector
    sector.items.forEach(item => {
      if (item.day >= nextDay) nextDay = item.day + 1;
    });
    sector.items.push({ day: nextDay, title: '', description: '' });
  } else if (field === 'preparations') {
    if (!form.preparations) form.preparations = [];
    form.preparations.push('');
  } else if (field === 'must_know_items') {
    if (!form.must_know.items) form.must_know.items = [];
    form.must_know.items.push({ name: '', price: 0 });
  } else {
    if (!form[field]) form[field] = [];
    form[field].push('');
  }
};

const removeItem = (field, index) => {
  if (field === 'must_know_items') {
    form.must_know.items.splice(index, 1);
  } else if (field === 'itinerary_sector') {
    form.itinerary.splice(index, 1);
  } else if (field === 'itinerary_item') {
    const { sIdx, idx } = index;
    form.itinerary[sIdx].items.splice(idx, 1);
  } else {
    form[field].splice(index, 1);
  }
};

const triggerFileInput = () => fileInput.value?.click();
const handleFileSelect = (event) => {
  const file = event.target.files?.[0];
  if (file) uploadFile(file);
};
const handleDrop = (event) => {
  isDragging.value = false;
  const file = event.dataTransfer?.files?.[0];
  if (file && file.type.startsWith('image/')) uploadFile(file);
};

const uploadFile = async (file) => {
  if (file.size > 10 * 1024 * 1024) { alert('ไฟล์มีขนาดเกิน 10MB'); return; }
  const reader = new FileReader();
  reader.onload = (e) => { imagePreview.value = e.target.result; };
  reader.readAsDataURL(file);

  uploading.value = true;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const res = await api.post('/admin/upload-image', formData);
    form.cover_image = res.data.data.url;
    imagePreview.value = null;
  } catch (e) {
    alert(e.response?.data?.message || 'อัปโหลดรูปไม่สำเร็จ');
    imagePreview.value = null;
  } finally {
    uploading.value = false;
    if (fileInput.value) fileInput.value.value = '';
  }
};

const removeImage = () => {
  form.cover_image = '';
  imagePreview.value = null;
  if (fileInput.value) fileInput.value.value = '';
};

const triggerGalleryUpload = () => galleryInput.value?.click();
const handleGallerySelect = async (event) => {
  const files = Array.from(event.target.files);
  if (!files.length) return;
  galleryUploading.value = true;
  try {
    const validFiles = files.filter(file => file.size <= 10 * 1024 * 1024);
    if (validFiles.length < files.length) alert('มีบางไฟล์ขนาดเกิน 10MB และจะถูกข้ามไป');
    if (!validFiles.length) { galleryUploading.value = false; return; }

    const uploadPromises = validFiles.map(async (file) => {
      const formData = new FormData();
      formData.append('file', file);
      const res = await api.post('/admin/upload-image', formData);
      return res.data.data.url;
    });

    const urls = await Promise.all(uploadPromises);
    form.gallery = [...(form.gallery || []), ...urls];
  } catch (e) {
    alert('อัปโหลดรูปภาพแกลเลอรี่บางส่วนล้มเหลว');
  } finally {
    galleryUploading.value = false;
    if (galleryInput.value) galleryInput.value.value = '';
  }
};

const submitForm = async () => {
  submitting.value = true;
  try {
    if (isEdit.value) {
      await admin.updateTrip(route.params.id, form);
    } else {
      await admin.createTrip(form);
    }
    router.push({ name: backRouteName.value });
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const copySection = (field) => {
  const data = form[field];
  localStorage.setItem(`copied_trip_${field}`, JSON.stringify(data));
};

const pasteSection = (field) => {
  const data = localStorage.getItem(`copied_trip_${field}`);
  if (data) {
    try {
      const parsedData = JSON.parse(data);
      if (field === 'must_know') {
        form.must_know = { ...parsedData };
      } else {
        form[field] = [...parsedData];
      }
    } catch (e) {
      console.error('Failed to paste data', e);
    }
  }
};

const openCopyModal = async (field) => {
  targetField.value = field;
  showCopyModal.value = true;
  selectedTripsIds.value = [];
  if (allTrips.value.length === 0) {
    try {
      const res = await api.get('/admin/trips?per_page=100');
      allTrips.value = res.data.data.filter(t => t.id !== Number(route.params.id));
    } catch (e) {
      alert('โหลดข้อมูลทริปอื่นๆ ไม่สำเร็จ');
    }
  }
};

const confirmBulkCopy = async () => {
  if (selectedTripsIds.value.length === 0) {
    alert('กรุณาเลือกทริปที่ต้องการวางข้อมูล');
    return;
  }
  
  if (!confirm(`ยืนยันการวางข้อมูลลงใน ${selectedTripsIds.value.length} ทริปที่เลือก? (ข้อมูลเดิมในทริปเหล่านั้นจะถูกเขียนทับ)`)) {
    return;
  }

  bulkCopying.value = true;
  try {
    await api.patch('/admin/trips/bulk-update-field', {
      trip_ids: selectedTripsIds.value,
      field: targetField.value,
      value: form[targetField.value]
    });
    alert('อัปเดตข้อมูลทริปที่เลือกเรียบร้อยแล้ว');
    showCopyModal.value = false;
  } catch (e) {
    alert('เกิดข้อผิดพลาดในการอัปเดตข้อมูล');
  } finally {
    bulkCopying.value = false;
  }
};

const filteredTrips = computed(() => {
  if (!searchTripQuery.value) return allTrips.value;
  const q = searchTripQuery.value.toLowerCase();
  return allTrips.value.filter(t => t.title.toLowerCase().includes(q) || t.location.toLowerCase().includes(q));
});

const initData = async () => {
  if (isEdit.value) {
    loading.value = true;
    try {
      const res = await api.get(`/admin/trips/${route.params.id}`);
      const trip = res.data.data;
      Object.assign(form, { ...trip });
      form.latitude = trip.latitude || null;
      form.longitude = trip.longitude || null;
      form.gallery = trip.gallery || [];
      form.inclusions = trip.inclusions || [];
      form.exclusions = trip.exclusions || [];
      form.highlights = trip.highlights || [];
      form.must_know = trip.must_know || { items: [], remarks: '' };
      
      // Transform old itinerary format to new sector-based format
      const rawItinerary = trip.itinerary || [];
      if (rawItinerary.length > 0 && !rawItinerary[0].hasOwnProperty('sector')) {
        form.itinerary = [{
          sector: 'กำหนดการเดินทาง',
          items: rawItinerary
        }];
      } else {
        form.itinerary = rawItinerary;
      }

      form.preparations = trip.preparations || [];
    } catch (e) {
      alert('ไม่พบข้อมูลทริป');
      router.push({ name: backRouteName.value });
    } finally {
      loading.value = false;
    }
  } else if (route.query.duplicate) {
     // Handle duplication logic if needed, or just let TripsPage handle it via navigation
  }
};

onMounted(() => {
  // In van mode, default type to 'climbing' (van category slug)
  if (isVanMode.value && !isEdit.value) {
    form.type = 'climbing';
  }
  initData();
  categoriesStore.fetchAdminCategories();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.edit-trip-page {
  padding-bottom: 80px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.btn-back {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  background: white;
  color: #374151;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-back:hover {
  background: #f9fafb;
  border-color: #d1d5db;
  transform: translateX(-2px);
}

.header-actions {
  display: flex;
  gap: 12px;
}

.edit-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
}

.edit-main {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.edit-sidebar {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.card {
  background: white;
  border-radius: 16px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
}

.section-card {
  padding: 24px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 18px;
  font-weight: 700;
  margin: 0 0 20px;
  color: #111827;
}
.section-title .material-symbols-rounded {
  font-size: 24px;
}

.title-input {
  font-size: 24px !important;
  font-weight: 700 !important;
  padding: 12px 16px !important;
}

.sidebar-card {
  padding: 0;
}

.sidebar-title {
  padding: 16px 20px;
  font-size: 15px;
  font-weight: 700;
  border-bottom: 1px solid #f3f4f6;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sidebar-body {
  padding: 20px;
}

.sidebar-footer {
  padding: 16px 20px;
  background: #f9fafb;
  border-top: 1px solid #f3f4f6;
}

/* ─── Publish Sidebar ────────────────── */
.status-select {
  width: 100%;
  font-weight: 700;
}
.status-active { color: #15803d; border-color: #bcf0da; background: #f3faf7; }
.status-inactive { color: #6b7280; border-color: #e5e7eb; background: #f9fafb; }
.status-full { color: #b45309; border-color: #fde68a; background: #fffbeb; }

.toggles-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.toggle-item {
  display: flex;
  align-items: center;
  gap: 12px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  color: #374151;
}

.toggle-item input[type="checkbox"] {
  width: 18px;
  height: 18px;
  accent-color: var(--color-accent);
}

.toggle-content {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ─── Pricing ─────────────────────────── */
.price-input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}
.currency-symbol {
  position: absolute;
  left: 14px;
  font-weight: 700;
  color: #6b7280;
}
.price-input {
  padding-left: 32px !important;
  font-size: 18px !important;
  font-weight: 700 !important;
  color: #15803d !important;
}

/* ─── Cover Image ──────────────────────── */
.cover-upload-area {
  border-radius: 12px;
  overflow: hidden;
  position: relative;
}

.cover-preview {
  aspect-ratio: 16/9;
  cursor: pointer;
  position: relative;
}
.cover-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.4);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: white;
  font-size: 13px;
  font-weight: 600;
  opacity: 0;
  transition: opacity 0.2s;
}
.cover-preview:hover .cover-overlay {
  opacity: 1;
}

.remove-cover-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: white;
  border: none;
  color: #ef4444;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
  cursor: pointer;
}

.cover-dropzone {
  aspect-ratio: 16/9;
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.2s;
}
.cover-dropzone:hover {
  background: #f9fafb;
  border-color: #9ca3af;
}
.cover-dropzone .material-symbols-rounded { font-size: 32px; }

/* ─── Highlights ─── */
.btn-add-dashed {
  width: 100%;
  padding: 16px;
  border: 2px dashed #e2e8f0;
  border-radius: 16px;
  background: transparent;
  color: #64748b;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-add-dashed:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
  background: #f0faf4;
}

.section-header-flex {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}
.section-header-flex .section-title {
  margin-bottom: 0;
}

.section-actions-mini {
  display: flex;
  gap: 8px;
}
.section-actions-mini button {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  background: white;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}
.section-actions-mini button:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
  background: #f0faf4;
}
.section-actions-mini button .material-symbols-rounded {
  font-size: 18px;
}

.remove-highlight-btn {
  display: flex;
}

/* ─── Modal Styles ───────────────────── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.modal-container {
  background: white;
  border-radius: 24px;
  width: 100%;
  max-width: 600px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.modal-title {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: #111827;
}

.btn-close {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: none;
  background: #f3f4f6;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-close:hover {
  background: #e5e7eb;
  color: #111827;
}

.modal-body {
  padding: 24px;
  overflow-y: auto;
  flex: 1;
}

.search-box {
  position: relative;
  display: flex;
  align-items: center;
}
.search-box .material-symbols-rounded {
  position: absolute;
  left: 12px;
  color: #9ca3af;
}
.search-box input {
  width: 100%;
  padding: 10px 12px 10px 40px !important;
  border-radius: 12px !important;
  border: 1px solid #e5e7eb !important;
  font-size: 14px !important;
}

.trips-selection-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.trip-selection-item {
  cursor: pointer;
}

.trip-selection-item .item-content {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid #f3f4f6;
  transition: all 0.2s;
}

.trip-selection-item:hover .item-content {
  background: #f9fafb;
  border-color: #e5e7eb;
}

.trip-selection-item.active .item-content {
  background: #f0faf4;
  border-color: var(--color-accent);
}

.trip-thumb {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
}
.trip-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.trip-info {
  flex: 1;
}
.trip-title {
  font-size: 14px;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}
.trip-meta {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.selection-status .material-symbols-rounded {
  font-size: 20px;
  color: #d1d5db;
}
.trip-selection-item.active .selection-status .material-symbols-rounded {
  color: var(--color-accent);
}

.modal-footer {
  padding: 20px 24px;
  border-top: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.selected-count {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-accent);
}

.modal-actions {
  display: flex;
  gap: 12px;
}

.animate-spin {
  animation: spin 1s linear infinite;
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* ─── Gallery ─── */
.gallery-grid-editor {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 12px;
}
.gallery-item-preview {
  aspect-ratio: 1/1;
}

@media (max-width: 1024px) {
  .edit-grid {
    grid-template-columns: 1fr;
  }
}
</style>

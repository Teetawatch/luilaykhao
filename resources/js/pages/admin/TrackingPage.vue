<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-map-marked-alt"></i> ติดตามรถแบบเรียลไทม์</h1>
        <p class="page-subtitle">
          <span class="ws-status" :class="wsConnected ? 'connected' : 'disconnected'">
            <i class="fas fa-circle"></i>
            {{ wsConnected ? 'เชื่อมต่อแล้ว (Real-time)' : 'ไม่ได้เชื่อมต่อ' }}
          </span>
        </p>
      </div>
      <div class="header-actions">
        <label class="toggle-trail">
          <input type="checkbox" v-model="showTrail" @change="toggleTrail" />
          <span><i class="fas fa-route"></i> แสดงเส้นทาง</span>
        </label>
        <button class="btn-secondary" @click="centerAll">
          <i class="fas fa-expand-arrows-alt"></i> แสดงทั้งหมด
        </button>
        <button class="btn-primary" @click="refreshLocations" :disabled="loading">
          <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i> รีเฟรช
        </button>
      </div>
    </div>

    <div class="tracking-container">
      <!-- Sidebar -->
      <div class="tracking-sidebar">
        <div class="sidebar-search">
          <i class="fas fa-search"></i>
          <input v-model="searchQuery" placeholder="ค้นหารถ..." />
        </div>

        <div class="vehicle-list">
          <div
            v-for="v in filteredVehicles"
            :key="v.vehicle_id"
            class="vehicle-list-item"
            :class="{ active: selectedVehicleId === v.vehicle_id }"
            @click="selectVehicle(v)"
          >
            <div class="vehicle-status-dot" :class="isOnline(v) ? 'online' : 'offline'"></div>
            <div class="vehicle-list-info">
              <div class="vehicle-list-name">{{ v.vehicle_name }}</div>
              <div class="vehicle-list-plate">{{ v.license_plate || 'ไม่มีทะเบียน' }}</div>
              <div class="vehicle-list-meta">
                <span v-if="v.speed != null">
                  <i class="fas fa-tachometer-alt"></i> {{ Math.round(v.speed) }} km/h
                </span>
                <span>{{ timeAgo(v.recorded_at) }}</span>
              </div>
            </div>
            <i class="fas fa-chevron-right vehicle-list-arrow"></i>
          </div>
          <div v-if="!filteredVehicles.length" class="vehicle-list-empty">
            <i class="fas fa-car-side"></i>
            <p>{{ loading ? 'กำลังโหลด...' : 'ไม่พบข้อมูลรถ' }}</p>
          </div>
        </div>

        <div class="sidebar-stats">
          <div class="stat-item online">
            <span class="stat-count">{{ onlineCount }}</span>
            <span class="stat-label">ออนไลน์</span>
          </div>
          <div class="stat-item offline">
            <span class="stat-count">{{ offlineCount }}</span>
            <span class="stat-label">ออฟไลน์</span>
          </div>
          <div class="stat-item total">
            <span class="stat-count">{{ vehicles.length }}</span>
            <span class="stat-label">ทั้งหมด</span>
          </div>
        </div>
      </div>

      <!-- Map -->
      <div class="tracking-map-wrapper">
        <div ref="mapContainer" class="tracking-map"></div>

        <!-- Selected vehicle overlay -->
        <div v-if="selectedVehicle" class="map-info-overlay">
          <button class="map-info-close" @click="selectedVehicleId = null">
            <i class="fas fa-times"></i>
          </button>
          <div class="map-info-header">
            <i :class="selectedVehicle.type === 'boat' ? 'fas fa-ship' : 'fas fa-shuttle-van'"></i>
            <div>
              <div class="map-info-name">{{ selectedVehicle.vehicle_name }}</div>
              <div class="map-info-plate">{{ selectedVehicle.license_plate }}</div>
            </div>
          </div>
          <div class="map-info-details">
            <div class="map-info-row">
              <i class="fas fa-map-pin"></i>
              <span>{{ selectedVehicle.latitude?.toFixed(5) }}, {{ selectedVehicle.longitude?.toFixed(5) }}</span>
            </div>
            <div class="map-info-row" v-if="selectedVehicle.speed != null">
              <i class="fas fa-tachometer-alt"></i>
              <span>{{ Math.round(selectedVehicle.speed) }} km/h</span>
            </div>
            <div class="map-info-row" v-if="selectedVehicle.heading != null">
              <i class="fas fa-compass"></i>
              <span>{{ Math.round(selectedVehicle.heading) }}°</span>
            </div>
            <div class="map-info-row">
              <i class="fas fa-clock"></i>
              <span>{{ timeAgo(selectedVehicle.recorded_at) }}</span>
            </div>
            <div class="map-info-row" v-if="trailPoints[selectedVehicle.vehicle_id]?.length">
              <i class="fas fa-route"></i>
              <span>{{ trailPoints[selectedVehicle.vehicle_id].length }} จุดในเส้นทาง</span>
            </div>
          </div>
          <!-- ETA Section -->
          <div v-if="etaData[selectedVehicle.vehicle_id]" class="map-info-eta">
            <div class="eta-header"><i class="fas fa-route"></i> ETA ถึงปลายทาง</div>
            <div class="eta-row">
              <span class="eta-badge distance"><i class="fas fa-road"></i> {{ etaData[selectedVehicle.vehicle_id].distance?.text }}</span>
              <span class="eta-badge duration"><i class="fas fa-clock"></i> {{ etaData[selectedVehicle.vehicle_id].duration?.text }}</span>
            </div>
            <div v-if="etaData[selectedVehicle.vehicle_id].duration_in_traffic" class="eta-row">
              <span class="eta-badge traffic"><i class="fas fa-car"></i> สภาพจราจร: {{ etaData[selectedVehicle.vehicle_id].duration_in_traffic?.text }}</span>
            </div>
          </div>
          <div class="map-info-actions">
            <button class="btn-eta btn-sm" @click="promptETACalculation(selectedVehicle)" :disabled="etaLoading">
              <i class="fas fa-spinner fa-spin" v-if="etaLoading"></i>
              <i class="fas fa-map-signs" v-else></i>
              {{ etaLoading ? 'กำลังคำนวณ...' : 'คำนวณ ETA' }}
            </button>
            <button class="btn-danger btn-sm btn-clear-trail"
              v-if="trailPoints[selectedVehicle.vehicle_id]?.length"
              @click="clearTrail(selectedVehicle.vehicle_id)">
              <i class="fas fa-trash"></i> ล้างเส้นทาง
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import api from '../../lib/axios';

// ─── State ───────────────────────────────────────────────
const mapContainer = ref(null);
const vehicles = ref([]);
const loading = ref(false);
const searchQuery = ref('');
const selectedVehicleId = ref(null);
const wsConnected = ref(false);
const showTrail = ref(true);

// เก็บ trail points สำหรับแต่ละรถ
const trailPoints = ref({});   // { vehicleId: [[lat, lng], ...] }
const MAX_TRAIL_POINTS = 200;

let map = null;
let markers = {};
let trailPolylines = {};   // { vehicleId: L.Polyline }
let L = null;

// ─── Computed ────────────────────────────────────────────
const filteredVehicles = computed(() => {
  if (!searchQuery.value) return vehicles.value;
  const q = searchQuery.value.toLowerCase();
  return vehicles.value.filter(v =>
    v.vehicle_name?.toLowerCase().includes(q) ||
    v.license_plate?.toLowerCase().includes(q)
  );
});

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === selectedVehicleId.value) ?? null
);

const onlineCount = computed(() => vehicles.value.filter(isOnline).length);
const offlineCount = computed(() => vehicles.value.filter(v => !isOnline(v)).length);

// ─── Helpers ─────────────────────────────────────────────
function isOnline(v) {
  if (!v.recorded_at) return false;
  return Date.now() - new Date(v.recorded_at).getTime() < 5 * 60 * 1000;
}

function timeAgo(dateStr) {
  if (!dateStr) return 'ไม่ทราบ';
  const diff = Date.now() - new Date(dateStr).getTime();
  const s = Math.floor(diff / 1000);
  if (s < 60) return `${s} วิ ที่แล้ว`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} นาที ที่แล้ว`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ชม. ที่แล้ว`;
  return `${Math.floor(h / 24)} วัน ที่แล้ว`;
}

// ─── Map Init ────────────────────────────────────────────
function initMap() {
  if (!L || !mapContainer.value || map) return;
  map = L.map(mapContainer.value, { center: [13.7563, 100.5018], zoom: 10 });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
  }).addTo(map);
}

// SVG icons — ใช้ inline เพราะ Font Awesome ไม่โหลดใน Leaflet divIcon
const SVG_VAN = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="18" height="18">
  <path d="M20 8h-3L15 4H5C3.9 4 3 4.9 3 6v11h2a3 3 0 0 0 6 0h4a3 3 0 0 0 6 0h2v-5l-3-4zM8 18a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm10 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM5 13V6h9l1.5 4H5v3z"/>
</svg>`;

const SVG_BOAT = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="18" height="18">
  <path d="M20 21c-1.39 0-2.78-.47-4-1.32-2.44 1.71-5.56 1.71-8 0C6.78 20.53 5.39 21 4 21H2v2h2c1.38 0 2.74-.35 4-.99 2.52 1.29 5.48 1.29 8 0 1.26.65 2.62.99 4 .99h2v-2h-2zM3.95 19H4c1.6 0 3.02-.88 4-2 .98 1.12 2.4 2 4 2s3.02-.88 4-2c.98 1.12 2.4 2 4 2h.05l1.89-6.68c.08-.26.06-.54-.06-.78s-.34-.42-.6-.5L20 10.62V6c0-1.1-.9-2-2-2h-3V1H9v3H6c-1.1 0-2 .9-2 2v4.62l-1.29.42c-.26.08-.48.26-.6.5s-.14.52-.06.78L3.95 19zM6 6h12v3.97L12 8 6 9.97V6z"/>
</svg>`;

function createIcon(vehicle, online) {
  const color  = online ? '#22c55e' : '#9ca3af';
  const border = online ? '#16a34a' : '#6b7280';
  const svgIcon = vehicle.type === 'boat' ? SVG_BOAT : SVG_VAN;

  // Heading rotation — หมุนเฉพาะไอคอน ไม่หมุน label
  const rot = (vehicle.heading != null)
    ? `style="transform:rotate(${vehicle.heading}deg);display:flex;align-items:center;justify-content:center;"`
    : 'style="display:flex;align-items:center;justify-content:center;"';

  // Label: ชื่อรถ + ทะเบียน (ถ้ามี)
  const name  = vehicle.vehicle_name ?? '';
  const plate = vehicle.license_plate ? `<span class="mk-plate">${vehicle.license_plate}</span>` : '';

  const html = `
    <div class="mk-wrap">
      <div class="mk-dot" style="background:${color};border-color:${border}" ${rot}>
        ${svgIcon}
      </div>
      <div class="mk-label">
        <span class="mk-name">${name}</span>
        ${plate}
      </div>
    </div>`;

  // iconSize: กว้าง 80px สูง 60px, anchor ล่างกลาง
  return L.divIcon({
    className: 'vehicle-marker-icon',
    html,
    iconSize:   [80, 60],
    iconAnchor: [40, 60],
    popupAnchor:[0, -60],
  });
}

// ─── Trail ───────────────────────────────────────────────
function addTrailPoint(vehicleId, lat, lng) {
  if (!trailPoints.value[vehicleId]) {
    trailPoints.value[vehicleId] = [];
  }
  const pts = trailPoints.value[vehicleId];
  pts.push([lat, lng]);
  if (pts.length > MAX_TRAIL_POINTS) pts.shift();
}

function updateTrailPolyline(vehicleId) {
  if (!map || !L) return;
  const pts = trailPoints.value[vehicleId];
  if (!pts || pts.length < 2) return;

  if (trailPolylines[vehicleId]) {
    if (showTrail.value) {
      trailPolylines[vehicleId].setLatLngs(pts);
    }
  } else {
    trailPolylines[vehicleId] = L.polyline(pts, {
      color: '#3b82f6',
      weight: 3,
      opacity: 0.7,
      dashArray: '6 4',
    }).addTo(map);
  }

  if (!showTrail.value) {
    trailPolylines[vehicleId].remove();
    delete trailPolylines[vehicleId];
  }
}

function toggleTrail() {
  if (!map || !L) return;
  if (showTrail.value) {
    // Re-draw all trails
    Object.keys(trailPoints.value).forEach(id => {
      const pts = trailPoints.value[id];
      if (!pts || pts.length < 2) return;
      if (!trailPolylines[id]) {
        trailPolylines[id] = L.polyline(pts, {
          color: '#3b82f6', weight: 3, opacity: 0.7, dashArray: '6 4',
        }).addTo(map);
      } else {
        trailPolylines[id].addTo(map);
      }
    });
  } else {
    Object.values(trailPolylines).forEach(p => p.remove());
    trailPolylines = {};
  }
}

function clearTrail(vehicleId) {
  trailPoints.value[vehicleId] = [];
  if (trailPolylines[vehicleId]) {
    trailPolylines[vehicleId].remove();
    delete trailPolylines[vehicleId];
  }
}

// ─── Markers ─────────────────────────────────────────────
function upsertMarker(v) {
  if (!map || !L || v.latitude == null) return;
  const online = isOnline(v);
  const icon = createIcon(v, online);
  const latlng = [v.latitude, v.longitude];

  if (markers[v.vehicle_id]) {
    smoothMove(markers[v.vehicle_id], latlng, 1500);
    markers[v.vehicle_id].setIcon(icon);
  } else {
    markers[v.vehicle_id] = L.marker(latlng, { icon })
      .addTo(map)
      .on('click', () => { selectedVehicleId.value = v.vehicle_id; });
    markers[v.vehicle_id].bindPopup(`<b>${v.vehicle_name}</b><br>${v.license_plate ?? ''}`);
  }
}

function smoothMove(marker, newLatLng, duration) {
  const start = marker.getLatLng();
  const end = L.latLng(newLatLng);
  const t0 = performance.now();
  function step(t) {
    const p = Math.min((t - t0) / duration, 1);
    const e = p < 0.5 ? 2 * p * p : -1 + (4 - 2 * p) * p;
    marker.setLatLng([start.lat + (end.lat - start.lat) * e,
                      start.lng + (end.lng - start.lng) * e]);
    if (p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

function updateAllMarkers() {
  vehicles.value.forEach(v => upsertMarker(v));
}

// ─── ETA (Distance Matrix) ──────────────────────────────
const etaLoading = ref(false);
const etaData = ref({});   // { vehicleId: { distance, duration, duration_in_traffic } }
const etaDestination = ref({ lat: null, lng: null });

async function promptETACalculation(vehicle) {
  const destInput = prompt('กรอกพิกัดปลายทาง (lat, lng)\nเช่น: 18.7883, 98.9853');
  if (!destInput) return;

  const parts = destInput.split(',').map(s => s.trim());
  if (parts.length !== 2 || isNaN(parts[0]) || isNaN(parts[1])) {
    alert('รูปแบบไม่ถูกต้อง กรุณากรอก lat, lng');
    return;
  }

  etaLoading.value = true;
  try {
    const res = await api.get(`/tracking/${vehicle.vehicle_id}/eta`, {
      params: { dest_lat: parts[0], dest_lng: parts[1] },
    });
    etaData.value[vehicle.vehicle_id] = res.data.data;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถคำนวณ ETA ได้');
  } finally {
    etaLoading.value = false;
  }
}

// ─── Data ─────────────────────────────────────────────────
async function refreshLocations() {
  loading.value = true;
  try {
    const res = await api.get('/tracking/current');
    const data = res.data.data ?? [];

    // seed trail for existing vehicles
    data.forEach(v => {
      if (v.latitude != null) addTrailPoint(v.vehicle_id, v.latitude, v.longitude);
      const existing = vehicles.value.find(x => x.vehicle_id === v.vehicle_id);
      if (existing) Object.assign(existing, v);
      else vehicles.value.push(v);
      upsertMarker(v);
    });
  } catch (e) {
    console.error('Failed to fetch locations:', e);
  } finally {
    loading.value = false;
  }
}

// ─── Real-time WebSocket ──────────────────────────────────
function initEcho() {
  if (!window.Echo) return;

  window.Echo.channel('vehicle-tracking')
    .listen('.location.updated', (data) => {
      handleLocationUpdate(data);
    })
    .subscribed(() => {
      wsConnected.value = true;
    })
    .error(() => {
      wsConnected.value = false;
    });

  // Check connection state
  window.Echo.connector.pusher.connection.bind('connected', () => { wsConnected.value = true; });
  window.Echo.connector.pusher.connection.bind('disconnected', () => { wsConnected.value = false; });
  window.Echo.connector.pusher.connection.bind('unavailable', () => { wsConnected.value = false; });
}

function handleLocationUpdate(data) {
  // อัปเดต vehicle list
  const idx = vehicles.value.findIndex(v => v.vehicle_id === data.vehicle_id);
  if (idx !== -1) {
    Object.assign(vehicles.value[idx], data);
  } else {
    vehicles.value.push(data);
  }

  // อัปเดต Marker (smooth move)
  upsertMarker(data);

  // เพิ่ม trail point
  addTrailPoint(data.vehicle_id, data.latitude, data.longitude);
  updateTrailPolyline(data.vehicle_id);
}

// ─── UI Actions ───────────────────────────────────────────
function selectVehicle(v) {
  selectedVehicleId.value = v.vehicle_id;
  if (map && v.latitude && v.longitude) {
    map.flyTo([v.latitude, v.longitude], 14, { duration: 0.8 });
  }
}

function centerAll() {
  if (!map || !L || !vehicles.value.length) return;
  const pts = vehicles.value.filter(v => v.latitude && v.longitude).map(v => [v.latitude, v.longitude]);
  if (!pts.length) return;
  map.fitBounds(L.latLngBounds(pts), { padding: [60, 60] });
}

// ─── Lifecycle ────────────────────────────────────────────
async function loadLeaflet() {
  if (window.L) { L = window.L; return; }
  await new Promise(resolve => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = () => { L = window.L; resolve(); };
    document.head.appendChild(script);
  });
}

onMounted(async () => {
  await loadLeaflet();
  initMap();
  await refreshLocations();
  initEcho();
});

onUnmounted(() => {
  if (window.Echo) window.Echo.leave('vehicle-tracking');
  if (map) { map.remove(); map = null; }
});
</script>

<style scoped>
/* ─── Header ─────────────────────────────── */
.ws-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 500;
  padding: 3px 10px;
  border-radius: 20px;
}
.ws-status.connected  { background: #dcfce7; color: #16a34a; }
.ws-status.disconnected { background: #fee2e2; color: #dc2626; }
.ws-status i { font-size: 8px; }

.header-actions { display: flex; gap: 10px; align-items: center; }

.toggle-trail {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: 13px;
  color: #374151;
  padding: 7px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: white;
  user-select: none;
}
.toggle-trail input { accent-color: #3b82f6; width: 15px; height: 15px; }

/* ─── Layout ─────────────────────────────── */
.tracking-container {
  display: flex;
  height: calc(100vh - 200px);
  min-height: 500px;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  background: #fff;
}

/* ─── Sidebar ────────────────────────────── */
.tracking-sidebar {
  width: 300px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid #e5e7eb;
}

.sidebar-search {
  padding: 12px 14px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #9ca3af;
}
.sidebar-search input { border: none; outline: none; flex: 1; font-size: 14px; color: #374151; }

.vehicle-list { flex: 1; overflow-y: auto; }

.vehicle-list-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 14px;
  cursor: pointer;
  border-bottom: 1px solid #f3f4f6;
  transition: background 0.12s;
}
.vehicle-list-item:hover { background: #f9fafb; }
.vehicle-list-item.active { background: #eff6ff; border-left: 3px solid #3b82f6; }

.vehicle-status-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.vehicle-status-dot.online  { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.5); }
.vehicle-status-dot.offline { background: #d1d5db; }

.vehicle-list-info { flex: 1; min-width: 0; }
.vehicle-list-name  { font-size: 13px; font-weight: 600; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vehicle-list-plate { font-size: 12px; color: #6b7280; }
.vehicle-list-meta  { display: flex; gap: 8px; font-size: 11px; color: #9ca3af; margin-top: 2px; }
.vehicle-list-arrow { color: #d1d5db; font-size: 11px; }

.vehicle-list-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; color: #9ca3af; }
.vehicle-list-empty i { font-size: 30px; margin-bottom: 8px; }

.sidebar-stats { display: flex; border-top: 1px solid #e5e7eb; padding: 10px 12px; gap: 8px; }
.stat-item { flex: 1; text-align: center; padding: 7px; border-radius: 8px; background: #f9fafb; }
.stat-item.online  .stat-count { color: #22c55e; }
.stat-item.offline .stat-count { color: #9ca3af; }
.stat-item.total   .stat-count { color: #3b82f6; }
.stat-count { display: block; font-size: 20px; font-weight: 700; }
.stat-label { font-size: 11px; color: #6b7280; }

/* ─── Map ─────────────────────────────────── */
.tracking-map-wrapper { flex: 1; position: relative; }
.tracking-map { width: 100%; height: 100%; }

/* ─── Info Overlay ───────────────────────── */
.map-info-overlay {
  position: absolute;
  bottom: 20px;
  left: 20px;
  background: white;
  border-radius: 12px;
  padding: 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,.15);
  z-index: 1000;
  min-width: 250px;
}
.map-info-close { position: absolute; top: 8px; right: 8px; background: none; border: none; cursor: pointer; color: #9ca3af; }
.map-info-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.map-info-header > i { font-size: 22px; color: #2d7a4f; }
.map-info-name  { font-size: 15px; font-weight: 700; color: #111827; }
.map-info-plate { font-size: 12px; color: #6b7280; }
.map-info-details { display: flex; flex-direction: column; gap: 5px; margin-bottom: 10px; }
.map-info-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #374151; }
.map-info-row i { width: 14px; text-align: center; color: #9ca3af; }

.map-info-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-clear-trail { flex: 1; justify-content: center; }
.btn-danger { background: #fee2e2; color: #dc2626; border: none; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px; }
.btn-danger:hover { background: #fecaca; }
.btn-eta { background: #eff6ff; color: #2563eb; border: none; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px; flex: 1; justify-content: center; }
.btn-eta:hover { background: #dbeafe; }
.btn-eta:disabled { opacity: 0.5; cursor: not-allowed; }

.map-info-eta { margin: 8px 0; padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; }
.eta-header { font-size: 12px; font-weight: 700; color: #16a34a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.eta-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 4px; }
.eta-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 6px; }
.eta-badge.distance { background: #dbeafe; color: #1e40af; }
.eta-badge.duration { background: #dcfce7; color: #166534; }
.eta-badge.traffic  { background: #fef3c7; color: #92400e; }

/* ─── Custom Marker ──────────────────────── */
:deep(.vehicle-marker-icon) {
  background: transparent !important;
  border: none !important;
  overflow: visible !important;
}
:deep(.mk-wrap) {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 80px;
}
:deep(.mk-dot) {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 3px 10px rgba(0,0,0,.35);
  border: 3px solid white;
  flex-shrink: 0;
  transition: transform 0.4s ease;
}
:deep(.mk-dot svg) {
  display: block;
  flex-shrink: 0;
}
:deep(.mk-label) {
  background: rgba(17,24,39,0.85);
  color: white;
  padding: 3px 7px;
  border-radius: 6px;
  margin-top: 4px;
  box-shadow: 0 2px 6px rgba(0,0,0,.25);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  max-width: 80px;
  pointer-events: none;
}
:deep(.mk-name) {
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 76px;
  line-height: 1.3;
}
:deep(.mk-plate) {
  font-size: 10px;
  font-weight: 400;
  opacity: 0.85;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 76px;
  line-height: 1.2;
}

/* ─── Responsive ─────────────────────────── */
@media (max-width: 768px) {
  .tracking-container { flex-direction: column; height: auto; }
  .tracking-sidebar   { width: 100%; max-height: 250px; border-right: none; border-bottom: 1px solid #e5e7eb; }
  .tracking-map-wrapper { min-height: 400px; }
}
</style>

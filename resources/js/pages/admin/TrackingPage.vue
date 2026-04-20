<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">map</span> ติดตามรถแบบเรียลไทม์</h1>
        <p class="page-subtitle">
          <span class="ws-status" :class="wsConnected ? 'connected' : 'disconnected'">
            <span class="material-symbols-rounded" style="font-size: 10px;">circle</span>
            {{ wsConnected ? 'เชื่อมต่อแล้ว (Real-time)' : 'ไม่ได้เชื่อมต่อ' }}
          </span>
        </p>
      </div>
      <div class="header-actions">
        <label class="toggle-trail">
          <input type="checkbox" v-model="showTrail" @change="toggleTrail" />
          <span style="display:flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">route</span> แสดงเส้นทาง
          </span>
        </label>
        <button class="btn-secondary" @click="centerAll">
          <span class="material-symbols-rounded">zoom_out_map</span> แสดงทั้งหมด
        </button>
        <button class="btn-primary" @click="refreshLocations" :disabled="loading">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">sync</span> รีเฟรช
        </button>
      </div>
    </div>

    <div class="tracking-container">
      <!-- Sidebar -->
      <div class="tracking-sidebar">
        <div class="sidebar-search">
          <span class="material-symbols-rounded">search</span>
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
            <div class="vehicle-list-icon">
              <span class="material-symbols-rounded">{{ getVehicleIcon(v.type) }}</span>
            </div>
            <div class="vehicle-list-info">
              <div class="vehicle-list-name">{{ v.vehicle_name }}</div>
              <div class="vehicle-list-plate">{{ v.license_plate || 'ไม่มีทะเบียน' }}</div>
              <div class="vehicle-list-meta">
                <span v-if="v.speed != null" style="display:flex;align-items:center;gap:2px;">
                  <span class="material-symbols-rounded" style="font-size:12px;">speed</span> {{ Math.round(v.speed) }} km/h
                </span>
                <span>{{ timeAgo(v.recorded_at) }}</span>
              </div>
            </div>
            <span class="material-symbols-rounded vehicle-list-arrow">chevron_right</span>
          </div>
          <div v-if="!filteredVehicles.length" class="vehicle-list-empty">
            <span class="material-symbols-rounded" style="font-size: 32px; margin-bottom: 8px;">directions_car</span>
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
            <span class="material-symbols-rounded">close</span>
          </button>
          <div class="map-info-header">
            <span class="material-symbols-rounded icon-lg highlight">
              {{ getVehicleIcon(selectedVehicle.type) }}
            </span>
            <div>
              <div class="map-info-name">{{ selectedVehicle.vehicle_name }}</div>
              <div class="map-info-plate">{{ selectedVehicle.license_plate }}</div>
            </div>
          </div>
          <div class="map-info-details">
            <div class="map-info-row">
              <span class="material-symbols-rounded text-icon">location_on</span>
              <span>{{ selectedVehicle.latitude?.toFixed(5) }}, {{ selectedVehicle.longitude?.toFixed(5) }}</span>
            </div>
            <div class="map-info-row" v-if="selectedVehicle.speed != null">
              <span class="material-symbols-rounded text-icon">speed</span>
              <span>{{ Math.round(selectedVehicle.speed) }} km/h</span>
            </div>
            <div class="map-info-row" v-if="selectedVehicle.heading != null">
              <span class="material-symbols-rounded text-icon">explore</span>
              <span>{{ Math.round(selectedVehicle.heading) }}°</span>
            </div>
            <div class="map-info-row">
              <span class="material-symbols-rounded text-icon">schedule</span>
              <span>{{ timeAgo(selectedVehicle.recorded_at) }}</span>
            </div>
            <div class="map-info-row" v-if="trailPoints[selectedVehicle.vehicle_id]?.length">
              <span class="material-symbols-rounded text-icon">route</span>
              <span>{{ trailPoints[selectedVehicle.vehicle_id].length }} จุดในเส้นทาง</span>
            </div>
          </div>
          <!-- ETA Section -->
          <div v-if="etaData[selectedVehicle.vehicle_id]" class="map-info-eta">
            <div class="eta-header">
              <span class="material-symbols-rounded" style="font-size:14px;">route</span> ETA ถึงปลายทาง
              <span v-if="etaData[selectedVehicle.vehicle_id].source === 'haversine'"
                style="margin-left:auto;font-size:10px;font-weight:500;color:#92400e;background:#fef3c7;padding:2px 6px;border-radius:4px;border:1px solid #fde68a;">
                ประมาณการ
              </span>
            </div>
            <div class="eta-row">
              <span class="eta-badge distance"><span class="material-symbols-rounded" style="font-size:12px;">add_road</span> {{ etaData[selectedVehicle.vehicle_id].distance?.text }}</span>
              <span class="eta-badge duration"><span class="material-symbols-rounded" style="font-size:12px;">schedule</span> {{ etaData[selectedVehicle.vehicle_id].duration?.text }}</span>
            </div>
            <div v-if="etaData[selectedVehicle.vehicle_id].duration_in_traffic" class="eta-row">
              <span class="eta-badge traffic"><span class="material-symbols-rounded" style="font-size:12px;">directions_car</span> สภาพจราจร: {{ etaData[selectedVehicle.vehicle_id].duration_in_traffic?.text }}</span>
            </div>
          </div>
          <div class="map-info-actions">
            <button class="btn-eta btn-sm" @click="promptETACalculation(selectedVehicle)" :disabled="etaLoading">
              <span class="material-symbols-rounded animate-spin" v-if="etaLoading">sync</span>
              <span class="material-symbols-rounded" v-else>directions</span>
              {{ etaLoading ? 'กำลังคำนวณ...' : 'คำนวณ ETA' }}
            </button>
            <button class="btn-danger btn-sm btn-clear-trail"
              v-if="trailPoints[selectedVehicle.vehicle_id]?.length"
              @click="clearTrail(selectedVehicle.vehicle_id)">
              <span class="material-symbols-rounded">delete</span> ล้างเส้นทาง
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
let pollInterval = null;

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

function getVehicleIcon(type) {
  switch (type) {
    case 'boat': return 'directions_boat';
    case 'van': return 'directions_bus'; /* Changing to directions_bus as it looks more like a van front-on */
    case 'bus': return 'directions_bus';
    default: return 'directions_bus';
  }
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

function createIcon(vehicle, online) {
  const color  = online ? 'var(--color-accent)' : 'var(--color-text-muted)';
  const border = online ? 'var(--color-ocean)' : 'var(--color-sand-dark)';
  const materialIcon = getVehicleIcon(vehicle.type);

  // Pointer rotation — หมุนเฉพาะเข็มชี้ทิศทาง ไอคอนรถจะตั้งตรงเสมอ
  const heading = vehicle.heading ?? 0;
  const hasHeading = vehicle.heading != null;

  // Label: ชื่อรถ + ทะเบียน (ถ้ามี)
  const name  = vehicle.vehicle_name ?? '';
  const plate = vehicle.license_plate ? `<span class="mk-plate">${vehicle.license_plate}</span>` : '';

  const html = `
    <div class="mk-wrap">
      <div class="mk-dot" style="background:${color}; border-color:${border}; display:flex; align-items:center; justify-content:center; position:relative;">
        <span class="material-symbols-rounded" style="color:white; font-size:22px; font-family: 'Material Symbols Rounded';">
          ${materialIcon}
        </span>
        ${hasHeading ? `<div class="mk-pointer" style="transform: rotate(${heading}deg)"></div>` : ''}
      </div>
      <div class="mk-label">
        <span class="mk-name">${name}</span>
        ${plate}
      </div>
    </div>`;

  return L.divIcon({
    className: 'vehicle-marker-icon',
    html,
    iconSize:   [80, 70],
    iconAnchor: [40, 50],
    popupAnchor:[0, -50],
  });
}

// ─── Trail ───────────────────────────────────────────────
function addTrailPoint(vehicleId, lat, lng) {
  if (!trailPoints.value[vehicleId]) {
    trailPoints.value[vehicleId] = [];
  }
  const pts = trailPoints.value[vehicleId];
  
  if (pts.length > 0) {
    const lastPt = pts[pts.length - 1];
    if (lastPt[0] === lat && lastPt[1] === lng) {
      return;
    }
  }

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
async function refreshLocations(isAutoRefresh) {
  if (isAutoRefresh !== true) loading.value = true;
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
    if (isAutoRefresh !== true) loading.value = false;
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
  
  pollInterval = setInterval(() => {
    refreshLocations(true);
  }, 5000);
});

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval);
  if (window.Echo) window.Echo.leave('vehicle-tracking');
  if (map) { map.remove(); map = null; }
});
</script>

<style scoped>
@import url('./admin-shared.css');

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
.ws-status.connected  { background: var(--color-sand); color: var(--color-accent); }
.ws-status.disconnected { background: #fee2e2; color: #dc2626; }

.header-actions { display: flex; gap: 10px; align-items: center; }

.toggle-trail {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  font-size: 13px;
  color: var(--color-text-mid);
  padding: 8px 14px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
  user-select: none;
  font-weight: 600;
}
.toggle-trail input { accent-color: var(--color-accent); width: 15px; height: 15px; }

/* ─── Layout ─────────────────────────────── */
.tracking-container {
  display: flex;
  height: calc(100vh - 200px);
  min-height: 500px;
  border-radius: 16px;
  overflow: hidden;
  border: 1px solid var(--color-sand-dark);
  background: var(--color-white);
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}

/* ─── Sidebar ────────────────────────────── */
.tracking-sidebar {
  width: 320px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--color-sand-dark);
}

.sidebar-search {
  padding: 16px;
  border-bottom: 1px solid var(--color-sand-dark);
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
}
.sidebar-search input { border: none; outline: none; flex: 1; font-size: 14px; color: var(--color-text-dark); background: transparent; }

.vehicle-list { flex: 1; overflow-y: auto; }

.vehicle-list-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  cursor: pointer;
  border-bottom: 1px solid var(--color-sand-dark);
  transition: background 0.15s;
}
.vehicle-list-item:hover { background: var(--color-sand); }
.vehicle-list-item.active { background: #eff6ff; border-left: 4px solid var(--color-accent); padding-left: 12px; }

.vehicle-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.vehicle-status-dot.online  { background: var(--color-accent); box-shadow: 0 0 6px rgba(45, 122, 79, 0.4); }
.vehicle-status-dot.offline { background: var(--color-text-muted); }

.vehicle-list-info { flex: 1; min-width: 0; }
.vehicle-list-name  { font-size: 14px; font-weight: 700; color: var(--color-text-dark); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vehicle-list-plate { font-size: 12px; color: var(--color-text-muted); margin-top: 2px;}
.vehicle-list-meta  { display: flex; gap: 8px; font-size: 11px; color: var(--color-text-muted); margin-top: 4px; }
.vehicle-list-arrow { color: var(--color-text-muted); font-size: 16px; }

.vehicle-list-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: var(--color-sand);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-ocean);
  flex-shrink: 0;
}
.vehicle-list-item.active .vehicle-list-icon { background: var(--color-white); color: var(--color-accent); }

.vehicle-list-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; color: var(--color-text-muted); }
.vehicle-list-empty p { font-size: 14px; margin-top: 8px; }

.sidebar-stats { display: flex; border-top: 1px solid var(--color-sand-dark); padding: 12px; gap: 8px; background: var(--color-white); }
.stat-item { flex: 1; text-align: center; padding: 10px; border-radius: 10px; background: var(--color-sand); border: 1px solid var(--color-sand-dark); }
.stat-item.online  .stat-count { color: var(--color-accent); }
.stat-item.offline .stat-count { color: var(--color-text-muted); }
.stat-item.total   .stat-count { color: var(--color-ocean); }
.stat-count { display: block; font-size: 20px; font-weight: 700; }
.stat-label { font-size: 11px; color: var(--color-text-mid); font-weight: 500; margin-top: 4px; display: block; }

/* ─── Map ─────────────────────────────────── */
.tracking-map-wrapper { flex: 1; position: relative; }
.tracking-map { width: 100%; height: 100%; border-top-right-radius: 16px; border-bottom-right-radius: 16px;}

/* ─── Info Overlay ───────────────────────── */
.map-info-overlay {
  position: absolute;
  bottom: 20px;
  left: 20px;
  background: var(--color-white);
  border-radius: 16px;
  padding: 20px;
  box-shadow: 0 4px 24px rgba(0,0,0,.1);
  z-index: 1000;
  min-width: 280px;
  border: 1px solid var(--color-sand-dark);
}
.map-info-close { position: absolute; top: 12px; right: 12px; background: none; border: none; cursor: pointer; color: var(--color-text-muted); transition: color 0.15s; }
.map-info-close:hover { color: var(--color-text-dark); }

.map-info-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--color-sand-dark);}
.icon-lg { font-size: 28px; color: var(--color-accent); }
.map-info-name  { font-size: 16px; font-weight: 700; color: var(--color-text-dark); }
.map-info-plate { font-size: 12px; color: var(--color-text-muted); margin-top: 2px;}

.map-info-details { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
.map-info-row { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--color-text-mid); }
.text-icon { font-size: 16px; color: var(--color-text-muted); }

.map-info-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--color-sand-dark);}
.btn-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 12px; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 6px; flex: 1; justify-content: center; font-weight: 600;}
.btn-danger:hover { background: #fecaca; }
.btn-eta { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 6px; flex: 1; justify-content: center; font-weight: 600;}
.btn-eta:hover { background: #dbeafe; }
.btn-eta:disabled { opacity: 0.5; cursor: not-allowed; }

.map-info-eta { margin: 12px 0 0 0; padding: 12px; background: var(--color-sand); border: 1px solid var(--color-sand-dark); border-radius: 10px; }
.eta-header { font-size: 12px; font-weight: 700; color: var(--color-text-dark); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.eta-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.eta-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
.eta-badge.distance { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.eta-badge.duration { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.eta-badge.traffic  { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

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
  box-shadow: 0 4px 12px rgba(0,0,0,.15);
  border: 3px solid white;
  flex-shrink: 0;
  position: relative;
}
:deep(.mk-pointer) {
  position: absolute;
  top: -8px;
  left: 50%;
  margin-left: -6px;
  width: 0;
  height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-bottom: 10px solid var(--color-accent);
  filter: drop-shadow(0 2px 2px rgba(0,0,0,0.2));
  transform-origin: 50% 27px; /* หมุนรอบจุดศูนย์กลางของ mk-dot (38px/2 + 8px = 27px) */
  z-index: 2;
}
:deep(.mk-label) {
  background: var(--color-white);
  color: var(--color-text-dark);
  padding: 4px 8px;
  border-radius: 6px;
  margin-top: 4px;
  box-shadow: 0 2px 8px rgba(0,0,0,.1);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  max-width: 80px;
  pointer-events: none;
  border: 1px solid var(--color-sand-dark);
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
  font-weight: 500;
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 76px;
  line-height: 1.2;
}

/* ─── Responsive ─────────────────────────── */
@media (max-width: 768px) {
  .tracking-container { flex-direction: column; height: auto; }
  .tracking-sidebar   { width: 100%; max-height: 280px; border-right: none; border-bottom: 1px solid var(--color-sand-dark); }
  .tracking-map-wrapper { min-height: 400px; }
  .map-info-overlay { left: 10px; right: 10px; bottom: 10px; min-width: 0; }
}
</style>

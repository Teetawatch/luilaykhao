<template>
  <!--
    ดวงเหรียญพิชิต — ตัวเดียวกับ partials/medal-art.blade.php (หน้าเว็บสาธารณะ)
    ริบบิ้น/ขอบหยัก/ช่อใบไม้/แถบป้าย มาจาก App\Support\MedalGeometry ผ่าน /admin/medal-options
    ส่วนรัศมีวงกลม เส้นโค้งตัวอักษร และตำแหน่งไอคอน/ชื่อ คัดลอกค่าคงที่ของคลาสนั้นไว้ตรงนี้
    — แก้ MedalGeometry แล้วต้องแก้ที่นี่และ lib/widgets/medal_art.dart ด้วย
  -->
  <div class="medal-art" :style="{ width: `${size}px`, height: `${size * 1.18}px` }">
    <img v-if="design.image" :src="design.image" class="medal-art__custom" alt="ภาพเหรียญ" />
    <svg v-else-if="geometry" viewBox="0 0 100 118" :width="size" :height="size * 1.18" aria-hidden="true">
      <defs>
        <path :id="arcId" d="M 11.8,68 A 38.2,38.2 0 0,1 88.2,68" />
      </defs>
      <polygon v-for="(band, i) in geometry.ribbon.bands" :key="`b${i}`" :points="points(band)" :fill="ribbonColor" />
      <polygon v-for="(stripe, i) in geometry.ribbon.stripes" :key="`s${i}`" :points="points(stripe)" fill="#fff" />

      <circle cx="50" cy="68" r="44" :fill="gold" />
      <circle v-for="(p, i) in geometry.scallops" :key="`c${i}`" :cx="p.x" :cy="p.y" r="5.6" :fill="gold" />
      <circle cx="50" cy="68" r="41" fill="#A87A2A" />
      <circle cx="50" cy="68" r="35.5" :fill="gold" />
      <circle cx="50" cy="68" r="34" :fill="design.color" />

      <text class="medal-art__ring" fill="#FCE9C0" font-size="4.2" dominant-baseline="central">
        <textPath :href="`#${arcId}`" startOffset="50%" text-anchor="middle">{{ ringText }}</textPath>
      </text>

      <ellipse
        v-for="(leaf, i) in geometry.laurel"
        :key="`l${i}`"
        :cx="leaf.cx"
        :cy="leaf.cy"
        :rx="leaf.rx"
        :ry="leaf.ry"
        :transform="`rotate(${leaf.deg} ${leaf.cx} ${leaf.cy})`"
        fill="#F2C66D"
      />

      <text class="medal-art__icon material-symbols-rounded" x="50" y="52" font-size="17" text-anchor="middle" dominant-baseline="central" fill="#fff">{{ design.icon }}</text>

      <foreignObject x="28" y="62" width="44" height="15">
        <div xmlns="http://www.w3.org/1999/xhtml" class="medal-art__name">{{ design.name }}</div>
      </foreignObject>

      <polygon :points="points(geometry.banner)" fill="#FBF3E1" />
      <text class="medal-art__banner" x="50" y="87" font-size="5" text-anchor="middle" dominant-baseline="central" :fill="bannerInk">FINISHER</text>
    </svg>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  /** { name, icon, color, image } */
  design: { type: Object, required: true },
  /** MedalGeometry::forClient() — null ระหว่างโหลด */
  geometry: { type: Object, default: null },
  /** ปี พ.ศ. ที่วิ่งรอบขอบ — null = ไม่ใส่ปี */
  year: { type: Number, default: null },
  size: { type: Number, default: 150 },
});

const gold = '#D9A441';
const arcId = `medal-arc-${Math.random().toString(36).slice(2, 9)}`;

// ต้องตรงกับ MedalGeometry::ringText()
const ringText = computed(() => (props.year
  ? `LUILAYKHAO  •  FINISHER  •  ${props.year}`
  : 'LUILAYKHAO  •  FINISHER'));

const mix = (hex, amount) => {
  const n = parseInt(String(hex || '#15803D').replace('#', ''), 16);
  const ch = (shift) => Math.round(((n >> shift) & 255) * (1 - amount));
  return `rgb(${ch(16)}, ${ch(8)}, ${ch(0)})`;
};

const ribbonColor = computed(() => mix(props.design.color, 0.25));
const bannerInk = computed(() => mix(props.design.color, 0.35));

const points = (xy) => {
  const out = [];
  for (let i = 0; i < xy.length; i += 2) out.push(`${xy[i]},${xy[i + 1]}`);
  return out.join(' ');
};
</script>

<style scoped>
.medal-art { flex: none; }
.medal-art svg { display: block; overflow: visible; }
.medal-art__custom { width: 100%; height: 100%; object-fit: contain; }
.medal-art__ring,
.medal-art__banner {
  font-family: 'Anuphan', sans-serif;
  font-weight: 800;
  letter-spacing: .6px;
}
.medal-art__icon {
  font-family: 'Material Symbols Rounded' !important;
  font-size: 17px;
  font-variation-settings: 'FILL' 1;
}
.medal-art__name {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  color: #fff;
  font-family: 'Anuphan', sans-serif;
  font-size: 5.6px;
  font-weight: 800;
  line-height: 1.2;
  overflow: hidden;
}
</style>

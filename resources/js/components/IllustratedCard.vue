<template>
  <article class="illus-card" :class="{ 'illus-card--sand': sand }">
    <div class="illus-card-media">
      <img :src="image" alt="" loading="lazy" />
    </div>
    <h3 class="mt-5 font-extrabold text-[var(--color-text-dark)]" :class="small ? 'text-lg' : 'text-xl'">{{ title }}</h3>
    <p class="mt-2 text-[15px] text-[var(--color-text-mid)] leading-relaxed">{{ desc }}</p>
  </article>
</template>

<script setup>
defineProps({
  image: { type: String, required: true },
  title: { type: String, required: true },
  desc: { type: String, default: '' },
  /** วางบนพื้นขาว — การ์ดเป็นสีเทาอ่อนแทน */
  sand: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
});
</script>

<style scoped>
.illus-card {
  display: flex;
  flex-direction: column;
  height: 100%;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  padding: 1.25rem 1.25rem 1.5rem;
  transition: border-color 0.2s ease;
}
.illus-card:hover { border-color: color-mix(in srgb, var(--color-accent) 35%, white); }
.illus-card--sand { background-color: var(--color-sand); border-color: transparent; }
.illus-card-media {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 4 / 3;
  border-radius: 1rem;
  background-color: var(--color-sand);
  overflow: hidden;
}
.illus-card--sand .illus-card-media { background-color: #fff; }
.illus-card-media img {
  width: 85%;
  height: 85%;
  object-fit: contain;
  transition: transform 0.4s ease;
}
.illus-card:hover .illus-card-media img { transform: scale(1.04); }
</style>

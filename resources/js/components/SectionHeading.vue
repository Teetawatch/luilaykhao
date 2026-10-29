<template>
  <div :class="center ? 'text-center mx-auto' : ''" class="max-w-3xl">
    <p
      v-if="eyebrow"
      class="inline-flex items-center gap-2 text-[13px] font-bold"
      :class="[center ? 'justify-center' : '', tone === 'gold' ? 'text-[var(--color-gold)]' : 'text-[var(--color-accent)]']"
    >
      <span class="w-6 h-[2px] rounded-full" :class="tone === 'gold' ? 'bg-[var(--color-gold)]' : 'bg-[var(--color-accent)]'"></span>
      {{ eyebrow }}
    </p>
    <h2 class="mt-3 text-3xl md:text-4xl font-extrabold text-[var(--color-text-dark)] leading-tight tracking-tight">
      <slot>{{ title }}</slot>
    </h2>
    <p v-if="lead" class="mt-4 text-base md:text-lg text-[var(--color-text-mid)] leading-relaxed">{{ lead }}</p>
  </div>
</template>

<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, default: '' },
  lead: { type: String, default: '' },
  center: { type: Boolean, default: false },
  tone: { type: String, default: 'accent' },
});
</script>

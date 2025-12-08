<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    type?: 'button' | 'submit' | 'reset'
    loading?: boolean
    disabled?: boolean
    block?: boolean
    variant?: 'primary' | 'ghost'
  }>(),
  {
    type: 'button',
    block: true,
    variant: 'primary',
  },
)
</script>

<template>
  <button
    :type="props.type"
    :disabled="props.disabled || props.loading"
    :class="[
      'inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-semibold uppercase tracking-wide transition-all duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
      props.block ? 'w-full' : '',
      props.variant === 'primary'
        ? 'zee-btn'
        : 'border border-white/10 bg-white/5 text-zeel-muted hover:border-zeel-primary/60 hover:bg-white/10 focus-visible:outline-zeel-primary/40',
    ]"
  >
    <svg
      v-if="props.loading"
      class="size-4 animate-spin text-white"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
    <span class="text-sm">
      <slot />
    </span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    variant?: 'success' | 'error' | 'info'
  }>(),
  {
    variant: 'info',
  },
)

const styles = computed(() => {
  switch (props.variant) {
    case 'success':
      return 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-200'
    case 'error':
      return 'border border-rose-500/30 bg-rose-500/10 text-rose-200'
    default:
      return 'border border-sky-500/30 bg-sky-500/10 text-sky-100'
  }
})
</script>

<template>
  <div :class="['rounded-xl px-4 py-3 text-sm', styles]">
    <slot />
  </div>
</template>

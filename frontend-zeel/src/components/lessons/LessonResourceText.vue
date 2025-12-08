<script setup lang="ts">
import { ref } from 'vue'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{ lesson: LessonDetail }>()

const expanded = ref(false)
const toggle = () => {
  expanded.value = !expanded.value
}
</script>

<template>
  <section
    class="rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-5 text-[var(--app-text)] shadow-[var(--app-card-shadow)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 dark:text-white dark:shadow-[0_25px_70px_rgba(0,0,0,0.55)]"
  >
    <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-white/60">
      <p>Resource text</p>
      <button
        class="text-[10px] font-medium uppercase tracking-[0.2em] text-[var(--app-accent-strong)] transition hover:text-[var(--app-accent)]"
        @click="toggle"
      >
        {{ expanded ? 'Show less' : 'Show more' }}
      </button>
    </div>
    <div
      class="mt-3 max-h-[420px] overflow-y-auto rounded-[18px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-5 py-4 text-[15px] leading-relaxed text-[var(--app-text)] dark:border-white/10 dark:bg-black/30 dark:text-white/80"
      :class="{ 'max-h-none': expanded }"
    >
      {{ lesson.original_text }}
    </div>
  </section>
</template>

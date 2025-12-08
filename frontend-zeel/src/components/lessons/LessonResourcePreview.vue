<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Lesson } from '@/mock/lessonData'

const props = defineProps<{ lesson: Lesson }>()

const showFull = ref(false)
const toggleText = () => {
  showFull.value = !showFull.value
}

const pillLabel = computed(() =>
  props.lesson.resourceType === 'video' ? 'From video' : 'Text resource',
)

const snippet = computed(() => {
  const text = props.lesson.originalText ?? ''
  if (showFull.value || text.length <= 400) {
    return text
  }
  return `${text.slice(0, 400)}…`
})
</script>

<template>
  <section class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-6 shadow-sm dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:shadow-[0_18px_45px_rgba(0,0,0,0.6)]">
    <div class="flex flex-wrap items-center justify-between gap-3 text-xs font-semibold uppercase tracking-[0.25em] text-[var(--app-border-dark)] dark:text-[var(--app-border)]">
      <span class="rounded-full border border-[var(--app-border)] px-3 py-1 text-[11px] text-[var(--app-surface-dark)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-surface)]">
        {{ pillLabel }}
      </span>
      <a
        v-if="lesson.resourceType === 'video' && lesson.videoUrl"
        :href="lesson.videoUrl"
        target="_blank"
        rel="noopener"
        class="rounded-full border border-[var(--app-border)] px-3 py-1 text-[11px] text-[var(--app-surface-dark)] transition hover:border-[var(--app-accent)] hover:text-[var(--app-accent-strong)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-surface)]"
      >
        Open original
      </a>
    </div>

    <div class="mt-4 space-y-3">
      <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[var(--app-border-dark)] dark:text-[var(--app-border)]">
        {{ lesson.resourceType === 'video' ? 'Transcript' : 'Resource text' }}
      </p>
      <div class="max-h-40 overflow-y-auto rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3 text-sm leading-relaxed text-[var(--app-surface-dark)] shadow-inner dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-[var(--app-surface)]">
        {{ snippet }}
      </div>
      <div class="flex justify-end">
        <button class="text-sm font-medium text-[var(--app-accent-strong)] hover:underline" @click="toggleText()">
          {{ showFull ? 'Show less' : 'Show more' }}
        </button>
      </div>
    </div>
  </section>
</template>

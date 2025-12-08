<script setup lang="ts">
import type { Lesson } from '@/types/lesson'

const props = defineProps<{
  lesson: Lesson
  selected: boolean
}>()

const emit = defineEmits<{ select: [id: number] }>()

const resourceLabels: Record<string, string> = {
  text: 'Text',
  video: 'Video',
  audio: 'Audio',
  youtube: 'YouTube',
}

const formatMeta = (lesson: Lesson) => {
  const level = lesson.level ? ` • Level ${lesson.level}` : ''
  const resourceLabel = resourceLabels[lesson.resource_type] ?? lesson.resource_type
  return `${new Date(lesson.created_at).toLocaleDateString()} • ${resourceLabel}${level}`
}
</script>

<template>
  <button
    class="w-full rounded-2xl border px-4 py-3 text-left transition"
    :class="[
      props.selected
        ? 'border-[var(--app-accent)] bg-[var(--app-panel)] text-[var(--app-text)] shadow-[var(--app-card-shadow)] dark:bg-[var(--app-surface-dark)] dark:text-white dark:shadow-[0_10px_30px_rgba(0,0,0,0.35)]'
        : 'border-transparent bg-[var(--app-surface-elevated)] text-[var(--app-text)] hover:border-[var(--app-border)] hover:bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/70 dark:text-white dark:hover:border-[var(--app-border-dark)]',
    ]"
    @click="emit('select', props.lesson.id)"
  >
    <p class="text-sm font-semibold">
      {{ props.lesson.title }}
    </p>
    <p class="mt-1 text-xs text-[var(--app-text-muted)] dark:text-white/60">
      {{ formatMeta(props.lesson) }}
    </p>
    <p class="mt-1 line-clamp-1 text-sm text-[var(--app-text-muted)] dark:text-white/70">
      {{ props.lesson.short_description }}
    </p>
  </button>
</template>

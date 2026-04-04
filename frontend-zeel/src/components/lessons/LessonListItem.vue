<script setup lang="ts">
import type { Lesson } from '@/types/lesson'

const props = defineProps<{
  lesson: Lesson
  collapsed?: boolean
  selected: boolean
}>()

const emit = defineEmits<{ select: [id: number] }>()

const resourceLabels: Record<string, string> = {
  text: 'Text',
  video: 'Video',
  audio: 'Audio',
  youtube: 'YouTube',
  text_ai: 'AI text',
}

const formatMeta = (lesson: Lesson) => {
  const level = lesson.level ? ` • Level ${lesson.level}` : ''
  const resourceLabel = resourceLabels[lesson.resource_type] ?? lesson.resource_type
  return `${new Date(lesson.created_at).toLocaleDateString()} • ${resourceLabel}${level}`
}

</script>

<template>
  <article
    class="rounded-2xl border transition"
    :class="[
      props.selected
        ? 'border-[var(--app-accent)] bg-[var(--app-panel)] text-[var(--app-text)] shadow-[var(--app-card-shadow)] dark:bg-[var(--app-surface-dark)] dark:text-white dark:shadow-[0_10px_30px_rgba(0,0,0,0.35)]'
        : 'border-transparent bg-[var(--app-surface-elevated)] text-[var(--app-text)] hover:border-[var(--app-border)] hover:bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/70 dark:text-white dark:hover:border-[var(--app-border-dark)]',
      props.collapsed ? 'overflow-hidden' : '',
    ]"
    :title="props.collapsed ? props.lesson.title : undefined"
  >
    <button
      type="button"
      class="w-full text-left"
      :class="props.collapsed ? 'flex min-h-[52px] items-center justify-center px-1.5 py-2' : 'px-4 py-3'"
      @click="emit('select', props.lesson.id)"
    >
      <template v-if="props.collapsed">
        <span
          class="flex h-8 w-8 items-center justify-center rounded-xl border text-[11px] font-semibold uppercase tracking-[0.04em]"
          :class="props.selected
            ? 'border-[var(--app-accent)] bg-[var(--app-accent-soft)] text-[var(--app-accent-strong)]'
            : 'border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/70'"
        >
          {{ props.lesson.title.trim().slice(0, 2) || 'L' }}
        </span>
      </template>
      <template v-else>
        <p class="text-sm font-semibold">
          {{ props.lesson.title }}
        </p>
        <p class="mt-1 text-xs text-[var(--app-text-muted)] dark:text-white/60">
          {{ formatMeta(props.lesson) }}
        </p>
        <p class="mt-1 line-clamp-1 text-sm text-[var(--app-text-muted)] dark:text-white/70">
          {{ props.lesson.short_description }}
        </p>
      </template>
    </button>

  </article>
</template>

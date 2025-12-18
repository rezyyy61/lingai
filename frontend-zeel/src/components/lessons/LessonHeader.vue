<script setup lang="ts">
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{ lesson: LessonDetail }>()

const resourceMetaMap: Record<string, string> = {
  text: 'Text resource',
  video: 'Video resource',
  audio: 'Audio resource',
  youtube: 'YouTube resource',
}

const metaLabel = (lesson: LessonDetail) =>
  resourceMetaMap[lesson.resource_type] ?? 'Text resource'
</script>

<template>
  <header class="space-y-3 text-[var(--app-text)] dark:text-white">
    <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-[var(--app-text-muted)] dark:text-white/50">
      Lesson
    </p>
    <div class="space-y-1">
      <h2 class="text-2xl font-semibold leading-snug">
        {{ lesson.title }}
      </h2>
      <p class="text-sm text-[var(--app-text-muted)] dark:text-white/60">
        {{ metaLabel(lesson) }}
        <template v-if="lesson.level"> • Level {{ lesson.level }}</template>
      </p>
    </div>
  </header>
</template>

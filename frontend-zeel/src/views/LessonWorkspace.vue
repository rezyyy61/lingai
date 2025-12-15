<script setup lang="ts">
import LessonHeader from '@/components/lessons/LessonHeader.vue'
import LessonResourceText from '@/components/lessons/LessonResourceText.vue'
import LessonTabs from '@/components/lessons/LessonTabs.vue'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{ lesson: LessonDetail | null; loading: boolean; error: string }>()
</script>

<template>
  <template v-if="props.loading">
    <div
      class="rounded-[28px] border border-[var(--app-border)]/70 bg-[var(--app-surface-elevated)]/85 px-6 py-6 text-center text-sm text-slate-500 shadow-sm dark:border-white/5 dark:bg-white/5 dark:text-slate-200 xl:col-span-2"
    >
      Loading lesson...
    </div>
  </template>
  <template v-else-if="props.error">
    <div
      class="rounded-[28px] border border-[var(--app-accent-strong)]/50 bg-[var(--app-accent-soft)]/90 px-6 py-6 text-center text-sm text-[var(--app-accent-strong)] dark:bg-[color:rgba(194,65,12,0.12)] dark:text-[var(--app-accent)] xl:col-span-2"
    >
      {{ props.error }}
    </div>
  </template>
  <template v-else-if="props.lesson">
    <section
      class="flex h-[calc(100vh-340px)] flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-panel)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white dark:shadow-[0_30px_80px_rgba(0,0,0,0.5)]"
    >
      <LessonHeader :lesson="props.lesson" />
      <div class="mt-6 flex-1 min-h-0">
        <LessonResourceText :lesson="props.lesson" class="h-full" />
      </div>
    </section>

    <section
      class="flex h-[calc(100vh-340px)] flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90 dark:text-white dark:shadow-[0_35px_95px_rgba(0,0,0,0.6)]"
    >
      <LessonTabs :lesson="props.lesson" />
    </section>
  </template>
  <template v-else>
    <div
      class="rounded-[28px] border border-dashed border-[var(--app-border)] bg-[var(--app-surface-elevated)]/85 px-8 py-12 text-center text-[var(--app-text-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/85 dark:text-slate-400 xl:col-span-2"
    >
      Select a lesson from the list to begin learning.
    </div>
  </template>
</template>

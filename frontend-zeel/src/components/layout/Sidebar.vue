<script setup lang="ts">
import LessonList from '@/components/lessons/LessonList.vue'
import type { Lesson } from '@/types/lesson'

const props = defineProps<{
  lessons: Lesson[]
  loading: boolean
  error: string
  selectedId: number | null
  q: string
  level: string
  resourceType: string
}>()

const emit = defineEmits<{
  select: [id: number]
  'update:q': [value: string]
  'update:level': [value: string]
  'update:resource-type': [value: string]
}>()

const levels = ['A2', 'B1', 'B2', 'C1']
const resourceOptions = [
  { label: 'All resources', value: '' },
  { label: 'Text', value: 'text' },
  { label: 'Audio', value: 'audio' },
  { label: 'YouTube', value: 'youtube' },
  { label: 'Video', value: 'video' },
]

const handleSelect = (id: number) => emit('select', id)
</script>

<template>
  <aside
    class="flex h-full flex-col gap-4 rounded-[28px] border border-[var(--app-border)] bg-[var(--app-panel)] p-4 text-[var(--app-text)] shadow-[var(--app-card-shadow)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/70 dark:text-white xl:sticky xl:top-28 xl:max-h-[calc(100vh-9rem)]"
  >
    <div class="space-y-3">
      <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-[var(--app-text-muted)] dark:text-white/60">
        Lessons
      </p>
      <div class="relative">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[var(--app-text-muted)] dark:text-white/50">
          ⌕
        </span>
        <input
          :value="q"
          type="text"
          placeholder="Search lessons"
          class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] py-2.5 pl-8 pr-3 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
          @input="emit('update:q', ($event.target as HTMLInputElement).value)"
        />
      </div>
    </div>
    <div class="flex gap-2 text-sm">
      <select
        :value="level"
        class="flex-1 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-white/10 dark:bg-white/5 dark:text-white"
        @change="emit('update:level', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">All levels</option>
        <option v-for="lvl in levels" :key="lvl" :value="lvl">{{ lvl }}</option>
      </select>
      <select
        :value="resourceType"
        class="flex-1 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-white/10 dark:bg-white/5 dark:text-white"
        @change="emit('update:resource-type', ($event.target as HTMLSelectElement).value)"
      >
        <option
          v-for="option in resourceOptions"
          :key="option.value"
          :value="option.value"
        >
          {{ option.label }}
        </option>
      </select>
    </div>
    <div class="flex-1 overflow-hidden">
      <div class="flex h-full flex-col overflow-y-auto pr-1">
        <p
          v-if="loading"
          class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/80 px-4 py-3 text-center text-sm text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/70"
        >
          Loading lessons...
        </p>
        <p
          v-else-if="error"
          class="rounded-2xl border border-[var(--app-accent-strong)]/40 bg-[color:rgba(249,115,22,0.08)] px-4 py-3 text-sm text-[var(--app-accent-strong)] dark:border-[var(--app-accent-strong)]/60 dark:bg-[color:rgba(194,65,12,0.15)] dark:text-[var(--app-accent-strong)]"
        >
          {{ error }}
        </p>
        <LessonList
          v-else
          :lessons="lessons"
          :selected-id="selectedId"
          @select="handleSelect"
        />
      </div>
    </div>
  </aside>
</template>

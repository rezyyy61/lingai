<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Lesson } from '@/mock/lessonData'

const props = defineProps<{
  lessons: Lesson[]
  selectedLessonId: number
}>()

const emit = defineEmits<{ select: [lessonId: number] }>()

const searchQuery = ref('')

const filteredLessons = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) return props.lessons
  return props.lessons.filter((lesson) => lesson.title.toLowerCase().includes(query))
})

const resourceLabel = (lesson: Lesson) => (lesson.resourceType === 'video' ? 'Video resource' : 'Text resource')

const handleSelect = (lessonId: number) => {
  emit('select', lessonId)
}
</script>

<template>
<aside class="flex h-full flex-col gap-4 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm shadow-[0_12px_40px_rgba(0,0,0,0.05)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:shadow-[0_18px_45px_rgba(0,0,0,0.45)]">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[var(--app-border-dark)] dark:text-[var(--app-border)]">Lessons</p>
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search"
        class="mt-3 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 text-sm text-[var(--app-surface-dark)] placeholder:text-[var(--app-border-dark)] shadow-inner shadow-[rgba(0,0,0,0.02)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[color:var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-surface)] dark:placeholder:text-[var(--app-border)]"
      />
    </div>
    <div class="space-y-3 overflow-y-auto pr-1">
      <button
        v-for="lesson in filteredLessons"
        :key="lesson.id"
        type="button"
        :class="[
          'w-full rounded-xl border px-4 py-3 text-left transition shadow-sm',
          lesson.id === selectedLessonId
            ? 'border-[var(--app-accent)] bg-[var(--app-accent-soft)] text-[var(--app-surface-dark)] dark:border-[var(--app-accent)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-surface)]'
            : 'border-transparent bg-[var(--app-surface-elevated)] text-[var(--app-surface-dark)] hover:border-[var(--app-border)] hover:bg-[var(--app-surface-elevated)]/80 dark:bg-[var(--app-surface-dark)] dark:text-[var(--app-surface)]',
        ]"
        @click="handleSelect(lesson.id)"
      >
        <p class="text-sm font-semibold">{{ lesson.title }}</p>
        <p class="mt-1 text-xs text-[var(--app-border-dark)] dark:text-[var(--app-border)]">
          {{ lesson.createdAt }} • {{ resourceLabel(lesson) }}<span v-if="lesson.level"> • Level {{ lesson.level }}</span>
        </p>
        <p class="mt-2 text-sm text-[var(--app-border-dark)] line-clamp-2 dark:text-[var(--app-border)]">{{ lesson.shortDescription }}</p>
        <div v-if="lesson.tags?.length" class="mt-3 flex flex-wrap gap-2 text-xs">
          <span
            v-for="tag in lesson.tags"
            :key="tag"
            class="rounded-full border border-[var(--app-border)] px-2 py-0.5 text-[11px] uppercase tracking-wide text-[var(--app-border-dark)] bg-[var(--app-surface-elevated)]/60 dark:border-[var(--app-border-dark)] dark:bg-transparent dark:text-[var(--app-border)]"
          >
            {{ tag }}
          </span>
        </div>
      </button>
      <p v-if="!filteredLessons.length" class="rounded-xl border border-dashed border-[var(--app-border)] p-4 text-center text-sm text-[var(--app-border-dark)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-border)]">
        No lessons found.
      </p>
    </div>
  </aside>
</template>

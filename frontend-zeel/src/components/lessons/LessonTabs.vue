<script setup lang="ts">
import { ref } from 'vue'
import type { LessonDetail } from '@/types/lesson'
import LessonTabFlashcards from './LessonTabFlashcards.vue'
import LessonTabShadowing from './LessonTabShadowing.vue'
import LessonGrammarTab from './LessonGrammarTab.vue'
import LessonTabExercises from './LessonTabExercises.vue'
import LessonTabNotes from './LessonTabNotes.vue'

const props = defineProps<{ lesson: LessonDetail }>()

const activeTab = ref<'flashcards' | 'shadowing' | 'grammar' | 'exercises' | 'notes'>('flashcards')

const tabs = [
  { id: 'flashcards', label: 'Flashcards' },
  { id: 'shadowing', label: 'Shadowing' },
  { id: 'grammar', label: 'Grammar' },
  { id: 'exercises', label: 'Exercises' },
  { id: 'notes', label: 'Notes' },
] as const

const setActiveTab = (id: typeof activeTab.value) => {
  activeTab.value = id
}
</script>

<template>
  <section
    class="flex h-full w-full max-w-full flex-col overflow-x-hidden text-[var(--app-text)] dark:text-[var(--app-text)]"
  >
    <!-- Tabs header -->
    <div
      class="w-full max-w-full overflow-x-auto"
    >
      <div
        class="inline-flex min-w-full flex-wrap gap-2 rounded-[18px] border border-[var(--app-border)] bg-[var(--app-panel-muted)]/60 p-1 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 sm:gap-3 sm:rounded-[999px] sm:p-1.5"
      >
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="flex-1 rounded-full px-3 py-2 text-xs font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--app-accent)] sm:flex-none sm:px-5 sm:py-2.5 sm:text-sm"
          :class="[
            activeTab === tab.id
              ? 'bg-[var(--app-accent)] text-white shadow-[0_12px_30px_rgba(249,115,22,0.35)]'
              : 'border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text-muted)] hover:text-[var(--app-text)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 dark:text-[var(--app-text-muted)] dark:hover:text-[var(--app-text)] dark:hover:bg-[color:rgba(255,255,255,0.05)]',
          ]"
          @click="setActiveTab(tab.id)"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <!-- Tab content -->
    <div
      class="mt-6 flex-1 w-full max-w-full overflow-x-hidden rounded-[22px] border border-[var(--app-border)] bg-[var(--app-panel)] p-4 shadow-[var(--app-card-shadow-strong)] sm:p-6 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:shadow-[0_35px_80px_rgba(0,0,0,0.55)]"
    >
      <LessonTabFlashcards
        v-if="activeTab === 'flashcards'"
        :lesson-id="lesson.id"
      />
      <LessonTabShadowing
        v-else-if="activeTab === 'shadowing'"
        :lesson="lesson"
      />
      <LessonGrammarTab
        v-else-if="activeTab === 'grammar'"
        :lesson-id="lesson.id"
      />
      <LessonTabExercises
        v-else-if="activeTab === 'exercises'"
        :lesson-id="lesson.id"
      />
      <LessonTabNotes v-else />
    </div>
  </section>
</template>

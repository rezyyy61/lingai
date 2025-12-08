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
  <section class="flex h-full flex-col text-[var(--app-text)] dark:text-[var(--app-text)]">
    <div
      class="flex flex-wrap gap-3 rounded-[999px] border border-[var(--app-border)] bg-[var(--app-panel-muted)]/60 p-1.5 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80"
    >
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="rounded-full px-5 py-2.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--app-accent)]"
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
    <div
      class="mt-6 flex-1 rounded-[22px] border border-[var(--app-border)] bg-[var(--app-panel)] p-6 shadow-[var(--app-card-shadow-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:shadow-[0_35px_80px_rgba(0,0,0,0.55)]"
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

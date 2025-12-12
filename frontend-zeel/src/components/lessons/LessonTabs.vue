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
    <div class="w-full max-w-full px-1">
      <div
        class="grid grid-cols-5 gap-1 rounded-[16px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-1 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] sm:inline-flex sm:w-auto sm:grid-cols-none sm:gap-2 sm:rounded-[999px] sm:bg-[var(--app-panel-muted)]/60 sm:p-1.5"
      >
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="flex items-center justify-center rounded-xl p-1.5 text-[10px] font-bold transition-all sm:rounded-full sm:px-5 sm:py-2.5 sm:text-sm"
          :class="[
            activeTab === tab.id
              ? 'bg-[var(--app-accent)] text-white shadow-md scale-[1.02]'
              : 'text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)] hover:text-[var(--app-text)] dark:hover:bg-white/5',
          ]"
          @click="setActiveTab(tab.id)"
        >
          <span class="truncate">{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Tab content -->
    <div
      class="mt-4 flex-1 w-full max-w-full overflow-x-hidden rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 shadow-sm sm:mt-6 sm:p-6 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
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

<script setup lang="ts">
import { ref } from 'vue'
import type { LessonDetail } from '@/types/lesson'
import { Icon } from '@iconify/vue'
import LessonTabFlashcards from './LessonTabFlashcards.vue'
import LessonTabShadowing from './LessonTabShadowing.vue'
import LessonGrammarTab from './LessonGrammarTab.vue'
import LessonTabExercises from './LessonTabExercises.vue'
import LessonTabNotes from './LessonTabNotes.vue'

const props = defineProps<{ lesson: LessonDetail }>()

const activeTab = ref<'flashcards' | 'shadowing' | 'grammar' | 'exercises' | 'notes'>('flashcards')

// Tools for the "Studio" section
const tools = [
  {
    id: 'flashcards',
    label: 'Flashcards',
    icon: 'solar:card-2-bold-duotone',
    color: 'text-orange-400',
    bg: 'bg-orange-400/10',
    border: 'border-orange-400/20'
  },
  {
    id: 'shadowing',
    label: 'Audio',
    icon: 'solar:microphone-2-bold-duotone',
    color: 'text-blue-400',
    bg: 'bg-blue-400/10',
    border: 'border-blue-400/20'
  },
  {
    id: 'grammar',
    label: 'Grammar',
    icon: 'solar:book-2-bold-duotone',
    color: 'text-purple-400',
    bg: 'bg-purple-400/10',
    border: 'border-purple-400/20'
  },
  {
    id: 'exercises',
    label: 'Quiz',
    icon: 'solar:clipboard-list-bold-duotone',
    color: 'text-emerald-400',
    bg: 'bg-emerald-400/10',
    border: 'border-emerald-400/20'
  },
] as const

const setActiveTab = (id: typeof activeTab.value) => {
  activeTab.value = id
}
</script>

<template>
  <section
    class="flex h-full w-full flex-col overflow-hidden text-[var(--app-text)]"
  >
    <!-- Studio Header (Tabs) -->
    <div class="shrink-0 pb-2 md:pb-4 flex flex-col gap-4">

      <!-- Desktop: Grid Layout -->
      <div class="hidden md:flex flex-col gap-4">
         <div class="grid grid-cols-4 gap-2">
           <button
            v-for="tool in tools"
            :key="tool.id"
            @click="setActiveTab(tool.id)"
            class="group relative flex items-center gap-2 rounded-xl px-3 py-3 transition-all duration-200 border"
            :class="[
              activeTab === tool.id
                ? 'bg-[var(--app-surface-elevated)] ' + tool.border + ' shadow-sm ring-1 ring-inset ' + tool.border
                : 'bg-[var(--app-surface)] border-transparent hover:bg-[var(--app-surface-elevated)]'
            ]"
          >
             <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors bg-[var(--app-panel-muted)]" :class="{ [tool.bg]: activeTab === tool.id }">
                <Icon :icon="tool.icon" class="h-5 w-5" :class="tool.color" />
             </div>
             <span class="text-xs font-bold text-[var(--app-text)] line-clamp-1">{{ tool.label }}</span>
          </button>
         </div>
         <div class="flex items-center gap-2">
           <h3 class="text-xs font-bold uppercase tracking-widest text-[var(--app-text-muted)] shrink-0 px-1">Sources</h3>
           <div class="h-px bg-[var(--app-border)]/50 flex-1"></div>
           <button
              @click="setActiveTab('notes')"
              class="flex items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-1.5 text-xs font-medium text-[var(--app-text-muted)] transition hover:bg-[var(--app-surface-elevated)] hover:text-[var(--app-text)]"
              :class="{ '!bg-[var(--app-surface-elevated)] !text-[var(--app-text)] !border-[var(--app-accent)]/30 ring-1 ring-[var(--app-accent)]/20': activeTab === 'notes' }"
           >
              <Icon icon="solar:notebook-bold-duotone" class="h-4 w-4" />
              <span>Lesson Notes</span>
           </button>
         </div>
      </div>

      <!-- Mobile: Horizontal Scroll (Compact) -->
      <div class="md:hidden flex overflow-x-auto no-scrollbar gap-2 px-1 -mx-1 snap-x py-1">
         <button
          v-for="tool in tools"
          :key="tool.id"
          @click="setActiveTab(tool.id)"
          class="snap-start shrink-0 flex items-center gap-2 rounded-xl px-3 py-2 border transition-colors"
          :class="[
            activeTab === tool.id
              ? 'bg-[var(--app-surface-elevated)] ' + tool.border + ' ring-1 ring-inset ' + tool.border
              : 'bg-[var(--app-surface)] border-transparent text-[var(--app-text-muted)]'
          ]"
        >
           <Icon :icon="tool.icon" class="h-4 w-4" :class="activeTab === tool.id ? tool.color : 'text-[var(--app-text-muted)]'" />
           <span class="text-xs font-bold" :class="activeTab === tool.id ? 'text-[var(--app-text)]' : 'text-[var(--app-text-muted)]'">{{ tool.label }}</span>
        </button>
         <div class="w-px bg-[var(--app-border)] shrink-0 my-1"></div>
         <button
            @click="setActiveTab('notes')"
            class="snap-start shrink-0 flex items-center gap-2 rounded-xl px-3 py-2 border border-transparent transition-colors"
             :class="activeTab === 'notes' ? 'bg-[var(--app-surface-elevated)] !border-[var(--app-border)]' : 'text-[var(--app-text-muted)]'"
         >
            <Icon icon="solar:notebook-bold-duotone" class="h-4 w-4" />
            <span class="text-xs font-bold">Notes</span>
         </button>
      </div>
    </div>

    <!-- Tab Content Area -->
    <div
      class="flex-1 min-h-0 w-full overflow-hidden rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-sm relative isolate"
    >
       <!-- Glass effect gradient overlay -->
       <div class="absolute inset-0 bg-gradient-to-b from-white/5 to-transparent pointer-events-none mix-blend-overlay" />

       <transition name="fade" mode="out-in">
          <div class="h-full w-full overflow-y-auto p-2 md:p-6 custom-scrollbar scroll-smooth">
            <KeepAlive>
               <component :is="
                  activeTab === 'flashcards' ? LessonTabFlashcards :
                  activeTab === 'shadowing' ? LessonTabShadowing :
                  activeTab === 'grammar' ? LessonGrammarTab :
                  activeTab === 'exercises' ? LessonTabExercises :
                  LessonTabNotes
               "
               :lesson-id="lesson.id"
               :lesson="lesson"
               />
            </KeepAlive>
          </div>
       </transition>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Custom scrollbar for content area */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: var(--app-border);
  border-radius: 20px;
}
</style>

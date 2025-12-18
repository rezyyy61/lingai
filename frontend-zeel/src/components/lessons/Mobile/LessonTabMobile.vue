<template>
  <section class="lg:hidden h-full min-h-0">
    <div class="h-full min-h-0 overflow-hidden flex flex-col" style="background: var(--app-bg)">
      <!-- Sticky header + icon tabs -->
      <div class="sticky top-0 z-30 px-4 pt-4 shrink-0">
        <div
          v-if="!isDetail"
          class="zee-card overflow-hidden relative"
        >
          <div
            class="pointer-events-none absolute -inset-10 opacity-60 blur-3xl"
            :style="{ background: 'radial-gradient(60% 60% at 40% 0%, var(--app-accent-soft) 0%, transparent 70%)' }"
          />

          <!-- Header row -->
          <div class="relative flex items-center justify-between px-4 pt-4">
            <div class="min-w-0">
              <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">
                Lesson practice
              </div>
              <div class="mt-1 truncate text-lg font-semibold tracking-tight text-[color:var(--app-text)]">
                {{ titleText }}
              </div>
            </div>

            <button
              class="grid h-11 w-11 place-items-center rounded-2xl border border-[color:var(--app-border)]
                     bg-[color:var(--app-surface-elevated)] active:scale-[0.99]"
              type="button"
              aria-label="More"
              @click="$emit('more')"
            >
              <Icon icon="solar:menu-dots-bold" class="h-5 w-5 text-[color:var(--app-text)]" />
            </button>
          </div>

          <!-- Icon Tabs -->
          <div class="relative mt-3 px-4 pb-4">
            <div class="grid grid-cols-5 gap-2">
              <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                class="group relative flex h-12 items-center justify-center rounded-2xl border transition active:scale-[0.99]"
                :class="tabBtnClass(t.key)"
                :aria-label="t.label"
                @click="selectTab(t.key)"
              >
                <div
                  v-if="activeTab === t.key"
                  class="pointer-events-none absolute inset-0 rounded-2xl"
                  :style="{ boxShadow: '0 12px 30px -14px rgba(249, 115, 22, 0.85)' }"
                />
                <Icon
                  :icon="t.icon"
                  class="h-6 w-6 transition"
                  :class="activeTab === t.key ? 'scale-[1.02]' : 'opacity-80 group-hover:opacity-100'"
                />
                <div
                  v-if="activeTab === t.key"
                  class="pointer-events-none absolute inset-x-3 -bottom-1 h-1 rounded-full"
                  :style="{ background: 'linear-gradient(90deg, var(--app-accent), var(--app-accent-strong))' }"
                />
              </button>
            </div>
          </div>
        </div>

        <div
          v-else
          class="flex items-center justify-between gap-2 rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)]/95 px-3 py-2"
        >
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-1.5 text-[11px] font-medium text-[color:var(--app-text)] active:scale-95"
            @click="exitDetail"
          >
            <Icon icon="solar:arrow-left-linear" class="h-3.5 w-3.5" />
            <span>Back to tools</span>
          </button>
          <div class="flex items-center gap-2 text-[11px] text-[color:var(--app-text-muted)]">
            <Icon
              :icon="tabs.find(t => t.key === activeTab)?.icon || 'solar:card-outline'"
              class="h-4 w-4"
            />
            <span>{{ tabs.find(t => t.key === activeTab)?.label || 'Summary' }}</span>
          </div>
        </div>

        <div class="h-3"></div>
      </div>

      <!-- Content -->
      <div class="flex-1 min-h-0 overflow-hidden">
        <LessonTabSummary
          v-if="activeTab === 'summary'"
          class="h-full min-h-0"
          :lesson="lessonFull"
        />

        <FlashCardMobile
          v-else-if="activeTab === 'flashcards'"
          class="h-full min-h-0"
          :lesson-id="lessonId"
          :initial-words="initialWords"
          :title="contentTitle"
          @generate="$emit('generate')"
        />

        <LessonTabShadowingMobile
          v-else-if="activeTab === 'shadowing'"
          class="h-full min-h-0"
          :lesson="lessonFull"
        />

        <LessonTabGrammarMobile
          v-else-if="activeTab === 'grammar'"
          class="h-full min-h-0"
          :lesson-id="lessonId"
        />

        <LessonTabExercisesMobile
          v-else-if="activeTab === 'exercises'"
          class="h-full min-h-0"
          :lesson-id="lessonId"
        />

        <LessonTabSummary
          v-else
          class="h-full min-h-0"
          :lesson="lessonFull"
        />
      </div>

    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import type { LessonWordDto } from '@/types/lesson'
import type { LessonDetail } from '@/types/lesson'

import FlashCardMobile from '@/components/lessons/flashcards/FlashCardMobile.vue'
import LessonTabShadowingMobile from '@/components/lessons/Mobile/LessonTabShadowingMobile.vue'
import LessonTabGrammarMobile from '@/components/lessons/Mobile/LessonTabGrammarMobile.vue'
import LessonTabExercisesMobile from '@/components/lessons/Mobile/LessonTabExercisesMobile.vue'
import LessonTabSummary from '@/components/lessons/LessonTabSummary.vue'

type TabKey = 'summary' | 'flashcards' | 'shadowing' | 'grammar' | 'exercises'

const props = defineProps<{
  lesson: LessonDetail
  title?: string
  initialWords?: LessonWordDto[]
  defaultTab?: TabKey
}>()

defineEmits<{
  (e: 'generate'): void
  (e: 'more'): void
}>()

const tabs: Array<{ key: TabKey; label: string; icon: string }> = [
  { key: 'summary', label: 'Summary', icon: 'solar:document-text-outline' },
  { key: 'flashcards', label: 'Flashcards', icon: 'solar:card-outline' },
  { key: 'shadowing', label: 'Shadowing', icon: 'solar:microphone-3-outline' },
  { key: 'grammar', label: 'Grammar', icon: 'solar:book-2-outline' },
  { key: 'exercises', label: 'Exercises', icon: 'solar:checklist-minimalistic-outline' },
]

const activeTab = ref<TabKey>(props.defaultTab ?? 'summary')
const isDetail = ref(false)

const lessonId = computed(() => Number(props.lesson?.id || 0))
const lessonFull = computed(() => props.lesson)

const titleText = computed(() => props.title ?? props.lesson?.title ?? `Lesson #${lessonId.value}`)

const contentTitle = computed(() => {
  const label = tabs.find((t) => t.key === activeTab.value)?.label ?? 'Practice'
  return `${titleText.value} • ${label}`
})

function selectTab(key: TabKey) {
  activeTab.value = key
  isDetail.value = true
}

function exitDetail() {
  activeTab.value = 'summary'
  isDetail.value = false
}

function tabBtnClass(key: TabKey) {
  const isActive = activeTab.value === key
  return isActive
    ? [
      'border-[color:var(--app-border-strong)]',
      'bg-[color:var(--app-accent-soft)]',
      'text-[color:var(--app-accent)]',
    ]
    : [
      'border-[color:var(--app-border)]',
      'bg-[color:var(--app-surface-elevated)]',
      'text-[color:var(--app-text-muted)]',
    ]
}
</script>

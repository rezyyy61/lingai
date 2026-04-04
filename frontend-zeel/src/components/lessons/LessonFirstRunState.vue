<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'

type LessonStartTab = 'text' | 'youtube' | 'ai'

const props = withDefaults(defineProps<{
  hasLessons: boolean
  hasActiveFilters?: boolean
  compact?: boolean
}>(), {
  hasActiveFilters: false,
  compact: false,
})

const emit = defineEmits<{
  (e: 'create', tab: LessonStartTab): void
  (e: 'clear-filters'): void
}>()

const state = computed<'filtered' | 'existing' | 'empty'>(() => {
  if (!props.hasLessons && props.hasActiveFilters) return 'filtered'
  if (props.hasLessons) return 'existing'
  return 'empty'
})

const startCards: Array<{
  tab: LessonStartTab
  title: string
  description: string
  icon: string
  tint: string
}> = [
  {
    tab: 'text',
    title: 'Paste text',
    description: 'Drop in an article, story, or dialogue and turn it into practice in minutes.',
    icon: 'solar:document-text-bold-duotone',
    tint: 'var(--app-accent)',
  },
  {
    tab: 'youtube',
    title: 'Use YouTube',
    description: 'Pull a transcript from a video and convert it into a lesson workspace.',
    icon: 'solar:play-circle-bold-duotone',
    tint: '#22c55e',
  },
  {
    tab: 'ai',
    title: 'Generate with AI',
    description: 'Start from a topic and let AI build a clean practice-ready lesson for you.',
    icon: 'solar:magic-stick-3-bold-duotone',
    tint: '#38bdf8',
  },
]
</script>

<template>
  <section
    class="relative overflow-hidden rounded-[30px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)]"
    :class="compact ? 'p-5 sm:p-6' : 'p-6 sm:p-8 xl:p-10'"
  >
    <div
      class="pointer-events-none absolute inset-x-0 top-0 h-44 opacity-90"
      :style="{ background: 'radial-gradient(55% 55% at 20% 0%, rgba(249,115,22,0.20) 0%, rgba(249,115,22,0.05) 42%, transparent 72%)' }"
    />
    <div
      class="pointer-events-none absolute right-[-8%] top-[-12%] h-64 w-64 rounded-full blur-3xl"
      :style="{ background: 'radial-gradient(circle, rgba(59,130,246,0.12) 0%, rgba(59,130,246,0.03) 45%, transparent 72%)' }"
    />

    <div v-if="state === 'filtered'" class="relative mx-auto flex max-w-3xl flex-col items-center text-center">
      <div class="mb-4 grid h-16 w-16 place-items-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel)]">
        <Icon icon="solar:filter-bold-duotone" class="h-8 w-8 text-[var(--app-accent)]" />
      </div>
      <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[var(--app-text-muted)]">
        No Match
      </p>
      <h2 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">
        No lessons match these filters.
      </h2>
      <p class="mt-3 max-w-xl text-sm leading-7 text-[var(--app-text-muted)] sm:text-base">
        Clear the current filters or create a fresh lesson from text, YouTube, or AI.
      </p>

      <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
        <button class="zee-btn px-5 py-3 text-sm font-semibold" type="button" @click="emit('clear-filters')">
          Clear filters
        </button>
        <button class="zee-card px-5 py-3 text-sm font-semibold" type="button" @click="emit('create', 'text')">
          New text lesson
        </button>
      </div>
    </div>

    <div
      v-else
      class="relative grid gap-5"
      :class="compact ? 'grid-cols-1' : 'grid-cols-1 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)] xl:items-center'"
    >
      <div class="min-w-0">
        <div class="inline-flex items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-panel)] px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-[var(--app-text-muted)]">
          <Icon icon="solar:stars-bold-duotone" class="h-4 w-4 text-[var(--app-accent)]" />
          <span>{{ state === 'existing' ? 'Next Session' : 'First Lesson' }}</span>
        </div>

        <h2 class="mt-5 max-w-2xl text-[clamp(2rem,4vw,3.7rem)] font-semibold leading-[1.02] tracking-[-0.04em]">
          <template v-if="state === 'existing'">
            Pick a lesson or spin up a fresh practice session.
          </template>
          <template v-else>
            Turn any text into a speaking studio.
          </template>
        </h2>

        <p class="mt-4 max-w-2xl text-sm leading-7 text-[var(--app-text-muted)] sm:text-base">
          <template v-if="state === 'existing'">
            Your source, shadowing, grammar, and exercises all open here. Choose one from the left rail or create a new lesson in a cleaner format.
          </template>
          <template v-else>
            Start with a short story, a YouTube transcript, or an AI-generated topic. We’ll turn it into shadowing, flashcards, grammar, and speaking practice in one place.
          </template>
        </p>

        <div class="mt-7 flex flex-wrap items-center gap-3">
          <button class="zee-btn px-5 py-3 text-sm font-semibold" type="button" @click="emit('create', 'text')">
            Start with text
          </button>
          <button class="zee-card px-5 py-3 text-sm font-semibold" type="button" @click="emit('create', 'youtube')">
            Import YouTube
          </button>
          <button class="zee-card px-5 py-3 text-sm font-semibold" type="button" @click="emit('create', 'ai')">
            Generate with AI
          </button>
        </div>

        <div class="mt-7 flex flex-wrap gap-2.5">
          <div class="rounded-full border border-[var(--app-border)] bg-[var(--app-panel)] px-3 py-1.5 text-xs text-[var(--app-text-muted)]">
            1. Add a source
          </div>
          <div class="rounded-full border border-[var(--app-border)] bg-[var(--app-panel)] px-3 py-1.5 text-xs text-[var(--app-text-muted)]">
            2. Generate practice
          </div>
          <div class="rounded-full border border-[var(--app-border)] bg-[var(--app-panel)] px-3 py-1.5 text-xs text-[var(--app-text-muted)]">
            3. Shadow and review
          </div>
        </div>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
        <button
          v-for="card in startCards"
          :key="card.tab"
          type="button"
          class="group relative overflow-hidden rounded-[24px] border border-[var(--app-border)] bg-[var(--app-panel)] p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:border-[var(--app-border-strong)] hover:shadow-[0_18px_50px_rgba(0,0,0,0.18)]"
          @click="emit('create', card.tab)"
        >
          <div
            class="pointer-events-none absolute inset-x-0 top-0 h-16 opacity-80"
            :style="{ background: `linear-gradient(180deg, color-mix(in srgb, ${card.tint} 18%, transparent) 0%, transparent 100%)` }"
          />
          <div class="relative">
            <div
              class="grid h-11 w-11 place-items-center rounded-2xl border border-white/5"
              :style="{ background: `color-mix(in srgb, ${card.tint} 16%, var(--app-surface-elevated))`, color: card.tint }"
            >
              <Icon :icon="card.icon" class="h-6 w-6" />
            </div>
            <div class="mt-4 text-base font-semibold tracking-tight">{{ card.title }}</div>
            <p class="mt-2 text-sm leading-6 text-[var(--app-text-muted)]">{{ card.description }}</p>
          </div>
        </button>
      </div>
    </div>
  </section>
</template>

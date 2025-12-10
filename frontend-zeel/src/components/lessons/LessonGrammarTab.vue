<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useLessonGrammar } from '@/composables/useLessonGrammar'
import { generateLessonGrammar } from '@/api/lessonGrammar'

const props = defineProps<{
  lessonId: number
}>()

const {
  grammarPoints,
  activePoint,
  activeIndex,
  total,
  isLoading,
  isError,
  isEmpty,
  isReady,
  hasPrev,
  hasNext,
  setActive,
  goPrev,
  goNext,
  reload,
} = useLessonGrammar(props.lessonId)

const toastMessage = ref('')
let toastTimeout: number | null = null

const isGenerationPending = ref(false)
const pendingBaselineSignature = ref<string | null>(null)
let pollingInterval: number | null = null
const isFocusMode = ref(false)

const pointsSignature = computed(() =>
  grammarPoints.value.map((p) => p.id).join('-'),
)

const generationStorageKey = computed(
  () => `zeel:grammar-generating:${props.lessonId}`,
)

const loadGenerationState = () => {
  if (typeof window === 'undefined') return false
  try {
    return window.localStorage.getItem(generationStorageKey.value) === '1'
  } catch {
    return false
  }
}

const persistGenerationState = (pending: boolean) => {
  if (typeof window === 'undefined') return
  try {
    if (pending) {
      window.localStorage.setItem(generationStorageKey.value, '1')
    } else {
      window.localStorage.removeItem(generationStorageKey.value)
    }
  } catch {
    // ignore storage errors
  }
}

const emptyStateVisible = computed(
  () => isEmpty.value && !isGenerationPending.value,
)

const progressLabel = computed(() =>
  total.value === 0 ? '0 / 0' : `${activeIndex.value + 1} / ${total.value}`,
)

const progressPercent = computed(() =>
  total.value === 0 ? 0 : Math.round(((activeIndex.value + 1) / total.value) * 100),
)

const parsedMeta = computed<{
  importance?: string
  tags?: string[]
}>(() => {
  const raw = activePoint.value?.meta
  if (!raw) return {}
  if (typeof raw === 'object') return raw as any
  try {
    return JSON.parse(raw as string)
  } catch {
    return {}
  }
})

const importance = computed(() => parsedMeta.value.importance || null)
const tags = computed<string[]>(() => parsedMeta.value.tags || [])

type UiExample = { sentence: string; translation?: string }

const parsedExamples = computed<UiExample[]>(() => {
  const raw = (activePoint.value as any)?.examples
  if (!raw) return []

  if (Array.isArray(raw)) {
    return raw.map((e: any) => ({
      sentence: e.sentence || e.text || '',
      translation: e.translation || '',
    }))
  }

  if (typeof raw === 'string') {
    try {
      const arr = JSON.parse(raw)
      if (!Array.isArray(arr)) return []
      return arr.map((e: any) => ({
        sentence: e.sentence || e.text || '',
        translation: e.translation || '',
      }))
    } catch {
      return []
    }
  }

  return []
})

const keyExample = computed<UiExample | null>(() => {
  return parsedExamples.value[0] ?? null
})

const patternLines = computed(() => {
  const pattern = (activePoint.value as any)?.pattern || ''
  return pattern
    .split('\n')
    .map((l: string) => l.trim())
    .filter((l: string) => l.length > 0)
})

const analysisDirection = computed<'rtl' | 'ltr'>(() => {
  const base =
    (activePoint.value?.description ?? '') +
    (activePoint.value?.explanation ?? '') +
    (parsedExamples.value[0]?.translation || '')
  const rtlPattern = /[\u0590-\u08FF]/
  return rtlPattern.test(base) ? 'rtl' : 'ltr'
})

const pushToast = (message: string) => {
  toastMessage.value = message
  if (toastTimeout) {
    clearTimeout(toastTimeout)
  }
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 4000)
}

const startPolling = () => {
  if (pollingInterval !== null) return
  pollingInterval = window.setInterval(() => {
    reload()
  }, 6000)
}

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

const handleGenerateGrammar = async () => {
  if (isGenerationPending.value) return
  try {
    isGenerationPending.value = true
    persistGenerationState(true)
    pendingBaselineSignature.value = pointsSignature.value || null
    await generateLessonGrammar(props.lessonId, {})
    startPolling()
    pushToast('Grammar notes generation queued')
  } catch {
    isGenerationPending.value = false
    persistGenerationState(false)
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Failed to queue grammar generation')
  }
}

watch(isGenerationPending, (pending) => {
  if (pending) startPolling()
  else stopPolling()
})

watch(pointsSignature, (signature) => {
  if (!isGenerationPending.value) return
  if (signature && signature !== pendingBaselineSignature.value) {
    isGenerationPending.value = false
    persistGenerationState(false)
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Grammar notes are ready')
  }
})

onMounted(() => {
  const pending = loadGenerationState()
  if (pending) {
    isGenerationPending.value = true
    startPolling()
  }
})

onBeforeUnmount(() => {
  if (toastTimeout) clearTimeout(toastTimeout)
  stopPolling()
})
</script>

<template>
  <section
    class="flex h-full w-full max-w-full flex-col overflow-x-hidden text-[var(--app-text)] dark:text-white"
  >
    <!-- Header -->
    <div
      v-if="!isFocusMode"
      class="flex w-full flex-wrap items-center justify-between gap-3"
    >
      <div class="space-y-1">
        <p
          class="text-[10px] font-semibold uppercase tracking-[0.25em] text-[var(--app-text-muted)] dark:text-white/60"
        >
          Grammar
        </p>
        <p class="text-sm text-[var(--app-text-muted)] dark:text-white/60">
          A structured walkthrough of the key grammar in this lesson.
        </p>
      </div>
      <div
        class="flex w-full flex-wrap items-center gap-3 text-xs text-[var(--app-text-muted)] sm:w-auto sm:justify-end dark:text-white/60"
      >
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Point: {{ progressLabel }}
        </span>
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Total: {{ total }}
        </span>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15"
          :disabled="isGenerationPending"
          @click="handleGenerateGrammar"
        >
          <Icon
            icon="solar:magic-stick-3-bold-duotone"
            class="h-4 w-4"
          />
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15"
          :disabled="!isReady || !activePoint"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:fullscreen-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Progress bar -->
    <div
      v-if="!isFocusMode"
      class="mt-3 h-1.5 w-full max-w-full overflow-hidden rounded-full bg-[var(--app-panel-muted)]/80 dark:bg-white/10"
    >
      <div
        class="h-full rounded-full bg-[var(--app-accent-secondary)] transition-all duration-300"
        :style="{ width: progressPercent + '%' }"
      />
    </div>

    <!-- Toast + states -->
    <div
      v-if="!isFocusMode"
      class="mt-3 w-full max-w-full space-y-2 text-xs"
    >
      <div
        v-if="toastMessage"
        class="w-full rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-center text-[var(--app-text)] dark:border-white/10 dark:bg-white/5 dark:text-white/80"
      >
        {{ toastMessage }}
      </div>

      <div
        v-if="isGenerationPending"
        class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/70"
      >
        Generating grammar notes… We’ll load them automatically when they are ready.
      </div>

      <div
        v-if="isError"
        class="flex w-full items-center justify-between gap-3 rounded-xl border border-[var(--app-accent-strong)] bg-[var(--app-panel-muted)] px-4 py-2 text-[var(--app-accent-strong)] dark:border-[var(--app-accent-strong)] dark:bg-white/5"
      >
        <p>Could not load grammar notes.</p>
        <button
          class="rounded-full border border-[var(--app-accent-strong)] px-3 py-1 text-[11px] font-medium"
          @click="reload"
        >
          Try again
        </button>
      </div>

      <div
        v-if="emptyStateVisible"
        class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-3 text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/70"
      >
        <p class="text-sm text-[var(--app-text)] dark:text-white">
          No grammar notes yet.
        </p>
        <p class="mt-1">
          Generate clear explanations, patterns, and examples for this lesson’s grammar.
        </p>
        <button
          class="mt-2 inline-flex items-center rounded-full bg-[var(--app-accent)] px-4 py-2 text-[11px] font-semibold text-white transition hover:bg-[var(--app-accent-strong)]"
          @click="handleGenerateGrammar"
        >
          Generate grammar notes
        </button>
      </div>
    </div>

    <!-- Tabs for grammar points -->
    <div
      v-if="isReady && grammarPoints.length && !isFocusMode"
      class="mt-4 flex w-full max-w-full flex-wrap items-center gap-2 border-b border-[var(--app-border)] pb-2"
    >
      <div class="custom-scrollbar flex min-w-0 flex-1 gap-2 overflow-x-auto pb-1">
        <button
          v-for="(point, index) in grammarPoints"
          :key="point.id"
          type="button"
          class="inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs transition"
          :class="
            index === activeIndex
              ? 'bg-[var(--app-accent-secondary)]/15 text-[var(--app-text)] dark:bg-[var(--app-accent-secondary)]/20 dark:text-white'
              : 'text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)] dark:text-white/70 dark:hover:bg-white/5'
          "
          @click="setActive(index)"
        >
          <span
            class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-black/5 text-[10px] font-semibold dark:bg-white/10"
          >
            {{ index + 1 }}
          </span>
          <div class="flex min-w-0 flex-col">
            <span class="truncate text-[11px] font-medium">
              {{ point.title }}
            </span>
              <span
                v-if="point.level"
                class="mt-[1px] text-[9px] uppercase tracking-wide text-[var(--app-text-muted)] dark:text-white/50"
              >
              Level {{ point.level }}
            </span>
          </div>
        </button>
      </div>

      <div class="mt-1 flex items-center gap-2 text-[10px] text-[var(--app-text-muted)] dark:text-white/60 sm:mt-0">
        <span class="hidden whitespace-nowrap md:inline">
          Point {{ activeIndex + 1 }} of {{ total }}
        </span>
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="rounded-full px-2 py-0.5 text-[11px]"
            :class="hasPrev ? 'text-[var(--app-text)] dark:text-white' : 'text-[var(--app-text-muted)] dark:text-white/40'"
            :disabled="!hasPrev"
            @click="goPrev"
          >
            ←
          </button>
          <button
            type="button"
            class="rounded-full px-2 py-0.5 text-[11px]"
            :class="hasNext ? 'text-[var(--app-text)] dark:text-white' : 'text-[var(--app-text-muted)] dark:text-white/40'"
            :disabled="!hasNext"
            @click="goNext"
          >
            →
          </button>
        </div>
      </div>
    </div>

    <!-- Main content: card with its own scroll -->
    <div class="mt-4 flex min-h-0 w-full max-w-full flex-1">
      <!-- Explicit generation loading state -->
      <div
        v-if="isGenerationPending && !isReady"
        class="flex w-full flex-col items-center justify-center gap-4 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-6 text-center text-sm text-[var(--app-text-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white/70"
      >
        <div class="flex items-center justify-center gap-3 text-[var(--app-text)] dark:text-white">
          <svg
            class="h-4 w-4 animate-spin text-[var(--app-accent)]"
            viewBox="0 0 24 24"
          >
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="3"
              fill="none"
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 0 1 8-8v3.5a4.5 4.5 0 0 0-4.5 4.5H4Z"
            />
          </svg>
          <span>Generating grammar notes for this lesson…</span>
        </div>
        <p class="text-[11px] text-[var(--app-text-muted)] dark:text-white/60">
          This may take a short moment. We’ll load the teacher’s grammar view automatically when it’s ready.
        </p>
      </div>

      <!-- Loading skeleton while fetching existing notes -->
      <div
        v-else-if="isLoading && !isReady && !isEmpty"
        class="flex w-full flex-col gap-3 text-[var(--app-text-muted)] dark:text-white/60"
      >
        <div class="h-4 w-40 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
        <div class="h-3 w-full animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
        <div class="h-3 w-11/12 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
        <div class="h-3 w-10/12 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
      </div>

      <!-- Active point card -->
      <div
        v-else-if="isReady && activePoint"
        class="mx-auto flex h-full w-full max-w-sm flex-col overflow-y-auto rounded-[26px] border border-[var(--app-border)] bg-[var(--app-panel)] px-4 py-4 text-sm leading-relaxed text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] sm:max-w-md sm:px-5 sm:py-5 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
        :dir="analysisDirection"
        :class="analysisDirection === 'rtl' ? 'text-right' : 'text-left'"
      >
        <!-- Focus mode back button -->
        <button
          v-if="isFocusMode"
          type="button"
          class="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]/80 text-[var(--app-text)] shadow-sm dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
          @click="isFocusMode = false"
        >
          <Icon
            icon="solar:arrow-left-linear"
            class="h-4 w-4"
          />
        </button>
        <header class="pb-3">
          <p
            class="text-[10px] font-semibold uppercase tracking-[0.28em] text-[var(--app-text-muted)] dark:text-white/60"
          >
            Grammar lesson
          </p>
          <h2 class="mt-2 text-base font-semibold leading-snug sm:text-lg">
            {{ activePoint.title }}
          </h2>

          <div
            class="mt-2 flex flex-wrap items-center gap-2 text-[10px] text-[var(--app-text-muted)] dark:text-white/60"
          >
            <span
              v-if="activePoint.level"
              class="rounded-full bg-black/5 px-2 py-[3px] text-[10px] font-medium dark:bg-white/10"
            >
              Level {{ activePoint.level }}
            </span>
            <span
              v-if="importance"
              class="rounded-full bg-[var(--app-accent-secondary)]/15 px-2 py-[3px] text-[10px] font-medium text-[var(--app-accent-secondary)]"
            >
              Importance: {{ importance }}
            </span>
            <div v-if="tags.length" class="flex flex-wrap gap-1">
              <span
                v-for="tag in tags"
                :key="tag"
                class="rounded-full bg.black/5 px-2 py-[2px] text-[9px] dark:bg-white/10"
              >
                #{{ tag }}
              </span>
            </div>
          </div>

          <div
            v-if="keyExample"
            class="mt-3 rounded-lg bg-[var(--app-panel-muted)]/70 px-3 py-2 text-xs dark:bg-white/5"
          >
            <p class="text-[13px] font-medium leading-relaxed">
              {{ keyExample.sentence }}
            </p>
            <p
              v-if="keyExample.translation"
              class="mt-1 text-[11px] text-[var(--app-text-muted)] dark:text-white/70"
            >
              {{ keyExample.translation }}
            </p>
          </div>
        </header>

        <div class="mt-2 flex-1 space-y-4 text-[13px] leading-relaxed">
          <div>
            <p
              v-if="activePoint.explanation || activePoint.description"
              class="whitespace-pre-wrap"
            >
              {{ activePoint.explanation || activePoint.description }}
            </p>
            <p
              v-else
              class="text-[12px] text-[var(--app-text-muted)] dark:text-white/60"
            >
              No explanation available for this grammar point yet.
            </p>

            <p
              v-if="activePoint.tips"
              class="mt-2 whitespace-pre-wrap text-[12px] text-[var(--app-text-muted)] dark:text-white/65"
            >
              {{ activePoint.tips }}
            </p>
          </div>

          <div v-if="patternLines.length">
            <div
              v-for="(line, idx) in patternLines"
              :key="idx"
              class="break-words font-mono text-[12px]"
            >
              {{ line }}
            </div>
          </div>

          <div v-if="parsedExamples.length">
            <div
              v-for="(ex, index) in parsedExamples"
              :key="index"
              class="leading-relaxed"
            >
              <p class="font-medium">
                • {{ ex.sentence }}
              </p>
              <p
                v-if="ex.translation"
                class="text-[12px] text-[var(--app-text-muted)] dark:text-white/70"
              >
                {{ ex.translation }}
              </p>
            </div>
          </div>

          <div
            v-if="activePoint.practiceItems && activePoint.practiceItems.length"
            class="space-y-2"
          >
            <div
              v-for="(item, index) in activePoint.practiceItems"
              :key="index"
            >
              <p class="font-medium">
                {{ index + 1 }}. {{ item.prompt }}
              </p>
              <p
                v-if="item.answer"
                class="mt-0.5 text-[12px]"
              >
                Answer:
                <span class="font-semibold">
                  {{ item.answer }}
                </span>
              </p>
              <p
                v-if="item.explanation"
                class="mt-0.5 text-[12px] text-[var(--app-text-muted)] dark:text-white/65"
              >
                {{ item.explanation }}
              </p>
            </div>
          </div>
        </div>

        <div
          v-if="!isFocusMode"
          class="mt-4 flex items-center justify-between text-[11px] text-[var(--app-text-muted)] dark:text-white/60"
        >
          <span>Point {{ activeIndex + 1 }} of {{ total }}</span>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="rounded-full border border-[var(--app-border)] px-3 py-1 disabled:opacity-40 dark:border-white/20"
              :disabled="!hasPrev"
              @click="goPrev"
            >
              ← Prev
            </button>
            <button
              type="button"
              class="rounded-full border border-[var(--app-border)] px-3 py-1 disabled:opacity-40 dark:border-white/20"
              :disabled="!hasNext"
              @click="goNext"
            >
              Next →
            </button>
          </div>
        </div>
      </div>

      <!-- Fallback -->
      <div
        v-else-if="isReady && !activePoint"
        class="flex h-full w-full items-center justify-center text-sm text-[var(--app-text-muted)] dark:text-white/70"
      >
        No active grammar point.
      </div>
    </div>
  </section>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  height: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  border-radius: 999px;
}
</style>

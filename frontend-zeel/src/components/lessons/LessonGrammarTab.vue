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
    class="flex h-full w-full max-w-full flex-col overflow-x-hidden text-[var(--app-text)]"
  >
    <!-- Header -->
    <div
      v-if="!isFocusMode"
      class="flex w-full flex-wrap items-center justify-between gap-3 px-1"
    >
      <div class="space-y-0.5">
        <p
          class="text-xs font-semibold font-display tracking-wider uppercase text-[var(--app-accent)]"
        >
          Grammar
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)] hidden sm:block">
          A structured walkthrough
        </p>
      </div>
      <div
        class="flex items-center gap-1.5 text-xs text-[var(--app-text-muted)]"
      >
        <div class="flex items-center rounded-full bg-[var(--app-surface-elevated)] border border-[var(--app-border)] p-0.5">
          <button
            type="button"
            class="h-7 w-7 inline-flex items-center justify-center rounded-full text-[var(--app-text)] hover:bg-[var(--app-surface)] disabled:opacity-30 disabled:hover:bg-transparent"
            :disabled="!hasPrev"
            @click="goPrev"
          >
            <Icon icon="solar:arrow-left-linear" class="h-4 w-4" />
          </button>
          <span class="px-2 font-medium min-w-[3rem] text-center">
             {{ activeIndex + 1 }} / {{ total }}
          </span>
          <button
            type="button"
            class="h-7 w-7 inline-flex items-center justify-center rounded-full text-[var(--app-text)] hover:bg-[var(--app-surface)] disabled:opacity-30 disabled:hover:bg-transparent"
            :disabled="!hasNext"
            @click="goNext"
          >
            <Icon icon="solar:arrow-right-linear" class="h-4 w-4" />
          </button>
        </div>

        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="isGenerationPending"
          @click="handleGenerateGrammar"
        >
          <span class="text-[10px] font-bold">AI</span>
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="!isReady || !activePoint"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:maximize-square-minimalistic-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Progress bar -->
    <div
      v-if="!isFocusMode"
      class="mt-4 h-1.5 w-full max-w-full overflow-hidden rounded-full bg-[var(--app-panel-muted)]"
    >
      <div
        class="h-full rounded-full bg-[var(--app-accent)] transition-all duration-300"
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
        class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-center text-[var(--app-text)] shadow-sm"
      >
        {{ toastMessage }}
      </div>

      <div
        v-if="isGenerationPending"
        class="flex items-center gap-3 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-[var(--app-text-muted)]"
      >
        <Icon icon="svg-spinners:90-ring-with-bg" class="h-4 w-4 text-[var(--app-accent)]" />
        Generating grammar notes...
      </div>

      <div
        v-if="isError"
        class="flex w-full items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-red-600 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-400"
      >
        <p>Could not load grammar notes.</p>
        <button
          class="rounded-full border border-current px-3 py-1 text-[11px] font-medium"
          @click="reload"
        >
          Try again
        </button>
      </div>

      <div
        v-if="emptyStateVisible"
        class="w-full rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-8 text-center"
      >
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--app-panel-muted)] text-[var(--app-accent)]">
          <Icon icon="solar:magic-stick-3-bold-duotone" class="h-6 w-6" />
        </div>
        <p class="text-sm font-medium text-[var(--app-text)]">
          No grammar notes
        </p>
        <p class="mt-1 text-xs text-[var(--app-text-muted)]">
          Generate clear explanations for this lesson.
        </p>
        <button
          class="mt-4 inline-flex items-center rounded-full bg-[var(--app-accent)] px-5 py-2 text-xs font-semibold text-white transition active:scale-95 shadow-md shadow-[var(--app-accent)]/20"
          @click="handleGenerateGrammar"
        >
          Generate notes
        </button>
      </div>
    </div>

    <!-- Tabs for grammar points -->
    <div
      v-if="isReady && grammarPoints.length && !isFocusMode"
      class="mt-4 w-full border-b border-[var(--app-border)] pb-1"
    >
      <div class="custom-scrollbar flex w-full gap-2 overflow-x-auto pb-3 px-1 snap-x">
        <button
          v-for="(point, index) in grammarPoints"
          :key="point.id"
          type="button"
          class="group relative inline-flex shrink-0 snap-start flex-col items-start gap-0.5 rounded-xl border px-3 py-2 text-left transition-all min-w-[140px] max-w-[200px]"
          :class="
            index === activeIndex
              ? 'bg-[var(--app-surface-elevated)] border-[var(--app-accent-secondary)] shadow-sm'
              : 'bg-transparent border-transparent hover:bg-[var(--app-surface-elevated)]'
          "
          @click="setActive(index)"
        >
          <span
            class="text-[10px] font-bold uppercase tracking-wider"
             :class="index === activeIndex ? 'text-[var(--app-accent)]' : 'text-[var(--app-text-muted)]'"
          >
            Point {{ index + 1 }}
          </span>
          <span class="truncate w-full text-xs font-medium text-[var(--app-text)]">
            {{ point.title }}
          </span>
        </button>
      </div>
    </div>

    <!-- Main content: card with its own scroll -->
    <div class="mt-4 flex min-h-0 w-full max-w-full flex-1 flex-col">
      <!-- Loading skeleton -->
      <div
        v-if="isLoading && !isReady && !isEmpty"
        class="flex w-full flex-col gap-4 p-4 rounded-[24px] bg-[var(--app-surface-elevated)] border border-[var(--app-border)]"
      >
        <div class="h-6 w-1/3 animate-pulse rounded-md bg-[var(--app-panel-muted)]" />
        <div class="space-y-2">
          <div class="h-4 w-full animate-pulse rounded-md bg-[var(--app-panel-muted)]" />
          <div class="h-4 w-full animate-pulse rounded-md bg-[var(--app-panel-muted)]" />
          <div class="h-4 w-3/4 animate-pulse rounded-md bg-[var(--app-panel-muted)]" />
        </div>
      </div>

      <!-- Active point card -->
      <div
        v-else-if="isReady && activePoint"
        class="relative flex flex-1 flex-col overflow-hidden rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-sm dark:bg-[#1e1e20]"
      >
         <!-- Focus mode back button -->
        <div v-if="isFocusMode" class="absolute top-4 left-4 z-10">
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm backdrop-blur-md"
              @click="isFocusMode = false"
            >
              <Icon
                icon="solar:minimize-square-minimalistic-bold-duotone"
                class="h-4 w-4"
              />
            </button>
        </div>

        <div 
          class="flex-1 overflow-y-auto px-5 py-6 sm:px-8 sm:py-8"
          :dir="analysisDirection"
          :class="analysisDirection === 'rtl' ? 'text-right' : 'text-left'"
        >
          <header class="pb-6 border-b border-[var(--app-border)] mb-6">
            <div class="flex flex-wrap items-center gap-2 mb-3">
              <span
                class="rounded-md bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)] ring-1 ring-inset ring-[var(--app-border)]"
              >
                Grammar Point {{ activeIndex + 1 }}
              </span>
              <span
                 v-if="activePoint.level"
                 class="rounded-md bg-[var(--app-surface)] px-2 py-1 text-[10px] font-medium text-[var(--app-text-muted)]"
              >
                 Level {{ activePoint.level }}
              </span>
            </div>
            
            <h2 class="font-display text-2xl font-semibold leading-tight text-[var(--app-text)] sm:text-3xl">
              {{ activePoint.title }}
            </h2>

            <!-- Tags -->
            <div v-if="tags.length" class="mt-3 flex flex-wrap gap-1.5">
              <span
                v-for="tag in tags"
                :key="tag"
                class="inline-flex items-center rounded-full bg-[var(--app-surface)] px-2.5 py-0.5 text-[10px] text-[var(--app-text-muted)]"
              >
                #{{ tag }}
              </span>
            </div>

            <div
              v-if="keyExample"
              class="mt-5 rounded-xl bg-[var(--app-accent)]/5 px-4 py-3 border border-[var(--app-accent)]/10"
            >
              <p class="text-[15px] font-medium leading-relaxed text-[var(--app-text)]">
                {{ keyExample.sentence }}
              </p>
              <p
                v-if="keyExample.translation"
                class="mt-1 text-xs text-[var(--app-text-muted)]"
              >
                {{ keyExample.translation }}
              </p>
            </div>
          </header>

          <div class="space-y-6 text-sm leading-relaxed text-[var(--app-text)] sm:text-base">
            <div>
              <p
                v-if="activePoint.explanation || activePoint.description"
                class="whitespace-pre-wrap"
              >
                {{ activePoint.explanation || activePoint.description }}
              </p>
              <p
                v-else
                class="italic text-[var(--app-text-muted)]"
              >
                No explanation available.
              </p>

              <div v-if="activePoint.tips" class="mt-4 rounded-lg bg-[var(--app-surface)] p-3 text-xs text-[var(--app-text-muted)]">
                 <strong class="font-bold text-[var(--app-text)] block mb-1">Tip</strong>
                 {{ activePoint.tips }}
              </div>
            </div>

            <div v-if="patternLines.length" class="space-y-2">
               <p class="text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">Pattern Structure</p>
               <div class="rounded-xl bg-[#1e1e20] p-4 text-white font-mono text-xs overflow-x-auto">
                  <div
                    v-for="(line, idx) in patternLines"
                    :key="idx"
                    class="break-words whitespace-pre"
                  >
                    {{ line }}
                  </div>
               </div>
            </div>

            <div v-if="parsedExamples.length" class="space-y-3">
               <p class="text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">Examples</p>
               <div class="space-y-4">
                  <div
                    v-for="(ex, index) in parsedExamples"
                    :key="index"
                    class="pl-3 border-l-2 border-[var(--app-border)]"
                  >
                    <p class="font-medium text-[var(--app-text)]">
                      {{ ex.sentence }}
                    </p>
                    <p
                      v-if="ex.translation"
                      class="text-xs text-[var(--app-text-muted)] mt-0.5"
                    >
                      {{ ex.translation }}
                    </p>
                  </div>
               </div>
            </div>

            <div
              v-if="activePoint.practiceItems && activePoint.practiceItems.length"
              class="space-y-3 pt-4 border-t border-[var(--app-border)]"
            >
               <p class="text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">Practice</p>
              <div
                v-for="(item, index) in activePoint.practiceItems"
                :key="index"
                class="rounded-xl bg-[var(--app-surface)] p-4"
              >
                <p class="font-medium">
                  <span class="text-[var(--app-accent)] mr-1">{{ index + 1 }}.</span> {{ item.prompt }}
                </p>
                <div v-if="item.answer" class="mt-2 text-xs">
                  <span class="text-[var(--app-text-muted)]">Answer:</span> <span class="font-semibold">{{ item.answer }}</span>
                </div>
                <p
                  v-if="item.explanation"
                  class="mt-1 text-xs text-[var(--app-text-muted)] italic"
                >
                  {{ item.explanation }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Fallback -->
      <div
        v-else-if="isReady && !activePoint"
        class="flex h-full w-full items-center justify-center text-sm text-[var(--app-text-muted)]"
      >
        Select a grammar point to view details.
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

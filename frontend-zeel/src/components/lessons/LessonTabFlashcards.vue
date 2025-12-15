<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import Flashcard from './flashcards/Flashcard.vue'
import GenerateFlashcardsModal from './GenerateFlashcardsModal.vue'
import { useLessonFlashcards } from '@/composables/useLessonFlashcards'

const props = defineProps<{
  lessonId: number
}>()

const {
  currentCard,
  total,
  reviewed,
  remaining,
  isLoading,
  isError,
  isEmpty,
  isReady,
  hasPrev,
  hasNext,
  goNext,
  goPrev,
  reload,
} = useLessonFlashcards(props.lessonId)

const progressPercent = computed(() =>
  total.value === 0 ? 0 : Math.round((reviewed.value / total.value) * 100),
)

const showGenerateModal = ref(false)
const isGenerationPending = ref(false)
const isGenerationTimedOut = ref(false)
const toastMessage = ref('')
let toastTimeout: number | null = null
let pollingInterval: number | null = null
let generationTimeout: number | null = null
const isFocusMode = ref(false)

const generationStorageKey = computed(
  () => `zeel:flashcards-generating:${props.lessonId}`,
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

const openGenerateModal = () => {
  showGenerateModal.value = true
}

const closeGenerateModal = () => {
  showGenerateModal.value = false
}

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

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
  if (generationTimeout !== null) {
    clearTimeout(generationTimeout)
    generationTimeout = null
  }
}

const handleTimeout = () => {
  stopPolling()
  isGenerationPending.value = false
  isGenerationTimedOut.value = true
  persistGenerationState(false)
}

const startPolling = () => {
  if (pollingInterval !== null) return
  
  isGenerationTimedOut.value = false // Reset timeout state
  
  // Set safety timeout (40 seconds)
  if (generationTimeout === null) {
      generationTimeout = window.setTimeout(handleTimeout, 40000)
  }

  pollingInterval = window.setInterval(() => {
    reload()
  }, 4000) // Slightly faster polling
}

const manualReload = () => {
    isGenerationTimedOut.value = false
    isGenerationPending.value = true
    startPolling()
    reload()
}

const handleGenerationQueued = () => {
  showGenerateModal.value = false
  if (isEmpty.value) {
    isGenerationPending.value = true
    startPolling()
  }
  pushToast('Vocabulary extraction started')
}

watch(isReady, (ready) => {
  if (ready) {
    if (isGenerationPending.value) {
      pushToast('Vocabulary is ready')
    }
    isGenerationPending.value = false
    isGenerationTimedOut.value = false
    persistGenerationState(false)
    stopPolling()
  }
})

watch(isGenerationPending, (pending) => {
  if (pending) {
    startPolling()
  } else {
    stopPolling()
  }
  persistGenerationState(pending)
})

onMounted(() => {
  const pending = loadGenerationState()
  if (pending) {
    isGenerationPending.value = true
    startPolling()
  }
})

onBeforeUnmount(() => {
  if (toastTimeout) {
    clearTimeout(toastTimeout)
  }
  stopPolling()
})

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)
</script>

<template>
  <section class="flex h-full flex-col text-[var(--app-text)]">
    <!-- Minimal header (hidden in focus mode) -->
    <div
      v-if="!isFocusMode"
      class="flex items-center justify-between gap-3 px-1 pb-2 shrink-0"
    >
      <div class="space-y-0.5">
        <p class="text-xs font-semibold font-display tracking-wide uppercase text-[var(--app-accent)]">
          Flashcards
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)] hidden sm:block">
          Key vocabulary from this lesson
        </p>
      </div>
      <div class="flex items-center gap-2 text-[11px] text-[var(--app-text-muted)]">
        <span
          v-if="total"
          class="rounded-full bg-[var(--app-surface-elevated)] border border-[var(--app-border)] px-2.5 py-1 font-medium"
        >
          {{ reviewed }} / {{ total }}
        </span>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          <span class="text-[10px] font-bold">AI</span>
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="!isReady || !currentCard"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:maximize-square-minimalistic-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Toast (hidden in focus mode) -->
    <div
      v-if="toastMessage && !isFocusMode"
      class="mt-4 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-center text-xs font-medium text-[var(--app-text)] shadow-sm"
    >
      {{ toastMessage }}
    </div>

    <div class="flex flex-1 flex-col items-center justify-center gap-3 sm:gap-8 relative overflow-hidden">
      <Transition name="fade-scale" mode="out-in">
        <div v-if="isError" class="flex flex-col items-center gap-3 text-sm text-red-500" key="error">
          <p>Something went wrong loading flashcards.</p>
          <button
            class="rounded-full border border-current px-5 py-2 text-xs font-semibold"
            @click="reload"
          >
            Try again
          </button>
        </div>

        <div v-else-if="isGenerationPending" class="w-full" key="generating">
           <div
             class="flex flex-col items-center gap-4 text-sm text-[var(--app-text-muted)]"
           >
             <span class="flex items-center gap-3 text-[var(--app-text)]">
               <Icon icon="svg-spinners:90-ring-with-bg" class="h-5 w-5 text-[var(--app-accent)]" />
               Extracting key vocabulary…
             </span>
             <p class="text-xs opacity-70">This usually takes about 20 seconds.</p>
           </div>
        </div>
        
        <div v-else-if="isGenerationTimedOut" class="w-full" key="timeout">
            <div class="flex flex-col items-center justify-center gap-4 py-8 text-center bg-[var(--app-surface-elevated)]/40 rounded-3xl border border-[var(--app-border)]/50 border-dashed">
                <div class="h-10 w-10 rounded-full bg-[var(--app-surface-elevated)] flex items-center justify-center text-[var(--app-text-muted)]">
                    <Icon icon="solar:hourglass-line-bold-duotone" class="h-6 w-6" />
                </div>
                <div class="space-y-1 max-w-[280px]">
                    <p class="text-sm font-medium text-[var(--app-text)]">Vocabulary isn’t ready yet</p>
                    <p class="text-xs text-[var(--app-text-muted)]">The analysis is taking longer than expected. Please try again in a moment.</p>
                </div>
                <button
                    class="mt-2 text-xs font-semibold text-[var(--app-accent)] uppercase tracking-wider hover:text-[var(--app-accent-strong)] transition"
                    @click="manualReload"
                >
                    check again
                </button>
            </div>
        </div>

        <div
          v-else-if="isLoading && !isReady && !isEmpty"
          class="flex w-full max-w-sm flex-col gap-4 sm:max-w-md"
          key="loading"
        >
          <div class="w-full aspect-[3/4] animate-pulse rounded-[32px] bg-[var(--app-surface-elevated)] border border-[var(--app-border)]" />
        </div>

        <div
          v-else-if="emptyStateVisible"
          class="flex flex-col items-center justify-center gap-5 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/50 px-6 py-10 text-center text-sm"
          key="empty"
        >
          <div class="rounded-full bg-[var(--app-surface-elevated)] p-4 text-[var(--app-accent)] ring-1 ring-[var(--app-border)]">
            <Icon icon="solar:card-2-bold-duotone" class="h-8 w-8" />
          </div>
          <div class="space-y-1">
            <p class="text-base font-semibold text-[var(--app-text)]">No flashcards yet</p>
            <p class="text-xs text-[var(--app-text-muted)] max-w-[200px] mx-auto leading-relaxed">
              Generate a fresh set of vocabulary cards for this lesson.
            </p>
          </div>
          <button
            class="rounded-full bg-[var(--app-accent)] px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[var(--app-accent)]/30 transition active:scale-95"
            @click="openGenerateModal"
          >
            Generate deck
          </button>
        </div>

        <div
          v-else-if="isReady && currentCard"
          class="relative flex w-full flex-1 flex-col items-center justify-center min-h-0"
          key="active"
        >
          <!-- exit focus button -->
          <button
            v-if="isFocusMode"
            type="button"
            class="absolute left-0 top-0 inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm backdrop-blur-md"
            @click="isFocusMode = false"
          >
            <Icon
              icon="solar:minimize-square-minimalistic-bold-duotone"
              class="h-4 w-4"
            />
          </button>

          <div
            class="flex h-full w-full flex-col items-center justify-center gap-6"
          >
            <div class="flex w-full flex-1 items-center justify-center">
              <div class="w-full max-w-[320px] sm:max-w-md">
                <Flashcard
                  :key="currentCard.id"
                  :wordId="currentCard.id"
                  :term="currentCard.term"
                  :meaning="currentCard.meaning"
                  :translation="currentCard.translation"
                  :exampleSentence="currentCard.exampleSentence"
                  :phonetic="currentCard.phonetic"
                  :partOfSpeech="currentCard.partOfSpeech"
                />
              </div>
            </div>

            <!-- navigation hidden in focus mode to keep only card visible -->
            <div
              v-if="!isFocusMode"
              class="flex w-full max-w-[320px] sm:max-w-md items-center justify-between gap-4"
            >
              <button
                type="button"
                class="flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm transition active:scale-95 disabled:opacity-30 disabled:active:scale-100"
                :disabled="!hasPrev"
                @click="goPrev"
              >
                <Icon icon="solar:arrow-left-linear" class="h-6 w-6" />
              </button>

              <div class="flex flex-col items-center">
                <span class="text-xs font-semibold text-[var(--app-text)]">
                  {{ reviewed }} / {{ total }}
                </span>
                <span class="text-[10px] text-[var(--app-text-muted)]">Cards</span>
              </div>

              <button
                type="button"
                class="flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm transition active:scale-95 disabled:opacity-30 disabled:active:scale-100"
                :disabled="!hasNext"
                @click="goNext"
              >
                <Icon icon="solar:arrow-right-linear" class="h-6 w-6" />
              </button>
            </div>
          </div>
        </div>

        <div
          v-else-if="isReady && !currentCard"
          class="flex flex-col items-center justify-center gap-3 text-sm text-[var(--app-text-muted)]"
          key="done"
        >
          <p>You have reviewed all flashcards for this lesson.</p>
        </div>
      </Transition>
    </div>

    <GenerateFlashcardsModal
      :open="showGenerateModal"
      :lesson-id="props.lessonId"
      @close="closeGenerateModal"
      @queued="handleGenerationQueued"
    />
  </section>
</template>

<style scoped>
.fade-scale-enter-active,
.fade-scale-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.fade-scale-enter-from,
.fade-scale-leave-to {
  opacity: 0;
  transform: translateY(10px) scale(0.98);
}
</style>

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
const toastMessage = ref('')
let toastTimeout: number | null = null
let pollingInterval: number | null = null
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

const handleGenerationQueued = () => {
  showGenerateModal.value = false
  if (isEmpty.value) {
    isGenerationPending.value = true
    startPolling()
  }
  pushToast('Flashcard generation queued')
}

watch(isReady, (ready) => {
  if (ready) {
    if (isGenerationPending.value) {
      pushToast('Flashcards are ready')
    }
    isGenerationPending.value = false
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
      class="flex items-center justify-between gap-3"
    >
      <div class="space-y-0.5">
        <p class="text-xs font-semibold">
          Flashcards
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)]">
          Based on this lesson’s vocabulary
        </p>
      </div>
      <div class="flex items-center gap-2 text-[11px] text-[var(--app-text-muted)]">
        <span
          v-if="total"
          class="hidden rounded-full border border-[var(--app-border)] px-2 py-1 sm:inline-flex"
        >
          {{ reviewed }} / {{ total }}
        </span>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-[var(--app-border)]"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          <span class="text-xs">AI</span>
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-[var(--app-border)]"
          :disabled="!isReady || !currentCard"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:fullscreen-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Toast (hidden in focus mode) -->
    <div
      v-if="toastMessage && !isFocusMode"
      class="mt-4 rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-center text-xs text-[var(--app-text)]"
    >
      {{ toastMessage }}
    </div>

    <div class="mt-4 flex flex-1 flex-col items-center justify-center gap-8">
      <div v-if="isError" class="flex flex-col items-center gap-3 text-sm text-[var(--app-accent-strong)]">
        <p>Something went wrong while loading flashcards.</p>
        <button
          class="rounded-full border border-[var(--app-accent-strong)] px-5 py-2 text-xs font-semibold text-[var(--app-accent-strong)]"
          @click="reload"
        >
          Try again
        </button>
      </div>

      <div v-if="isGenerationPending" class="w-full">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="generating"
            class="flex flex-col items-center gap-4 text-sm text-[var(--app-text-muted)]"
          >
            <span class="flex items-center gap-3 text-[var(--app-text)]">
              <svg class="h-4 w-4 animate-spin text-[var(--app-accent)]" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" />
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 0 1 8-8v3.5a4.5 4.5 0 0 0-4.5 4.5H4Z"
                />
              </svg>
              Generating flashcards… This may take a moment.
            </span>
            <button
              class="rounded-full border border-[var(--app-border)] px-4 py-1 text-xs text-[var(--app-text)] transition hover:text-[var(--app-accent-strong)]"
              @click="reload"
            >
              Check again
            </button>
          </div>
        </Transition>
      </div>

      <div
        v-else-if="isLoading && !isReady && !isEmpty"
        class="flex w-full max-w-sm flex-col gap-4 sm:max-w-md"
      >
        <div class="w-full aspect-[3/4] animate-pulse rounded-3xl bg-[var(--app-panel-muted)]" />
        <div class="mx-auto flex gap-4">
          <div class="h-10 w-10 animate-pulse rounded-full bg-[var(--app-panel-muted)]" />
          <div class="h-10 w-10 animate-pulse rounded-full bg-[var(--app-panel-muted)]" />
        </div>
      </div>

      <div
        v-else-if="emptyStateVisible"
        class="flex flex-col items-center justify-center gap-4 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-6 py-8 text-center text-sm text-[var(--app-text-muted)]"
      >
        <p class="text-base text-[var(--app-text)]">No flashcards for this lesson yet.</p>
        <p class="text-xs text-[var(--app-text-muted)]">
          Generate a set of key vocabulary to start practicing.
        </p>
        <button
          class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_12px_24px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)]"
          @click="openGenerateModal"
        >
          Generate flashcards
        </button>
      </div>

      <div
        v-else-if="isReady && currentCard"
        class="relative flex w-full flex-1 flex-col items-center justify-center"
      >
        <!-- exit focus button -->
        <button
          v-if="isFocusMode"
          type="button"
          class="absolute left-0 top-0 inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm dark:border-[var(--app-border)]"
          @click="isFocusMode = false"
        >
          <Icon
            icon="solar:arrow-left-linear"
            class="h-4 w-4"
          />
        </button>

        <Transition name="fade-scale" mode="out-in">
          <div
            key="flashcards"
            class="flex h-full w-full flex-col items-center justify-center gap-6"
          >
            <div class="flex w-full flex-1 items-center justify-center">
              <div class="w-full max-w-sm sm:max-w-md">
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
              class="mt-4 flex w-full items-center justify-center gap-10 text-xs text-[var(--app-text-muted)]"
            >
              <button
                type="button"
                class="flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:opacity-30"
                :disabled="!hasPrev"
                @click="goPrev"
              >
                <span class="text-base">←</span>
              </button>

              <span class="text-[11px] font-semibold">
                {{ reviewed }} / {{ total }}
              </span>

              <button
                type="button"
                class="flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:opacity-30"
                :disabled="!hasNext"
                @click="goNext"
              >
                <span class="text-base">→</span>
              </button>
            </div>
          </div>
        </Transition>
      </div>

      <div
        v-else-if="isReady && !currentCard"
        class="flex flex-col items-center justify-center gap-3 text-sm text-[var(--app-text-muted)]"
      >
        <p>You have reviewed all flashcards for this lesson.</p>
      </div>
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

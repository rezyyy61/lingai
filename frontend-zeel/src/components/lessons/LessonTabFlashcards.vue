<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
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
    stopPolling()
  }
})

watch(isGenerationPending, (pending) => {
  if (pending) {
    startPolling()
  } else {
    stopPolling()
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
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="space-y-1">
        <p class="text-[10px] font-semibold uppercase tracking-[0.25em] text-[var(--app-text-muted)]">
          Flashcards
        </p>
        <p class="text-sm text-[var(--app-text-muted)]">
          Review key vocabulary from this lesson
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-3 text-xs text-[var(--app-text-muted)]">
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1">
          Card: {{ reviewed }} / {{ total }}
        </span>
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1">
          Remaining: {{ remaining }}
        </span>
        <button
          type="button"
          class="rounded-full border border-[var(--app-border)] px-3 py-1 font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-surface-elevated)] disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          Generate flashcards
        </button>
      </div>
    </div>

    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--app-panel-muted)]">
      <div
        class="h-full rounded-full bg-[var(--app-accent-secondary)] transition-all duration-300"
        :style="{ width: progressPercent + '%' }"
      />
    </div>

    <div
      v-if="toastMessage"
      class="mt-4 rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-center text-xs text-[var(--app-text)]"
    >
      {{ toastMessage }}
    </div>

    <div class="mt-6 flex flex-1 flex-col items-center justify-center gap-8">
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
        class="flex w-full max-w-3xl flex-col gap-4"
      >
        <div class="w-full aspect-[4/3] animate-pulse rounded-[32px] bg-[var(--app-panel-muted)]" />
        <div class="mx-auto flex gap-4">
          <div class="h-12 w-32 animate-pulse rounded-full bg-[var(--app-panel-muted)]" />
          <div class="h-12 w-32 animate-pulse rounded-full bg-[var(--app-panel-muted)]" />
        </div>
      </div>

      <div
        v-else-if="emptyStateVisible"
        class="flex flex-col items-center justify-center gap-4 rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-6 py-10 text-center text-sm text-[var(--app-text-muted)]"
      >
        <p class="text-base text-[var(--app-text)]">No flashcards for this lesson yet.</p>
        <p class="text-xs text-[var(--app-text-muted)]">
          Generate a set of key vocabulary to start practicing.
        </p>
        <button
          class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_15px_30px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)]"
          @click="openGenerateModal"
        >
          Generate flashcards
        </button>
      </div>

      <div v-else-if="isReady && currentCard" class="w-full">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="flashcards"
            class="flex h-full w-full flex-col items-center gap-8"
          >
            <div class="flex w-full flex-1 items-center justify-center">
              <div class="w-full max-w-4xl">
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

            <div class="mt-auto flex w-full flex-col items-center gap-3 text-xs text-[var(--app-text-muted)]">
              <div class="flex w-full max-w-2xl gap-4">
                <button
                  class="flex-1 rounded-[999px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-4 text-base font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:opacity-40"
                  :disabled="!hasPrev"
                  @click="goPrev"
                >
                  Previous
                </button>
                <button
                  class="flex-1 rounded-[999px] bg-[var(--app-accent)] px-6 py-4 text-base font-semibold text-white transition hover:bg-[var(--app-accent-strong)] disabled:opacity-40"
                  :disabled="!hasNext"
                  @click="goNext"
                >
                  Next
                </button>
              </div>
              <p>
                {{ remaining }} cards remaining
              </p>
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

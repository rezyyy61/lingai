<script setup lang="ts">
import { onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useFlashcardReview } from '@/composables/useFlashcardReview'

const props = defineProps<{
  lessonId: number
}>()

const review = useFlashcardReview(props.lessonId)
const {
  currentCard,
  dueCount,
  errorMessage,
  isAnswerVisible,
  isComplete,
  isLoading,
  isSubmitting,
  reviewedCount,
  sessionInitialCount,
  successCount,
  failureCount,
} = review

onMounted(() => {
  review.start()
})
</script>

<template>
  <section class="rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/60 p-4 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--app-accent)]">Review</p>
        <p class="mt-1 text-sm text-[var(--app-text-muted)]">
          {{ dueCount }} due card{{ dueCount === 1 ? '' : 's' }}
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <span
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1 text-xs font-semibold text-[var(--app-text)]"
        >
          {{ successCount }} good
        </span>
        <span
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1 text-xs font-semibold text-[var(--app-text)]"
        >
          {{ failureCount }} bad
        </span>
        <button
          type="button"
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] transition active:scale-95"
          @click="review.reset()"
        >
          Reset
        </button>
      </div>
    </div>

    <div v-if="errorMessage" class="mt-4 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-300">
      {{ errorMessage }}
    </div>

    <div class="mt-4">
      <div v-if="isLoading" class="rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-5 py-8 text-center text-sm text-[var(--app-text-muted)]">
        Loading review cards…
      </div>

      <div
        v-else-if="currentCard"
        class="rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-5 py-6 shadow-sm"
      >
        <div class="flex items-center justify-between gap-3">
          <span class="rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--app-text-muted)]">
            {{ currentCard.review?.status ?? 'new' }}
          </span>
          <span class="text-xs text-[var(--app-text-muted)]">
            {{ reviewedCount + 1 }} / {{ Math.max(sessionInitialCount, 1) }}
          </span>
        </div>

        <div class="mt-6 text-center">
          <p class="text-3xl font-semibold tracking-tight text-[var(--app-text)] sm:text-4xl">
            {{ currentCard.term }}
          </p>
          <p v-if="currentCard.phonetic" class="mt-2 text-sm text-[var(--app-text-muted)]">
            {{ currentCard.phonetic }}
          </p>
        </div>

        <div v-if="isAnswerVisible" class="mt-6 space-y-4 rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-4">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--app-text-muted)]">Meaning</p>
            <p class="mt-1 text-base text-[var(--app-text)]">{{ currentCard.meaning || '—' }}</p>
          </div>
          <div v-if="currentCard.translation">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--app-text-muted)]">Translation</p>
            <p class="mt-1 text-base text-[var(--app-text)]">{{ currentCard.translation }}</p>
          </div>
          <div v-if="currentCard.exampleSentence">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--app-text-muted)]">Example</p>
            <p class="mt-1 text-sm leading-relaxed text-[var(--app-text)]">{{ currentCard.exampleSentence }}</p>
          </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
          <button
            v-if="!isAnswerVisible"
            type="button"
            class="rounded-full bg-[var(--app-accent)] px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[var(--app-accent)]/20 transition active:scale-95"
            @click="review.showAnswer()"
          >
            Show Answer
          </button>

          <template v-else>
            <button
              type="button"
              class="rounded-full border border-red-500/20 bg-red-500/10 px-5 py-2.5 text-sm font-semibold text-red-300 transition active:scale-95 disabled:opacity-50"
              :disabled="isSubmitting"
              @click="review.submit('dont_know')"
            >
              I don't know this
            </button>
            <button
              type="button"
              class="rounded-full bg-emerald-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 transition active:scale-95 disabled:opacity-50"
              :disabled="isSubmitting"
              @click="review.submit('know')"
            >
              I know this
            </button>
          </template>
        </div>
      </div>

      <div
        v-else-if="isComplete"
        class="rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-5 py-8 text-center"
      >
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-400">
          <Icon icon="solar:check-circle-bold-duotone" class="h-7 w-7" />
        </div>
        <p class="mt-4 text-lg font-semibold text-[var(--app-text)]">Review session complete</p>
        <p class="mt-1 text-sm text-[var(--app-text-muted)]">
          No more cards are due right now for this lesson.
        </p>
      </div>
    </div>
  </section>
</template>

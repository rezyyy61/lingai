<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useLessonExercises } from '@/composables/useLessonExercises'
import type { LessonExerciseOption } from '@/types/lesson'
import GenerateExercisesModal from './GenerateExercisesModal.vue'

const props = defineProps<{
  lessonId: number
}>()

const skillFilter = ref('')
const typeFilter = ref('')
const selectedOptionId = ref<number | null>(null)

const showGenerateModal = ref(false)
const isGenerationPending = ref(false)
const toastMessage = ref('')
const pendingBaselineSignature = ref<string | null>(null)
let toastTimeout: number | null = null
let pollingInterval: number | null = null

const {
  exercises,
  activeExercise,
  activeAttempt,
  activeIndex,
  total,
  isLoading,
  isError,
  isEmpty,
  isReady,
  hasPrev,
  hasNext,
  submitAttempt,
  goPrev,
  goNext,
  reload,
} = useLessonExercises(props.lessonId, {
  skill: skillFilter,
  type: typeFilter,
})

const progressLabel = computed(() =>
  total.value === 0 ? '0 / 0' : `${activeIndex.value + 1} / ${total.value}`,
)

const progressPercent = computed(() =>
  total.value === 0 ? 0 : Math.round(((activeIndex.value + 1) / Math.max(total.value, 1)) * 100),
)

const exercisesSignature = computed(() => exercises.value.map((exercise) => exercise.id).join('-'))

const availableSkills = computed(() => {
  const values = new Set<string>()
  if (skillFilter.value) {
    values.add(skillFilter.value)
  }
  exercises.value.forEach((exercise) => {
    if (exercise.skill) {
      values.add(exercise.skill)
    }
  })
  return Array.from(values)
})

const availableTypes = computed(() => {
  const values = new Set<string>()
  if (typeFilter.value) {
    values.add(typeFilter.value)
  }
  exercises.value.forEach((exercise) => {
    if (exercise.type) {
      values.add(exercise.type)
    }
  })
  return Array.from(values)
})

const hasAttempt = computed(() => !!activeAttempt.value)

const statusMessage = computed(() => {
  if (!activeAttempt.value) return ''
  return activeAttempt.value.isCorrect ? 'Correct!' : 'Try again'
})

const explanation = computed(() => {
  const exercise = activeExercise.value
  if (!exercise) return ''
  if (!activeAttempt.value) return ''
  return exercise.solutionExplanation ?? ''
})

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)
const canSubmit = computed(() => !!selectedOptionId.value && !activeAttempt.value)

watch(activeExercise, () => {
  const attempt = activeAttempt.value
  selectedOptionId.value = attempt ? attempt.selectedOptionId : null
})

watch(activeAttempt, (attempt) => {
  if (attempt) {
    selectedOptionId.value = attempt.selectedOptionId
  }
})

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
  pendingBaselineSignature.value = exercisesSignature.value
  isGenerationPending.value = true
  startPolling()
  pushToast('Exercise generation queued')
}

watch(isGenerationPending, (pending) => {
  if (pending) {
    startPolling()
  } else {
    stopPolling()
  }
})

watch(exercisesSignature, (signature) => {
  if (!isGenerationPending.value) return
  if (signature && signature !== pendingBaselineSignature.value) {
    isGenerationPending.value = false
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Exercises are ready')
  }
})

onBeforeUnmount(() => {
  if (toastTimeout) {
    clearTimeout(toastTimeout)
  }
  stopPolling()
})

const handleSelectOption = (optionId: number) => {
  if (activeAttempt.value) return
  selectedOptionId.value = optionId
}

const handleSubmit = async () => {
  if (!selectedOptionId.value || activeAttempt.value) {
    return
  }
  await submitAttempt(selectedOptionId.value)
}

const handlePrev = () => {
  goPrev()
  selectedOptionId.value = null
}

const handleNext = () => {
  goNext()
  selectedOptionId.value = null
}

const optionClasses = (option: LessonExerciseOption) => {
  const attempt = activeAttempt.value
  if (!attempt) {
    return selectedOptionId.value === option.id
      ? 'border-[var(--app-accent)] bg-[var(--app-panel-muted)] text-[var(--app-text)] dark:border-white/30 dark:bg-white/10 dark:text-white'
      : 'border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] hover:border-[var(--app-accent)] dark:border-white/10 dark:bg-white/5 dark:text-white/80 dark:hover:border-white/20'
  }

  if (attempt.selectedOptionId === option.id) {
    return attempt.isCorrect
      ? 'border-[var(--app-accent-secondary)] bg-[var(--app-accent-secondary-soft)] text-[var(--app-accent-secondary)]'
      : 'border-[var(--app-accent-strong)] bg-[var(--app-accent-soft)] text-[var(--app-accent-strong)]'
  }

  if (!attempt.isCorrect && option.isCorrect) {
    return 'border-[var(--app-accent-secondary)] bg-[var(--app-accent-secondary-soft)] text-[var(--app-accent-secondary)]'
  }

  return 'border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] dark:border-white/10 dark:bg-white/5 dark:text-white/80'
}
</script>

<template>
  <section class="flex h-full flex-col text-[var(--app-text)] dark:text-white">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="space-y-1">
        <p class="text-[10px] font-semibold uppercase tracking-[0.25em] text-[var(--app-text-muted)] dark:text-white/60">
          Exercises
        </p>
        <p class="text-sm text-[var(--app-text-muted)] dark:text-white/60">
          Choose the correct answer and see why it is correct
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-3 text-xs text-[var(--app-text-muted)] dark:text-white/60">
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Exercise: {{ progressLabel }}
        </span>
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Total: {{ total }}
        </span>
        <button
          type="button"
          class="rounded-full border border-[var(--app-border)] px-3 py-1 font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-surface-elevated)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15 dark:text-white/80 dark:hover:text-white"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          Generate exercises
        </button>
      </div>
    </div>

    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--app-panel-muted)] dark:bg-white/10">
      <div
        class="h-full rounded-full bg-[var(--app-accent-secondary)] transition-all duration-300"
        :style="{ width: progressPercent + '%' }"
      />
    </div>

    <div
      v-if="toastMessage"
      class="mt-4 rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-center text-xs text-[var(--app-text)] dark:border-white/10 dark:bg-white/5 dark:text-white/80"
    >
      {{ toastMessage }}
    </div>

    <div class="mt-6 flex flex-wrap gap-3 text-xs text-[var(--app-text-muted)] dark:text-white/70">
      <label class="flex flex-col gap-1">
        <span class="text-[10px] uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-white/50">Skill</span>
        <select
          v-model="skillFilter"
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1 text-xs text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-white/15 dark:bg-white/5 dark:text-white"
        >
          <option value="">All skills</option>
          <option v-for="skill in availableSkills" :key="skill" :value="skill">
            {{ skill }}
          </option>
        </select>
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-[10px] uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-white/50">Type</span>
        <select
          v-model="typeFilter"
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1 text-xs text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-white/15 dark:bg-white/5 dark:text-white"
        >
          <option value="">All types</option>
          <option v-for="type in availableTypes" :key="type" :value="type">
            {{ type }}
          </option>
        </select>
      </label>
    </div>

    <div class="mt-6 flex flex-1 flex-col items-center justify-center gap-8">
      <div v-if="isError" class="flex flex-col items-center gap-3 text-sm text-[var(--app-accent-strong)]">
        <p>Could not load exercises.</p>
        <button
          class="rounded-full border border-[var(--app-accent-strong)] px-4 py-1.5 text-xs font-medium text-[var(--app-accent-strong)]"
          @click="reload"
        >
          Try again
        </button>
      </div>

      <div v-if="isGenerationPending" class="w-full">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="exercises-generating"
            class="flex flex-col items-center gap-4 text-sm text-[var(--app-text-muted)] dark:text-white/70"
          >
            <span class="flex items-center gap-3 text-[var(--app-text)] dark:text-white">
              <svg class="h-4 w-4 animate-spin text-[var(--app-accent)]" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" />
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 0 1 8-8v3.5a4.5 4.5 0 0 0-4.5 4.5H4Z"
                />
              </svg>
              Generating exercises for this lesson…
            </span>
            <button
              class="rounded-full border border-[var(--app-border)] px-4 py-1 text-xs text-[var(--app-text)] transition hover:text-[var(--app-accent-strong)] dark:border-white/15 dark:text-white/80 dark:hover:text-white"
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
        <div class="w-full aspect-[4/3] animate-pulse rounded-[32px] bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
        <div class="mx-auto flex gap-4">
          <div class="h-12 w-32 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
          <div class="h-12 w-32 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80" />
        </div>
      </div>

      <div
        v-else-if="emptyStateVisible"
        class="flex flex-col items-center justify-center gap-4 rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-6 py-10 text-center text-sm text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/70"
      >
        <p class="text-base text-[var(--app-text)] dark:text-white">No exercises for this lesson yet.</p>
        <p class="text-xs text-[var(--app-text-muted)] dark:text-white/60">
          Generate targeted practice items to reinforce this lesson.
        </p>
        <button
          class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_15px_30px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)]"
          @click="openGenerateModal"
        >
          Generate exercises
        </button>
      </div>

      <div v-else-if="isReady && activeExercise" class="w-full">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="exercise-ready"
            class="w-full max-w-3xl rounded-[30px] border border-[var(--app-border)] bg-gradient-to-br from-[var(--app-panel)] via-[var(--app-surface-elevated)] to-[var(--app-panel)] px-8 py-7 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] dark:border-[var(--app-border-dark)] dark:bg-gradient-to-br dark:from-[var(--app-surface-dark)] dark:via-[var(--app-surface-dark-elevated)] dark:to-[var(--app-surface-dark)] dark:text-white dark:shadow-2xl"
          >
            <div class="flex items-center justify-between text-xs text-[var(--app-text-muted)] dark:text-white/60">
              <div class="flex flex-wrap items-center gap-2">
                <span>Exercise card</span>
                <span class="rounded-full bg-[var(--app-surface-dark-elevated)] px-2 py-0.5 text-[10px] text-white">
                  Exercise {{ activeIndex + 1 }} of {{ total }}
                </span>
                <span
                  v-if="activeExercise.skill"
                  class="rounded-full bg-[var(--app-accent-secondary-soft)] px-2 py-0.5 text-[10px] text-[var(--app-accent-secondary)]"
                >
                  {{ activeExercise.skill }}
                </span>
                <span
                  class="rounded-full bg-[var(--app-surface-dark-elevated)] px-2 py-0.5 text-[10px] capitalize text-white"
                >
                  {{ activeExercise.type }}
                </span>
              </div>
              <div v-if="statusMessage" class="text-[11px] font-semibold">
                <span
                  :class="
                    activeAttempt?.isCorrect
                      ? 'text-[var(--app-accent-secondary)]'
                      : 'text-[var(--app-accent-strong)]'
                  "
                >
                  {{ statusMessage }}
                </span>
              </div>
            </div>

            <div class="mt-5 space-y-2">
              <p class="text-lg font-medium tracking-wide">
                {{ activeExercise.questionPrompt }}
              </p>
              <p
                v-if="activeExercise.instructions"
                class="text-xs text-[var(--app-text-muted)] dark:text-white/60"
              >
                {{ activeExercise.instructions }}
              </p>
            </div>

            <div class="mt-6 space-y-2">
              <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[var(--app-text-muted)] dark:text-white/60">
                Select an answer
              </p>
              <div class="space-y-3">
                <button
                  v-for="option in activeExercise.options"
                  :key="option.id"
                  type="button"
                  class="flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left text-sm transition"
                  :class="optionClasses(option)"
                  @click="handleSelectOption(option.id)"
                >
                  <span class="mr-3 flex-1">
                    {{ option.text }}
                  </span>
                  <span
                    v-if="selectedOptionId === option.id && !activeAttempt"
                    class="ml-3 text-[11px] text-[var(--app-text-muted)] dark:text-white/70"
                  >
                    Selected
                  </span>
                </button>
              </div>
            </div>

            <div
              v-if="hasAttempt && explanation"
              class="mt-6 space-y-2 rounded-2xl bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-[var(--app-text)] dark:bg-white/5 dark:text-white"
            >
              <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[var(--app-text-muted)] dark:text-white/60">
                Why this answer is correct
              </p>
              <p>
                {{ explanation }}
              </p>
            </div>

            <div class="mt-6 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--app-text-muted)] dark:text-white/70">
                <span>Exercise {{ activeIndex + 1 }} of {{ total }}</span>
              </div>
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button
                  type="button"
                  class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_15px_30px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)] disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="!canSubmit"
                  @click="handleSubmit"
                >
                  {{ hasAttempt ? 'Answer submitted' : 'Check answer' }}
                </button>
                <div class="flex items-center gap-2">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-sm text-[var(--app-text)] disabled:opacity-40 dark:border-white/20 dark:bg-white/5 dark:text-white"
                    :disabled="!hasPrev"
                    @click="handlePrev"
                  >
                    ← Previous
                  </button>
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-sm text-[var(--app-text)] disabled:opacity-40 dark:border-white/20 dark:bg-white/5 dark:text-white"
                    :disabled="!hasNext"
                    @click="handleNext"
                  >
                    Next →
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </div>

      <div
        v-else-if="isReady && !activeExercise"
        class="flex h-full w-full max-w-3xl items-center justify-center rounded-3xl border border-[var(--app-border)] bg-[var(--app-panel)] px-6 py-6 text-sm text-[var(--app-text-muted)] shadow-[var(--app-card-shadow)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-white/70 dark:shadow-2xl"
      >
        No active exercise.
      </div>
    </div>

    <GenerateExercisesModal
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

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useLessonExercises } from '@/composables/useLessonExercises'
import type { LessonExerciseOption } from '@/types/lesson'
import GenerateExercisesModal from '@/components/lessons/GenerateExercisesModal.vue'

const props = defineProps<{ lessonId: number }>()

const skillFilter = ref('')
const typeFilter = ref('')
const selectedOptionId = ref<number | null>(null)

const showGenerateModal = ref(false)
const isGenerationPending = ref(false)
const toastMessage = ref('')
const pendingBaselineSignature = ref<string | null>(null)
let toastTimeout: number | null = null
let pollingInterval: number | null = null

const unanswered = ref<Record<number, true>>({})

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

const rawExercise = computed<any>(() => {
  const x: any = activeExercise.value
  return x?.exercise ?? x?.data ?? x?.item ?? x
})

const promptText = computed(() => {
  const ex: any = rawExercise.value
  return String(
    ex?.question_prompt ??
    ex?.questionPrompt ??
    ex?.question ??
    ex?.prompt ??
    ex?.text ??
    '',
  )
})

const options = computed<LessonExerciseOption[]>(() => {
  const ex: any = rawExercise.value
  const arr =
    ex?.options ??
    ex?.exerciseOptions ??
    ex?.exercise_options ??
    []
  return Array.isArray(arr) ? (arr as LessonExerciseOption[]) : []
})

const progressLabel = computed(() =>
  total.value === 0 ? '0 / 0' : `${activeIndex.value + 1} / ${total.value}`,
)

const progressPercent = computed(() =>
  total.value === 0 ? 0 : Math.round(((activeIndex.value + 1) / Math.max(total.value, 1)) * 100),
)

const exercisesSignature = computed(() => exercises.value.map((e: any) => e.id).join('-'))

const availableSkills = computed(() => {
  const s = new Set<string>()
  if (skillFilter.value) s.add(skillFilter.value)
  exercises.value.forEach((e: any) => {
    if (e?.skill) s.add(String(e.skill))
  })
  return Array.from(s)
})

const availableTypes = computed(() => {
  const s = new Set<string>()
  if (typeFilter.value) s.add(typeFilter.value)
  exercises.value.forEach((e: any) => {
    if (e?.type) s.add(String(e.type))
  })
  return Array.from(s)
})

const hasAttempt = computed(() => !!activeAttempt.value)

const statusMessage = computed(() => {
  if (!activeAttempt.value) return ''
  return activeAttempt.value.isCorrect ? 'Correct!' : 'Try again'
})

const explanation = computed(() => {
  if (!rawExercise.value || !activeAttempt.value) return ''
  const ex: any = rawExercise.value
  return String(ex?.solution_explanation ?? ex?.solutionExplanation ?? '')
})

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)
const canSubmit = computed(() => !!selectedOptionId.value && !activeAttempt.value)

const pendingList = computed(() => {
  const list: Array<{ id: number; index: number }> = []
  exercises.value.forEach((ex: any, idx: number) => {
    const id = Number(ex?.id || 0)
    if (!id) return
    if (unanswered.value[id] && idx !== activeIndex.value) {
      list.push({ id, index: idx })
    }
  })
  return list.slice(0, 14)
})

watch(activeExercise, () => {
  const attempt = activeAttempt.value
  selectedOptionId.value = attempt ? attempt.selectedOptionId : null

  const ex: any = rawExercise.value
  const id = Number(ex?.id || 0)
  if (id && attempt) {
    const copy = { ...unanswered.value }
    delete copy[id]
    unanswered.value = copy
  }
})

watch(activeAttempt, (attempt) => {
  if (!attempt) return
  selectedOptionId.value = attempt.selectedOptionId

  const ex: any = rawExercise.value
  const id = Number(ex?.id || 0)
  if (id) {
    const copy = { ...unanswered.value }
    delete copy[id]
    unanswered.value = copy
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
  if (toastTimeout) clearTimeout(toastTimeout)
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 3500)
}

const startPolling = () => {
  if (pollingInterval !== null) return
  pollingInterval = window.setInterval(() => reload(), 6000)
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
  if (pending) startPolling()
  else stopPolling()
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
  if (toastTimeout) clearTimeout(toastTimeout)
  stopPolling()
})

const handleSelectOption = (optionId: number) => {
  if (activeAttempt.value) return
  selectedOptionId.value = optionId
}

const handleSubmit = async () => {
  if (!selectedOptionId.value || activeAttempt.value) return
  await submitAttempt(selectedOptionId.value)
}

const markCurrentUnanswered = () => {
  const ex: any = rawExercise.value
  const id = Number(ex?.id || 0)
  if (!id) return
  if (activeAttempt.value) return
  unanswered.value = { ...unanswered.value, [id]: true }
}

const handlePrev = () => {
  goPrev()
  selectedOptionId.value = null
}

const handleNext = () => {
  if (!activeAttempt.value) markCurrentUnanswered()
  goNext()
  selectedOptionId.value = null
}

const jumpTo = (index: number) => {
  const cur = activeIndex.value
  if (index > cur) for (let i = cur; i < index; i++) goNext()
  if (index < cur) for (let i = cur; i > index; i--) goPrev()
  selectedOptionId.value = null
}

const optionClasses = (option: LessonExerciseOption) => {
  const attempt: any = activeAttempt.value

  if (!attempt) {
    return selectedOptionId.value === option.id
      ? 'border-[var(--app-accent)] bg-[var(--app-panel-muted)] text-[var(--app-text)]'
      : 'border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] hover:border-[var(--app-accent)]'
  }

  if (attempt.selectedOptionId === option.id) {
    return attempt.isCorrect
      ? 'border-[var(--app-accent-secondary)] bg-[var(--app-accent-secondary-soft)] text-[var(--app-accent-secondary)]'
      : 'border-[var(--app-accent-strong)] bg-[var(--app-accent-soft)] text-[var(--app-accent-strong)]'
  }

  if (!attempt.isCorrect && (option as any).isCorrect) {
    return 'border-[var(--app-accent-secondary)] bg-[var(--app-accent-secondary-soft)] text-[var(--app-accent-secondary)]'
  }

  return 'border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)]'
}

const skillLabel = computed(() => String(rawExercise.value?.skill ?? '').trim())
const typeLabel = computed(() => String(rawExercise.value?.type ?? '').trim())
</script>

<template>
  <section class="flex h-full flex-col text-[var(--app-text)]">
    <div class="flex items-center justify-between gap-3 px-1">
      <div class="space-y-0.5">
        <p class="text-xs font-semibold font-display tracking-wider uppercase text-[var(--app-accent)]">
          Exercises
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)] hidden sm:block">
          Practice your knowledge
        </p>
      </div>

      <div class="flex items-center gap-2 text-[11px] text-[var(--app-text-muted)]">
        <span class="rounded-full bg-[var(--app-surface-elevated)] border border-[var(--app-border)] px-2.5 py-1 font-medium">
          {{ progressLabel }}
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
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95"
          @click="reload"
        >
          <Icon icon="solar:refresh-outline" class="h-4 w-4" />
        </button>
      </div>
    </div>

    <div class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-[var(--app-panel-muted)]">
      <div class="h-full rounded-full bg-[var(--app-accent)] transition-all duration-300" :style="{ width: progressPercent + '%' }" />
    </div>

    <div v-if="toastMessage" class="mt-3 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-center text-xs text-[var(--app-text)] shadow-sm">
      {{ toastMessage }}
    </div>

    <div v-if="pendingList.length" class="mt-4">
      <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">
        Unanswered
      </div>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          v-for="p in pendingList"
          :key="p.id"
          type="button"
          class="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1 text-[11px] font-semibold text-[var(--app-text)] active:scale-[0.99]"
          @click="jumpTo(p.index)"
        >
          #{{ p.index + 1 }}
        </button>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 text-xs">
      <label class="relative">
        <select
          v-model="skillFilter"
          class="appearance-none rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-elevated)] pl-3 pr-8 py-1.5 text-xs font-medium text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none"
        >
          <option value="">All skills</option>
          <option v-for="skill in availableSkills" :key="skill" :value="skill">
            {{ skill }}
          </option>
        </select>
        <Icon icon="solar:alt-arrow-down-bold" class="absolute right-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-[var(--app-text-muted)] pointer-events-none" />
      </label>

      <label class="relative">
        <select
          v-model="typeFilter"
          class="appearance-none rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-elevated)] pl-3 pr-8 py-1.5 text-xs font-medium text-[var(--app-text)] focus:border-[var(--app-accent)] focus:outline-none"
        >
          <option value="">All types</option>
          <option v-for="type in availableTypes" :key="type" :value="type">
            {{ type }}
          </option>
        </select>
        <Icon icon="solar:alt-arrow-down-bold" class="absolute right-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-[var(--app-text-muted)] pointer-events-none" />
      </label>
    </div>

    <div class="mt-4 flex flex-1 flex-col items-center justify-start pb-4 relative">
      <Transition name="fade-scale" mode="out-in">
        <div
          v-if="isError"
          class="w-full flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-600 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-400"
          key="error"
        >
          <p class="text-xs font-medium">Could not load exercises.</p>
          <button class="rounded-full border border-current px-3 py-1 text-[10px] font-bold uppercase tracking-wider" @click="reload">
            Retry
          </button>
        </div>

        <div v-else-if="isGenerationPending" class="w-full mt-2" key="generating">
          <div class="flex flex-col items-center gap-4 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-8 text-center shadow-sm">
            <Icon icon="svg-spinners:90-ring-with-bg" class="h-8 w-8 text-[var(--app-accent)]" />
            <div class="space-y-1">
              <p class="text-sm font-medium text-[var(--app-text)]">Generating exercises...</p>
              <p class="text-xs text-[var(--app-text-muted)]">Crafting questions based on lesson content.</p>
            </div>
          </div>
        </div>

        <div
          v-else-if="isLoading && !isReady && !isEmpty"
          class="flex w-full flex-col gap-4 p-4 rounded-[24px] bg-[var(--app-surface-elevated)] border border-[var(--app-border)]"
          key="loading"
        >
          <div class="w-2/3 h-6 animate-pulse rounded-md bg-[var(--app-panel-muted)]" />
          <div class="space-y-3 mt-2">
            <div class="h-12 w-full animate-pulse rounded-xl bg-[var(--app-panel-muted)]" />
            <div class="h-12 w-full animate-pulse rounded-xl bg-[var(--app-panel-muted)]" />
            <div class="h-12 w-full animate-pulse rounded-xl bg-[var(--app-panel-muted)]" />
          </div>
        </div>

        <div
          v-else-if="emptyStateVisible"
          class="w-full rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-10 text-center"
          key="empty"
        >
          <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[var(--app-panel-muted)] text-[var(--app-accent)]">
            <Icon icon="solar:dumbbell-large-bold-duotone" class="h-7 w-7" />
          </div>
          <p class="text-sm font-medium text-[var(--app-text)]">No exercises yet</p>
          <p class="mt-1 text-xs text-[var(--app-text-muted)]">
            Generate targeted practice items to reinforce this lesson.
          </p>
          <button
            class="mt-6 w-full rounded-xl bg-[var(--app-accent)] px-6 py-3 text-sm font-bold text-white shadow-md shadow-[var(--app-accent)]/20 active:scale-95 transition-transform"
            @click="openGenerateModal"
          >
            Generate exercises
          </button>
        </div>

        <div v-else-if="isReady && rawExercise" class="w-full flex-1 flex flex-col" key="active">
          <div class="relative flex-1 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-sm overflow-hidden flex flex-col">
            <div class="px-5 pt-5 pb-2 flex items-center justify-between">
              <div class="flex gap-2">
                <span
                  v-if="skillLabel"
                  class="inline-flex items-center rounded-md bg-[var(--app-surface)] border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]"
                >
                  {{ skillLabel }}
                </span>
                <span
                  v-if="typeLabel"
                  class="inline-flex items-center rounded-md bg-[var(--app-surface)] border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]"
                >
                  {{ typeLabel }}
                </span>
              </div>

              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition active:scale-95"
                  @click="markCurrentUnanswered"
                >
                  <Icon icon="solar:bookmark-outline" class="h-5 w-5" />
                </button>

                <div v-if="statusMessage" class="flex items-center gap-1.5">
                  <Icon
                    :icon="activeAttempt?.isCorrect ? 'solar:check-circle-bold' : 'solar:close-circle-bold'"
                    class="h-4 w-4"
                    :class="activeAttempt?.isCorrect ? 'text-[var(--app-accent-secondary)]' : 'text-red-500'"
                  />
                  <span class="text-xs font-bold" :class="activeAttempt?.isCorrect ? 'text-[var(--app-accent-secondary)]' : 'text-red-500'">
                    {{ statusMessage }}
                  </span>
                </div>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto px-5 pb-5">
              <div class="mt-2 text-[var(--app-text)]">
                <h3 class="font-display text-xl font-semibold leading-snug sm:text-2xl">
                  {{ promptText || '—' }}
                </h3>
              </div>

              <div class="mt-6 space-y-3">
                <button
                  v-for="option in options"
                  :key="option.id"
                  type="button"
                  class="group relative flex w-full items-center gap-3 rounded-xl border p-4 text-left transition-all active:scale-[0.98]"
                  :class="optionClasses(option)"
                  @click="handleSelectOption(option.id)"
                >
                  <div
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition-colors"
                    :class="selectedOptionId === option.id ? 'border-current' : 'border-[var(--app-border)] opacity-50 group-hover:opacity-100'"
                  >
                    <div v-if="selectedOptionId === option.id" class="h-2.5 w-2.5 rounded-full bg-current" />
                  </div>
                  <span class="text-sm font-medium leading-normal">{{ option.text }}</span>
                </button>
              </div>

              <div v-if="hasAttempt && explanation" class="mt-6 animate-in fade-in slide-in-from-bottom-2 duration-300">
                <div class="rounded-xl bg-[var(--app-surface)] p-4 border border-[var(--app-border)]">
                  <p class="mb-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">
                    Explanation
                  </p>
                  <p class="text-sm leading-relaxed text-[var(--app-text)]">
                    {{ explanation }}
                  </p>
                </div>
              </div>
            </div>

            <div class="mt-auto border-t border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 backdrop-blur-xl">
              <div class="flex items-center gap-3">
                <button
                  type="button"
                  class="inline-flex h-12 w-12 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition active:scale-95 disabled:opacity-40"
                  :disabled="!hasPrev"
                  @click="handlePrev"
                >
                  <Icon icon="solar:arrow-left-linear" class="h-5 w-5" />
                </button>

                <button
                  v-if="!hasAttempt"
                  type="button"
                  class="flex-1 h-12 rounded-xl bg-[var(--app-accent)] px-6 text-sm font-bold text-white shadow-md shadow-[var(--app-accent)]/20 transition active:scale-95 disabled:opacity-50 disabled:shadow-none"
                  :disabled="!canSubmit"
                  @click="handleSubmit"
                >
                  Check Answer
                </button>

                <button
                  v-else
                  type="button"
                  class="flex-1 h-12 rounded-xl bg-[var(--app-surface)] border border-[var(--app-border)] px-6 text-sm font-bold text-[var(--app-text)] transition active:scale-95 disabled:opacity-40"
                  :disabled="!hasNext"
                  @click="handleNext"
                >
                  {{ hasNext ? 'Next Question' : 'Finish' }}
                  <Icon v-if="hasNext" icon="solar:arrow-right-linear" class="inline-block ml-1 h-4 w-4" />
                </button>

                <button
                  type="button"
                  class="inline-flex h-12 w-12 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition active:scale-95 disabled:opacity-40"
                  :disabled="!hasNext"
                  @click="handleNext"
                  :aria-label="hasAttempt ? 'Next' : 'Skip and next'"
                >
                  <Icon :icon="hasAttempt ? 'solar:arrow-right-linear' : 'solar:skip-next-bold'" class="h-5 w-5" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="flex h-full w-full items-center justify-center text-sm text-[var(--app-text-muted)]" key="fallback">
          No active exercise.
        </div>
      </Transition>
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

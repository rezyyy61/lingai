import { ref, computed, onMounted, watch, type Ref } from 'vue'
import type {
  LessonExerciseDto,
  LessonExercise,
  LessonExerciseOption,
  LessonExerciseAttemptResponse,
} from '@/types/lesson'
import { fetchLessonExercises, attemptLessonExercise } from '@/api/lessonExercises'

interface ExerciseAttemptState {
  selectedOptionId: number
  isCorrect: boolean
}

function mapExercise(dto: LessonExerciseDto): LessonExercise {
  const options: LessonExerciseOption[] = (dto.options ?? []).map((option) => ({
    id: option.id,
    text: option.text,
    isCorrect: !!option.is_correct,
  }))

  return {
    id: dto.id,
    lessonId: dto.lesson_id,
    sentenceId: dto.lesson_sentence_id ?? null,
    type: dto.type,
    skill: dto.skill ?? null,
    questionPrompt: dto.question_prompt,
    instructions: dto.instructions ?? null,
    solutionExplanation: dto.solution_explanation ?? null,
    options,
  }
}

type ExerciseFilters = {
  skill?: Ref<string>
  type?: Ref<string>
}

export function useLessonExercises(lessonId: number, filters?: ExerciseFilters) {
  const isLoading = ref(false)
  const isError = ref(false)
  const hasLoadedOnce = ref(false)

  const exercises = ref<LessonExercise[]>([])
  const activeIndex = ref(0)
  const attempts = ref<Record<number, ExerciseAttemptState>>({})

  const total = computed(() => exercises.value.length)

  const activeExercise = computed<LessonExercise | null>(() => {
    if (activeIndex.value < 0 || activeIndex.value >= exercises.value.length) {
      return null
    }
    return exercises.value[activeIndex.value] ?? null
  })

  const isEmpty = computed(
    () => hasLoadedOnce.value && !isLoading.value && total.value === 0 && !isError.value
  )

  const isReady = computed(
    () => !isLoading.value && !isError.value && total.value > 0
  )

  const hasPrev = computed(() => activeIndex.value > 0)
  const hasNext = computed(() => activeIndex.value < total.value - 1)

  const activeAttempt = computed<ExerciseAttemptState | null>(() => {
    const exercise = activeExercise.value
    if (!exercise) return null
    return attempts.value[exercise.id] ?? null
  })

  async function load() {
    isLoading.value = true
    isError.value = false
    try {
      const rows = await fetchLessonExercises(lessonId, {
        skill: filters?.skill?.value || undefined,
        type: filters?.type?.value || undefined,
      })
      exercises.value = rows.map(mapExercise)
      activeIndex.value = 0
      attempts.value = {}
    } catch {
      isError.value = true
    } finally {
      hasLoadedOnce.value = true
      isLoading.value = false
    }
  }

  async function submitAttempt(optionId: number) {
    const exercise = activeExercise.value
    if (!exercise) return false

    if (attempts.value[exercise.id]) {
      return true
    }

    try {
      const response: LessonExerciseAttemptResponse = await attemptLessonExercise(
        exercise.id,
        optionId
      )

      attempts.value = {
        ...attempts.value,
        [exercise.id]: {
          selectedOptionId: optionId,
          isCorrect: !!response.is_correct,
        },
      }
      return true
    } catch {
      return false
    }
  }

  function goPrev() {
    if (hasPrev.value) {
      activeIndex.value -= 1
    }
  }

  function goNext() {
    if (hasNext.value) {
      activeIndex.value += 1
    }
  }

  function reload() {
    load()
  }

  onMounted(load)

  if (filters?.skill || filters?.type) {
    watch(
      [filters?.skill ?? ref(''), filters?.type ?? ref('')],
      () => {
        load()
      },
    )
  }

  return {
    exercises,
    activeExercise,
    activeIndex,
    activeAttempt,
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
  }
}

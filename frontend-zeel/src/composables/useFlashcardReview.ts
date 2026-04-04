import { computed, ref } from 'vue'
import type { LessonFlashcard, LessonWordDto } from '@/types/lesson'
import { fetchDueLessonFlashcards, resetLessonFlashcardReview, submitLessonFlashcardReview } from '@/api/lessonFlashcards'

function mapLessonWordToFlashcard(dto: LessonWordDto): LessonFlashcard {
  return {
    id: dto.id,
    lessonId: dto.lesson_id,
    term: dto.term,
    meaning: dto.meaning ?? '',
    translation: dto.translation ?? '',
    exampleSentence: dto.example_sentence ?? null,
    phonetic: dto.phonetic ?? null,
    partOfSpeech: dto.part_of_speech ?? null,
    review: dto.review ?? null,
  }
}

export function useFlashcardReview(lessonId: number) {
  const queue = ref<LessonFlashcard[]>([])
  const isLoading = ref(false)
  const isSubmitting = ref(false)
  const isAnswerVisible = ref(false)
  const dueCount = ref(0)
  const sessionInitialCount = ref(0)
  const successCount = ref(0)
  const failureCount = ref(0)
  const errorMessage = ref('')

  const currentCard = computed(() => queue.value[0] ?? null)
  const reviewedCount = computed(() => Math.max(0, sessionInitialCount.value - queue.value.length))
  const hasCards = computed(() => queue.value.length > 0)
  const isComplete = computed(() => !isLoading.value && queue.value.length === 0)

  function setQueue(cards: LessonFlashcard[]) {
    queue.value = cards
    dueCount.value = cards.length
  }

  async function loadQueue(limit = 20) {
    isLoading.value = true
    errorMessage.value = ''

    try {
      const response = await fetchDueLessonFlashcards(lessonId, limit)
      const cards = response.data.map(mapLessonWordToFlashcard)
      setQueue(cards)
      sessionInitialCount.value = response.data.length
    } catch (error) {
      console.error(error)
      errorMessage.value = 'Could not load review cards.'
      queue.value = []
      dueCount.value = 0
      sessionInitialCount.value = 0
    } finally {
      isLoading.value = false
    }
  }

  async function start(limit = 20) {
    isAnswerVisible.value = false
    successCount.value = 0
    failureCount.value = 0
    await loadQueue(limit)
  }

  function showAnswer() {
    isAnswerVisible.value = true
  }

  async function submit(result: 'know' | 'dont_know') {
    if (!currentCard.value || isSubmitting.value) {
      return
    }

    isSubmitting.value = true
    errorMessage.value = ''

    try {
      const response = await submitLessonFlashcardReview(currentCard.value.id, result)
      const remainingCards = queue.value.slice(1)

      if (result === 'dont_know') {
        remainingCards.push(mapLessonWordToFlashcard(response.card))
      }

      setQueue(remainingCards)
      isAnswerVisible.value = false
      if (result === 'know') {
        successCount.value += 1
      } else {
        failureCount.value += 1
      }
    } catch (error) {
      console.error(error)
      errorMessage.value = 'Could not save review result.'
    } finally {
      isSubmitting.value = false
    }
  }

  async function reset(limit = 20) {
    isLoading.value = true
    errorMessage.value = ''

    try {
      const response = await resetLessonFlashcardReview(lessonId, limit)
      const cards = response.data.map(mapLessonWordToFlashcard)
      setQueue(cards)
      sessionInitialCount.value = response.data.length
      successCount.value = 0
      failureCount.value = 0
      isAnswerVisible.value = false
    } catch (error) {
      console.error(error)
      errorMessage.value = 'Could not reset review cards.'
    } finally {
      isLoading.value = false
    }
  }

  return {
    currentCard,
    queue,
    isLoading,
    isSubmitting,
    isAnswerVisible,
    dueCount,
    reviewedCount,
    sessionInitialCount,
    successCount,
    failureCount,
    hasCards,
    isComplete,
    errorMessage,
    start,
    showAnswer,
    submit,
    loadQueue,
    reset,
  }
}

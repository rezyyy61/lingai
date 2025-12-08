import { ref, computed, onMounted } from 'vue'
import type { LessonWordDto, LessonFlashcard } from '@/types/lesson'
import { fetchLessonWords } from '@/api/lessonFlashcards'

function mapLessonWordToFlashcard(dto: LessonWordDto): LessonFlashcard {
  return {
    id: dto.id,
    term: dto.term,
    meaning: dto.meaning,
    translation: dto.translation,
    exampleSentence: dto.example_sentence ?? null,
    phonetic: dto.phonetic ?? null,
    partOfSpeech: dto.part_of_speech ?? null,
  }
}

export function useLessonFlashcards(lessonId: number) {
  const isLoading = ref(false)
  const isError = ref(false)
  const hasLoadedOnce = ref(false)

  const cards = ref<LessonFlashcard[]>([])
  const currentIndex = ref(0)

  const total = computed(() => cards.value.length)

  const currentCard = computed<LessonFlashcard | null>(() => {
    if (currentIndex.value < 0 || currentIndex.value >= cards.value.length) {
      return null
    }
    return cards.value[currentIndex.value] ?? null
  })

  const reviewed = computed(() => {
    if (!total.value) return 0
    if (!currentCard.value && currentIndex.value >= total.value) {
      return total.value
    }
    return currentIndex.value + 1
  })

  const remaining = computed(() => {
    if (!total.value) return 0
    if (!currentCard.value && currentIndex.value >= total.value) {
      return 0
    }
    return total.value - reviewed.value
  })

  const isEmpty = computed(
    () => hasLoadedOnce.value && !isLoading.value && total.value === 0 && !isError.value
  )

  const isReady = computed(
    () => !isLoading.value && !isError.value && total.value > 0
  )

  const hasPrev = computed(() => currentIndex.value > 0)
  const hasNext = computed(() => currentIndex.value < total.value - 1)

  async function load() {
    isLoading.value = true
    isError.value = false
    try {
      const words = await fetchLessonWords(lessonId)
      cards.value = words.map(mapLessonWordToFlashcard)
      currentIndex.value = 0
    } catch {
      isError.value = true
    } finally {
      hasLoadedOnce.value = true
      isLoading.value = false
    }
  }

  function goNext() {
    if (hasNext.value) {
      currentIndex.value += 1
    }
  }

  function goPrev() {
    if (hasPrev.value) {
      currentIndex.value -= 1
    }
  }

  function reload() {
    load()
  }

  onMounted(load)

  return {
    cards,
    currentCard,
    currentIndex,
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
  }
}

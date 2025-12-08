import { ref, computed, onMounted } from 'vue'
import type { LessonSentenceDto, LessonShadowSentence } from '@/types/lesson'
import { fetchLessonSentences } from '@/api/lessonShadowing'

function mapSentence(dto: LessonSentenceDto): LessonShadowSentence {
  return {
    id: dto.id,
    lessonId: dto.lesson_id,
    orderIndex: dto.order_index,
    text: dto.text,
    translation: dto.translation ?? null,
    source: dto.source,
    startTime: dto.start_time ?? null,
    endTime: dto.end_time ?? null,
  }
}


export function useLessonShadowing(lessonId: number) {
  const isLoading = ref(false)
  const isError = ref(false)
  const hasLoadedOnce = ref(false)

  const sentences = ref<LessonShadowSentence[]>([])
  const activeIndex = ref(0)

  const total = computed(() => sentences.value.length)

  const activeSentence = computed<LessonShadowSentence | null>(() => {
    if (activeIndex.value < 0 || activeIndex.value >= sentences.value.length) {
      return null
    }
    return sentences.value[activeIndex.value] ?? null
  })

  const isEmpty = computed(
    () => hasLoadedOnce.value && !isLoading.value && total.value === 0 && !isError.value
  )

  const isReady = computed(
    () => !isLoading.value && !isError.value && total.value > 0
  )

  const hasPrev = computed(() => activeIndex.value > 0)
  const hasNext = computed(() => activeIndex.value < total.value - 1)

  async function load() {
    isLoading.value = true
    isError.value = false
    try {
      const rows = await fetchLessonSentences(lessonId)
      sentences.value = rows.map(mapSentence)
      activeIndex.value = 0
    } catch {
      isError.value = true
    } finally {
      hasLoadedOnce.value = true
      isLoading.value = false
    }
  }

  function setActive(index: number) {
    if (index < 0 || index >= sentences.value.length) return
    activeIndex.value = index
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

  return {
    sentences,
    activeSentence,
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
  }
}

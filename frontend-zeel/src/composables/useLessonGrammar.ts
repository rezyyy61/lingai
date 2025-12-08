import { ref, computed, onMounted } from 'vue'
import type {
  LessonGrammarPointDto,
  GrammarExampleDto,
  GrammarPracticeItemDto,
} from '@/api/lessonGrammar'
import { fetchLessonGrammarPoints } from '@/api/lessonGrammar'

export interface GrammarExample {
  text: string
  translation?: string | null
}

export interface GrammarPracticeItem {
  prompt: string
  answer?: string | null
  explanation?: string | null
}

export interface LessonGrammarPoint {
  id: number
  lessonId: number
  key?: string | null

  title: string

  level?: string | null
  description?: string | null
  pattern?: string | null

  summary?: string | null
  explanation?: string | null
  tips?: string | null

  examples: GrammarExample[]
  practiceItems: GrammarPracticeItem[]

  meta?: unknown
}

function mapGrammarPoint(dto: LessonGrammarPointDto): LessonGrammarPoint {
  let examples: GrammarExample[] = []

  const rawExamples = dto.examples

  if (Array.isArray(rawExamples)) {
    examples = rawExamples.map((ex: GrammarExampleDto) => ({
      text: ex.sentence || ex.text || '',
      translation: ex.translation ?? null,
    }))
  } else if (typeof rawExamples === 'string' && rawExamples.trim().length > 0) {
    try {
      const parsed = JSON.parse(rawExamples) as GrammarExampleDto[]
      if (Array.isArray(parsed)) {
        examples = parsed.map((ex: GrammarExampleDto) => ({
          text: ex.sentence || ex.text || '',
          translation: ex.translation ?? null,
        }))
      }
    } catch (e) {
      console.warn('Failed to parse grammar examples JSON', e)
    }
  }

  const practiceItems: GrammarPracticeItem[] = Array.isArray(dto.practice_items)
    ? dto.practice_items.map((p: GrammarPracticeItemDto) => ({
      prompt: p.prompt,
      answer: p.answer ?? null,
      explanation: p.explanation ?? null,
    }))
    : []

  return {
    id: dto.id,
    lessonId: dto.lesson_id,
    key: dto.key ?? null,

    title: dto.title,

    level: dto.level ?? null,
    description: dto.description ?? null,
    pattern: dto.pattern ?? null,

    summary: dto.summary ?? null,
    explanation: dto.explanation ?? null,
    tips: dto.tips ?? null,

    examples,
    practiceItems,

    meta: dto.meta,
  }
}


export function useLessonGrammar(lessonId: number) {
  const grammarPoints = ref<LessonGrammarPoint[]>([])
  const activeIndex = ref(0)
  const isLoading = ref(false)
  const isError = ref(false)
  const hasLoadedOnce = ref(false)

  const total = computed(() => grammarPoints.value.length)

  const activePoint = computed<LessonGrammarPoint | null>(() => {
    if (activeIndex.value < 0 || activeIndex.value >= grammarPoints.value.length) return null
    return grammarPoints.value[activeIndex.value] ?? null
  })

  const isEmpty = computed(
    () => hasLoadedOnce.value && !isLoading.value && total.value === 0 && !isError.value,
  )

  const isReady = computed(
    () => !isLoading.value && !isError.value && total.value > 0,
  )

  const hasPrev = computed(() => activeIndex.value > 0)
  const hasNext = computed(() => activeIndex.value < total.value - 1)

  async function load() {
    isLoading.value = true
    isError.value = false
    try {
      const rows = await fetchLessonGrammarPoints(lessonId)
      grammarPoints.value = rows.map(mapGrammarPoint)

      if (grammarPoints.value.length === 0) {
        activeIndex.value = 0
      } else if (activeIndex.value >= grammarPoints.value.length) {
        activeIndex.value = 0
      }
    } catch (e) {
      console.error('Failed to load lesson grammar points', e)
      isError.value = true
    } finally {
      hasLoadedOnce.value = true
      isLoading.value = false
    }
  }

  function setActive(index: number) {
    if (index < 0 || index >= grammarPoints.value.length) return
    activeIndex.value = index
  }

  function goPrev() {
    if (hasPrev.value) activeIndex.value -= 1
  }

  function goNext() {
    if (hasNext.value) activeIndex.value += 1
  }

  function reload() {
    load()
  }

  onMounted(load)

  return {
    grammarPoints,
    activePoint,
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

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { fetchLesson } from '@/api/lessonApi'
import type { LessonContentGenerationState } from '@/types/lesson'

export type LessonGenerationFeature = 'flashcards' | 'shadowing' | 'grammar' | 'exercises'

interface UseLessonContentGenerationOptions {
  lessonId: number
  feature: LessonGenerationFeature
  reloadContent: () => Promise<unknown> | unknown
  onReady?: (state: LessonContentGenerationState | null) => void | Promise<void>
  onFailed?: (state: LessonContentGenerationState | null) => void | Promise<void>
  pollIntervalMs?: number
  timeoutMs?: number
}

export function useLessonContentGeneration(options: UseLessonContentGenerationOptions) {
  const generation = ref<LessonContentGenerationState | null>(null)
  const isSyncing = ref(false)
  const hasTimedOut = ref(false)
  const lastHandledToken = ref<string | null>(null)

  let pollInterval: number | null = null
  let timeoutTimer: number | null = null

  const status = computed(() => generation.value?.status ?? null)
  const message = computed(() => generation.value?.message ?? null)
  const isPending = computed(() => status.value === 'processing')
  const isFailed = computed(() => status.value === 'failed' || hasTimedOut.value)
  const startedAt = computed(() => generation.value?.started_at ?? null)
  const waitingSeconds = computed(() => {
    if (!startedAt.value) return 0
    const started = new Date(startedAt.value).getTime()
    if (Number.isNaN(started)) return 0
    return Math.max(0, Math.round((Date.now() - started) / 1000))
  })

  const stopPolling = () => {
    if (pollInterval !== null) {
      clearInterval(pollInterval)
      pollInterval = null
    }

    if (timeoutTimer !== null) {
      clearTimeout(timeoutTimer)
      timeoutTimer = null
    }
  }

  const startTimeout = () => {
    if (timeoutTimer !== null) return

    timeoutTimer = window.setTimeout(() => {
      hasTimedOut.value = true
      stopPolling()
    }, options.timeoutMs ?? 120000)
  }

  const startPolling = () => {
    if (pollInterval !== null) return

    hasTimedOut.value = false
    startTimeout()

    pollInterval = window.setInterval(() => {
      void syncStatus()
    }, options.pollIntervalMs ?? 3000)
  }

  const buildHandledToken = (state: LessonContentGenerationState | null) => {
    if (!state?.status) return null
    return [
      state.status,
      state.completed_at ?? '',
      state.failed_at ?? '',
      state.updated_at ?? '',
      state.item_count ?? '',
    ].join(':')
  }

  const syncStatus = async () => {
    if (isSyncing.value) return generation.value

    isSyncing.value = true
    try {
      const lesson = await fetchLesson(options.lessonId)
      const nextState = lesson.analysis_meta?.content_generation?.[options.feature] ?? null
      generation.value = nextState

      if (nextState?.status === 'processing') {
        startPolling()
        return nextState
      }

      stopPolling()

      const handledToken = buildHandledToken(nextState)
      if (handledToken && handledToken !== lastHandledToken.value) {
        lastHandledToken.value = handledToken

        if (nextState?.status === 'ready') {
          await options.reloadContent()
          await options.onReady?.(nextState)
        } else if (nextState?.status === 'failed') {
          await options.onFailed?.(nextState)
        }
      }

      return nextState
    } finally {
      isSyncing.value = false
    }
  }

  const beginTracking = async () => {
    hasTimedOut.value = false
    await syncStatus()
    startPolling()
  }

  onMounted(() => {
    void syncStatus()
  })

  onBeforeUnmount(() => {
    stopPolling()
  })

  return {
    generation,
    status,
    message,
    isPending,
    isFailed,
    hasTimedOut,
    waitingSeconds,
    syncStatus,
    beginTracking,
    stopPolling,
  }
}

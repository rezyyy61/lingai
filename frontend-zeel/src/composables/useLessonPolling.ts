import { computed, onBeforeUnmount, ref } from 'vue'

type PollState = 'idle' | 'polling' | 'ready' | 'error'

export function useLessonPolling<TLesson>(
  fetchLesson: () => Promise<TLesson>,
  isReady: (lesson: TLesson) => boolean,
  opts?: { intervalMs?: number; maxMs?: number },
) {
  const intervalMs = opts?.intervalMs ?? 1500
  const maxMs = opts?.maxMs ?? 120000

  const lesson = ref<TLesson | null>(null)
  const state = ref<PollState>('idle')
  const error = ref('')

  let timer: number | null = null
  let startedAt = 0

  const isPolling = computed(() => state.value === 'polling')
  const isReadyState = computed(() => state.value === 'ready')

  const stop = () => {
    if (timer) {
      window.clearTimeout(timer)
      timer = null
    }
    if (state.value === 'polling') state.value = 'idle'
  }

  const tick = async () => {
    if (!startedAt) startedAt = Date.now()
    const elapsed = Date.now() - startedAt
    if (elapsed > maxMs) {
      state.value = 'error'
      error.value = 'Generation took too long.'
      return
    }

    try {
      const res = await fetchLesson()
      lesson.value = res
      if (isReady(res)) {
        state.value = 'ready'
        return
      }
      state.value = 'polling'
      timer = window.setTimeout(tick, intervalMs)
    } catch (e) {
      console.error(e)
      state.value = 'error'
      error.value = 'Failed to refresh lesson.'
    }
  }

  const start = async () => {
    error.value = ''
    startedAt = 0
    stop()
    state.value = 'polling'
    await tick()
  }

  onBeforeUnmount(() => stop())

  return { lesson, state, error, isPolling, isReadyState, start, stop }
}

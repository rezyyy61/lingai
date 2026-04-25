<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'
import { generateLessonReadAloud, getLessonReadAloud } from '@/api/lessonApi'

const props = defineProps<{
  lessonId: number
  variant?: 'inline' | 'sheet'
}>()

const emit = defineEmits<{
  (e: 'playing-change', value: boolean): void
  (e: 'sync-change', value: {
    activeTokenIndex: number | null
    timings: ReadAloudWordTiming[]
    chunks: ReadAloudChunk[]
    available: boolean
  }): void
}>()

type ReadAloudRes = {
  status?: 'pending' | 'processing' | 'ready' | 'failed'
  exists?: boolean
  audio_url?: string | null
  locale?: string
  voice?: string | null
  rate?: string | null
  format?: string | null
  chunk_count?: number | null
  generated_at?: string | null
  generation_version?: string | null
  current_generation_version?: string | null
  is_stale?: boolean | null
  config_snapshot?: Record<string, unknown> | null
  sync_precision?: string | null
  alignment_provider?: string | null
  alignment_note?: string | null
  chunks?: ReadAloudChunk[] | null
  word_timestamps?: ReadAloudWordTiming[] | null
  timings?: ReadAloudWordTiming[] | null
}

type ReadAloudWordTiming = {
  text: string
  start: number
  end: number
  start_char: number | null
  end_char: number | null
  chunk_index: number
}

type ReadAloudChunk = {
  index: number
  text?: string
  spoken_text?: string | null
  word_timestamps?: ReadAloudWordTiming[] | null
}

type RenderSegment =
  | { type: 'text'; text: string }
  | { type: 'token'; text: string; tokenIndex: number }

const variant = computed(() => props.variant ?? 'inline')
const isLoading = ref(false)
const error = ref('')
const playbackRate = ref(1)
const playbackRateOptions = [0.75, 0.9, 1]
const state = ref<ReadAloudRes>({
  status: 'pending',
  exists: false,
  audio_url: null,
})
const audioElement = ref<HTMLAudioElement | null>(null)
const isPlaying = ref(false)
const activeTokenIndex = ref<number | null>(null)
let pollingTimer: number | null = null
let animationFrameId: number | null = null

const status = computed(() => state.value.status ?? 'pending')
const audioUrl = computed(() => {
  const value = state.value.audio_url
  return typeof value === 'string' && value.trim() !== '' ? value : null
})
const canGenerate = computed(() => !isLoading.value && status.value !== 'processing')
const isReady = computed(() => status.value === 'ready' && !!audioUrl.value)
const isStale = computed(() => state.value.is_stale === true)
const wordTimings = computed<ReadAloudWordTiming[]>(() => {
  const direct = Array.isArray(state.value.timings) ? state.value.timings : state.value.word_timestamps

  if (!Array.isArray(direct)) {
    return []
  }

  return direct
    .filter((timing): timing is ReadAloudWordTiming => (
      typeof timing?.text === 'string'
      && typeof timing?.start === 'number'
      && typeof timing?.end === 'number'
      && typeof timing?.chunk_index === 'number'
    ))
    .sort((a, b) => a.start - b.start)
})
const timedChunks = computed(() => {
  if (!Array.isArray(state.value.chunks)) {
    return []
  }

  let tokenOffset = 0

  return state.value.chunks
    .filter((chunk): chunk is ReadAloudChunk => typeof chunk?.index === 'number')
    .map((chunk) => {
      const chunkTokens = wordTimings.value.filter((timing) => timing.chunk_index === chunk.index)
      const spokenText = typeof chunk.spoken_text === 'string' && chunk.spoken_text.trim() !== ''
        ? chunk.spoken_text
        : (typeof chunk.text === 'string' ? chunk.text : '')
      const segments = buildRenderSegments(spokenText, chunkTokens, tokenOffset)
      tokenOffset += chunkTokens.length

      return {
        ...chunk,
        spokenText,
        chunkTokens,
        segments,
      }
    })
})
const hasTimingHighlight = computed(() => timedChunks.value.some((chunk) => chunk.segments.length > 0))

const wrapperClass = computed(() => {
  if (variant.value === 'sheet') {
    return 'rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-3'
  }
  return 'rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4'
})

watch(
  () => isPlaying.value,
  (v) => emit('playing-change', v),
  { immediate: true },
)

watch(
  () => [activeTokenIndex.value, wordTimings.value, timedChunks.value, hasTimingHighlight.value] as const,
  () => {
    emit('sync-change', {
      activeTokenIndex: activeTokenIndex.value,
      timings: wordTimings.value,
      chunks: state.value.chunks ?? [],
      available: hasTimingHighlight.value,
    })
  },
  { immediate: true, deep: true },
)

const destroyAudio = () => {
  stopAnimationLoop()
  if (!audioElement.value) return
  audioElement.value.pause()
  isPlaying.value = false
  activeTokenIndex.value = null
}

const stopPolling = () => {
  if (pollingTimer !== null) {
    window.clearTimeout(pollingTimer)
    pollingTimer = null
  }
}

const applyPlaybackRate = () => {
  if (audioElement.value) {
    audioElement.value.playbackRate = playbackRate.value
  }
}

const setPlaybackRate = (rate: number) => {
  playbackRate.value = rate
  applyPlaybackRate()
}

const formattedGeneratedAt = computed(() => {
  const raw = state.value.generated_at

  if (typeof raw !== 'string' || raw.trim() === '') {
    return ''
  }

  const date = new Date(raw)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  return date.toLocaleString()
})

const refreshState = async () => {
  const res = (await getLessonReadAloud(props.lessonId)) as ReadAloudRes
  state.value = res
  return res
}

const handlePlay = () => {
  isPlaying.value = true
  startAnimationLoop()
}

const handlePause = () => {
  isPlaying.value = false
  stopAnimationLoop()
}

const handleEnded = () => {
  isPlaying.value = false
  stopAnimationLoop()
  activeTokenIndex.value = null
}

const pollUntilComplete = async () => {
  try {
    const latest = await refreshState()

    if (latest.status === 'ready' || latest.status === 'failed') {
      isLoading.value = false

      if (latest.status === 'failed') {
        error.value = 'Failed to generate read-aloud audio.'
      }

      stopPolling()
      return
    }

    pollingTimer = window.setTimeout(() => {
      void pollUntilComplete()
    }, 2200)
  } catch (e) {
    console.error(e)
    isLoading.value = false
    stopPolling()
    error.value = 'Failed to refresh read-aloud status.'
  }
}

const generateReadAloud = async () => {
  if (!canGenerate.value) return

  error.value = ''
  isLoading.value = true

  try {
    await generateLessonReadAloud(props.lessonId)
    const latest = await refreshState()

    if (latest.status === 'processing') {
      stopPolling()
      await pollUntilComplete()
      return
    }

    isLoading.value = false
  } catch (e: any) {
    console.error(e)
    isLoading.value = false
    error.value = e?.response?.data?.errors?.lesson?.[0]
      ?? e?.response?.data?.message
      ?? 'Failed to generate read-aloud audio.'
  }
}

watch(
  () => props.lessonId,
  async () => {
    destroyAudio()
    error.value = ''
    stopPolling()
    state.value = {
      status: 'pending',
      exists: false,
      audio_url: null,
    }

    try {
      const latest = await refreshState()

      if (latest.status === 'processing') {
        isLoading.value = true
        void pollUntilComplete()
        return
      }
    } catch (e) {
      console.error(e)
      error.value = 'Failed to load read-aloud audio.'
    }

    isLoading.value = false
  },
)

watch(
  () => playbackRate.value,
  () => {
    applyPlaybackRate()
  },
)

watch(
  () => audioUrl.value,
  () => {
    isPlaying.value = false
    activeTokenIndex.value = null
    stopAnimationLoop()
  },
)

onMounted(async () => {
  try {
    const latest = await refreshState()

    if (latest.status === 'processing') {
      isLoading.value = true
      void pollUntilComplete()
    }
  } catch (e) {
    console.error(e)
    error.value = 'Failed to load read-aloud audio.'
  }
})

onBeforeUnmount(() => {
  destroyAudio()
  stopPolling()
})

const buildRenderSegments = (text: string, tokens: ReadAloudWordTiming[], tokenOffset: number): RenderSegment[] => {
  if (text.trim() === '' || tokens.length === 0) {
    return text === '' ? [] : [{ type: 'text', text }]
  }

  const segments: RenderSegment[] = []
  let cursor = 0

  tokens.forEach((token, index) => {
    const startChar = typeof token.start_char === 'number' ? token.start_char : null
    const endChar = typeof token.end_char === 'number' ? token.end_char : null

    if (startChar === null || endChar === null || startChar < cursor || endChar > text.length || endChar <= startChar) {
      return
    }

    if (startChar > cursor) {
      segments.push({
        type: 'text',
        text: text.slice(cursor, startChar),
      })
    }

    segments.push({
      type: 'token',
      text: text.slice(startChar, endChar),
      tokenIndex: tokenOffset + index,
    })
    cursor = endChar
  })

  if (cursor < text.length) {
    segments.push({
      type: 'text',
      text: text.slice(cursor),
    })
  }

  return segments.length > 0 ? segments : [{ type: 'text', text }]
}

const findActiveTokenIndex = (currentTime: number): number | null => {
  const timings = wordTimings.value

  if (timings.length === 0) {
    return null
  }

  let low = 0
  let high = timings.length - 1

  while (low <= high) {
    const mid = Math.floor((low + high) / 2)
    const token = timings[mid]

    if (!token) {
      return null
    }

    if (currentTime < token.start) {
      high = mid - 1
      continue
    }

    if (currentTime >= token.end) {
      low = mid + 1
      continue
    }

    return mid
  }

  return null
}

const updateHighlight = () => {
  if (!audioElement.value || !isPlaying.value) {
    return
  }

  activeTokenIndex.value = findActiveTokenIndex(audioElement.value.currentTime)
  animationFrameId = window.requestAnimationFrame(updateHighlight)
}

const stopAnimationLoop = () => {
  if (animationFrameId !== null) {
    window.cancelAnimationFrame(animationFrameId)
    animationFrameId = null
  }
}

const startAnimationLoop = () => {
  stopAnimationLoop()

  if (!audioElement.value || wordTimings.value.length === 0) {
    return
  }

  updateHighlight()
}
</script>

<template>
  <div :class="wrapperClass">
    <div class="flex items-center justify-between gap-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-3">
      <div class="min-w-0">
        <p class="text-sm font-semibold text-[var(--app-text)]">Read Aloud</p>
        <p class="mt-1 text-xs text-[var(--app-text-muted)]">
          {{ status === 'ready'
            ? isStale
              ? 'Read-aloud settings changed. Regenerate for improved audio.'
              : 'Natural read-aloud audio is ready.'
            : status === 'processing'
              ? 'Generating read-aloud audio...'
              : 'Generate natural audio from the original lesson text.' }}
        </p>
      </div>

      <button
        type="button"
        class="inline-flex items-center justify-center gap-2 rounded-full bg-[var(--app-accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--app-accent-strong)] disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="!canGenerate"
        @click="generateReadAloud"
      >
        <Icon
          v-if="isLoading || status === 'processing'"
          icon="svg-spinners:90-ring-with-bg"
          class="h-4 w-4"
        />
        <Icon
          v-else
          icon="solar:play-circle-bold-duotone"
          class="h-4 w-4"
        />
        <span>{{ status === 'ready' ? (isStale ? 'Update' : 'Regenerate') : 'Generate' }}</span>
      </button>
    </div>

    <div
      v-if="error"
      class="mt-3 flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-medium text-red-600 border border-red-100 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-400"
    >
      <Icon icon="solar:danger-triangle-bold" class="h-4 w-4 shrink-0" />
      {{ error }}
    </div>

    <div v-if="isReady && audioUrl" class="mt-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-4">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-xs text-[var(--app-text-muted)]">
          <span v-if="state.chunk_count">Chunks: {{ state.chunk_count }}</span>
          <span v-if="state.voice">Voice: {{ state.voice }}</span>
          <span v-if="formattedGeneratedAt">{{ formattedGeneratedAt }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs text-[var(--app-text-muted)]">Playback speed</span>
          <button
            v-for="rate in playbackRateOptions"
            :key="rate"
            type="button"
            class="rounded-full border px-3 py-1 text-xs font-medium transition"
            :class="playbackRate === rate
              ? 'border-[var(--app-accent)] bg-[var(--app-accent)]/15 text-[var(--app-accent)]'
              : 'border-[var(--app-border)] bg-transparent text-[var(--app-text-muted)] hover:text-[var(--app-text)]'"
            @click="setPlaybackRate(rate)"
          >
            {{ rate }}x
          </button>
        </div>
      </div>

      <audio
        ref="audioElement"
        class="w-full"
        controls
        preload="metadata"
        :src="audioUrl"
        @loadedmetadata="applyPlaybackRate"
        @play="handlePlay"
        @pause="handlePause"
        @ended="handleEnded"
      />

    </div>

    <div
      v-else
      class="mt-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-3 text-xs text-[var(--app-text-muted)]"
    >
      {{ status === 'processing'
        ? 'Read-aloud audio is being generated. It will appear here automatically when ready.'
        : status === 'failed'
          ? 'Read-aloud generation failed. Try again.'
          : 'No read-aloud audio generated yet.' }}
    </div>
  </div>
</template>

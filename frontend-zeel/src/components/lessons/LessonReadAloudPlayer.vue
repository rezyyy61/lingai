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
}

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
let pollingTimer: number | null = null

const status = computed(() => state.value.status ?? 'pending')
const audioUrl = computed(() => {
  const value = state.value.audio_url
  return typeof value === 'string' && value.trim() !== '' ? value : null
})
const canGenerate = computed(() => !isLoading.value && status.value !== 'processing')
const isReady = computed(() => status.value === 'ready' && !!audioUrl.value)
const isStale = computed(() => state.value.is_stale === true)

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

const destroyAudio = () => {
  if (!audioElement.value) return
  audioElement.value.pause()
  isPlaying.value = false
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
}

const handlePause = () => {
  isPlaying.value = false
}

const handleEnded = () => {
  isPlaying.value = false
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

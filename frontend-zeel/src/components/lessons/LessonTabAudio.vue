<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { fetchLesson, generateLessonAudio } from '@/api/lessonApi'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{
  lesson: LessonDetail
}>()

const currentLesson = ref<LessonDetail>(props.lesson)
const isSubmitting = ref(false)
const error = ref('')
const audioElement = ref<HTMLAudioElement | null>(null)
const playbackRate = ref(1)
const playbackRateOptions = [0.75, 0.9, 1]
let pollingTimer: number | null = null

const audioStatus = computed(() => currentLesson.value.analysis_meta?.audio_generation?.status ?? null)
const spokenSegments = computed(() => {
  const raw = currentLesson.value.analysis_meta?.audio_script?.spoken_segments

  if (!Array.isArray(raw)) {
    return []
  }

  return raw.filter((segment): segment is { type: string; speaker: string; style: string; pause_ms: number; text: string } => (
    !!segment
    && typeof segment === 'object'
    && typeof segment.type === 'string'
    && typeof segment.speaker === 'string'
    && typeof segment.style === 'string'
    && typeof segment.pause_ms === 'number'
    && typeof segment.text === 'string'
    && segment.text.trim().length > 0
  ))
})
const transcriptPreview = computed(() => {
  if (spokenSegments.value.length > 0) {
    return spokenSegments.value
      .map((segment) => `${segment.speaker}: ${segment.text.trim()}`)
      .join('\n\n')
      .trim()
  }

  return ((currentLesson.value.analysis_meta?.audio_script?.spoken_script as string | undefined | null) ?? '').trim()
})
const scriptReady = computed(() => transcriptPreview.value.length > 0)
const audioReady = computed(() => audioStatus.value === 'ready' && ((currentLesson.value.audio_url ?? '').trim().length > 0))
const hasOriginalText = computed(() => ((currentLesson.value.original_text ?? '').trim().length > 0))

const buttonLabel = computed(() => {
  if (audioStatus.value === 'processing') return 'Generating audio...'
  if (audioReady.value) return 'Regenerate audio'
  return 'Generate audio'
})

const titleText = computed(() => {
  if (audioReady.value) return 'Your lesson audio is ready'
  if (audioStatus.value === 'processing') return 'Preparing your lesson audio'
  return 'Create lesson audio'
})

const descriptionText = computed(() => {
  if (audioReady.value) {
    return 'The spoken lesson has been generated and converted into an audio file. You can play it directly here.'
  }

  if (audioStatus.value === 'processing') {
    return scriptReady.value
      ? 'The spoken script is ready. We are synthesizing the audio file now.'
      : 'We are generating the spoken script first and will create the audio file immediately after.'
  }

  return 'Use one button for the full flow. If the lesson does not have a spoken script yet, the app will generate it and then create the final audio file.'
})

const generatedAtText = computed(() => {
  const raw = currentLesson.value.analysis_meta?.audio_generation?.generated_at

  if (typeof raw !== 'string' || raw.trim() === '') {
    return ''
  }

  const date = new Date(raw)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  return date.toLocaleString()
})

const canGenerate = computed(() => hasOriginalText.value && audioStatus.value !== 'processing' && !isSubmitting.value)

const speedLabel = (rate: number) => `${rate}x`

const applyPlaybackRate = () => {
  if (audioElement.value) {
    audioElement.value.playbackRate = playbackRate.value
  }
}

const setPlaybackRate = (rate: number) => {
  playbackRate.value = rate
  applyPlaybackRate()
}

const stopPolling = () => {
  if (pollingTimer !== null) {
    window.clearTimeout(pollingTimer)
    pollingTimer = null
  }
}

const refreshLesson = async () => {
  const latest = await fetchLesson(currentLesson.value.id)
  currentLesson.value = latest
  return latest
}

const pollUntilComplete = async () => {
  try {
    const latest = await refreshLesson()
    const status = latest.analysis_meta?.audio_generation?.status ?? null

    if (status === 'ready' || status === 'failed') {
      isSubmitting.value = false

      if (status === 'failed') {
        error.value = 'Audio generation failed. Try again.'
      }

      stopPolling()
      return
    }

    pollingTimer = window.setTimeout(() => {
      void pollUntilComplete()
    }, 2200)
  } catch (err) {
    console.error(err)
    stopPolling()
    isSubmitting.value = false
    error.value = 'Could not refresh lesson audio status.'
  }
}

const handleGenerate = async () => {
  if (!canGenerate.value) return

  error.value = ''
  isSubmitting.value = true

  try {
    await generateLessonAudio(currentLesson.value.id)
    const latest = await refreshLesson()

    if ((latest.analysis_meta?.audio_generation?.status ?? null) === 'processing') {
      stopPolling()
      await pollUntilComplete()
      return
    }

    isSubmitting.value = false
  } catch (err: any) {
    console.error(err)
    isSubmitting.value = false

    error.value = err?.response?.data?.errors?.lesson?.[0]
      ?? err?.response?.data?.message
      ?? 'Could not start lesson audio generation.'
  }
}

watch(
  () => props.lesson,
  (lesson) => {
    currentLesson.value = lesson
    error.value = ''
    stopPolling()

    if ((lesson.analysis_meta?.audio_generation?.status ?? null) === 'processing') {
      isSubmitting.value = true
      void pollUntilComplete()
      return
    }

    isSubmitting.value = false
  },
  { immediate: true, deep: true },
)

watch(playbackRate, () => {
  applyPlaybackRate()
})

watch(
  () => currentLesson.value.audio_url,
  () => {
    window.setTimeout(() => {
      applyPlaybackRate()
    }, 0)
  },
)

onBeforeUnmount(() => {
  stopPolling()
})
</script>

<template>
  <section class="flex h-full flex-col text-[var(--app-text)]">
    <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar px-1">
      <div class="rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface)] p-5 shadow-sm md:p-6">
        <div class="rounded-[24px] border border-[var(--app-border)] bg-[linear-gradient(180deg,rgba(255,255,255,0.03),rgba(255,255,255,0.01))] p-5">
          <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="space-y-2">
              <p class="text-xs font-semibold uppercase tracking-widest text-[var(--app-accent)]">
                Lesson Audio
              </p>
              <h3 class="text-xl font-semibold">
                {{ titleText }}
              </h3>
              <p class="max-w-2xl text-sm leading-7 text-[var(--app-text-muted)]">
                {{ descriptionText }}
              </p>
            </div>

            <button
              type="button"
              class="inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-semibold text-white shadow-lg transition active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
              :class="audioReady ? 'bg-[var(--app-accent-secondary)] shadow-[var(--app-accent-secondary)]/30' : 'bg-[var(--app-accent)] shadow-[var(--app-accent)]/30'"
              :disabled="!canGenerate"
              @click="handleGenerate"
            >
              <Icon
                v-if="audioStatus === 'processing' || isSubmitting"
                icon="svg-spinners:90-ring-with-bg"
                class="h-4 w-4"
              />
              <Icon
                v-else
                icon="solar:music-note-3-bold-duotone"
                class="h-4 w-4"
              />
              <span>{{ buttonLabel }}</span>
            </button>
          </div>

          <div
            v-if="error"
            class="mt-4 rounded-2xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-300"
          >
            {{ error }}
          </div>

          <div v-if="scriptReady" class="mt-6">
            <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-[var(--app-text-muted)]">
              Transcript
            </p>
            <div class="custom-scrollbar mt-3 max-h-[260px] overflow-y-auto rounded-[20px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 text-sm leading-7 text-[var(--app-text-muted)]">
              <pre class="whitespace-pre-wrap font-sans">{{ transcriptPreview }}</pre>
            </div>
          </div>

          <div class="mt-6">
            <div class="flex items-center justify-between gap-3">
              <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-[var(--app-text-muted)]">
                Audio Player
              </p>
              <span v-if="generatedAtText" class="text-xs text-[var(--app-text-muted)]">
                {{ generatedAtText }}
              </span>
            </div>

            <div
              v-if="audioReady && currentLesson.audio_url"
              class="mt-3 rounded-[20px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4"
            >
              <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-[var(--app-text-muted)]">Speed</span>
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
                  {{ speedLabel(rate) }}
                </button>
              </div>
              <audio
                ref="audioElement"
                class="w-full"
                controls
                preload="metadata"
                :src="currentLesson.audio_url"
                @loadedmetadata="applyPlaybackRate"
              />
            </div>

            <div
              v-else
              class="mt-3 rounded-[20px] border border-dashed border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-6 text-sm text-[var(--app-text-muted)]"
            >
              {{ audioStatus === 'processing'
                ? 'Audio is being generated. It will appear here automatically when ready.'
                : 'No lesson audio yet.' }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: var(--app-border);
  border-radius: 999px;
}
</style>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import type { LessonDetail } from '@/types/lesson'
import { submitSpeakingPractice, type SpeakingSubmitResponse } from '@/api/speaking'

const props = defineProps<{
  lesson: LessonDetail
}>()

const isRecording = ref(false)
const isSubmitting = ref(false)
const error = ref<string | null>(null)
const feedback = ref<SpeakingSubmitResponse | null>(null)
const mediaRecorder = ref<MediaRecorder | null>(null)
const chunks: BlobPart[] = []
const targetLanguage = ref<string | null>(null)
const prompt = ref('')

const shadowAudio = ref<HTMLAudioElement | null>(null)
const isPlayingShadow = ref(false)

const ensureTargetLanguage = () => {
  if (targetLanguage.value) return targetLanguage.value
  if (props.lesson.language_code) return props.lesson.language_code
  return 'en'
}

const startRecording = async () => {
  if (isRecording.value || isSubmitting.value) return
  error.value = null

  if (typeof navigator === 'undefined' || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    error.value =
      'Your browser does not support microphone recording. Please use a modern browser (Chrome, Edge, Safari) over HTTPS.'
    return
  }

  if (typeof MediaRecorder === 'undefined') {
    error.value =
      'Microphone recording is not available in this browser. Please try the latest version of Chrome, Edge, or Safari.'
    return
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    const recorder = new MediaRecorder(stream)
    chunks.length = 0

    recorder.ondataavailable = (e: BlobEvent) => {
      if (e.data && e.data.size > 0) {
        chunks.push(e.data)
      }
    }

    recorder.onstop = () => {
      stream.getTracks().forEach((t) => t.stop())
      void submitRecording()
    }

    mediaRecorder.value = recorder
    recorder.start()
    isRecording.value = true
  } catch (err: any) {
    const name = err?.name || ''
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
      error.value =
        'Microphone permission was denied. Please allow microphone access in your browser and try again.'
    } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      error.value = 'No microphone device was found. Please connect a microphone and try again.'
    } else {
      error.value =
        'Could not access microphone. Please check browser permissions, HTTPS, and try again.'
    }
  }
}

const stopRecording = () => {
  if (!isRecording.value || !mediaRecorder.value) return
  mediaRecorder.value.stop()
  isRecording.value = false
}

const submitRecording = async () => {
  if (!chunks.length) {
    error.value = 'No audio captured.'
    return
  }

  const blob = new Blob(chunks, { type: 'audio/webm' })
  const file = new File([blob], 'speaking.webm', { type: 'audio/webm' })

  isSubmitting.value = true
  error.value = null

  try {
    const res = await submitSpeakingPractice({
      audio: file,
      target_language: ensureTargetLanguage(),
      prompt: prompt.value || props.lesson.title || '',
    })
    feedback.value = res
  } catch (e: any) {
    const status = e?.response?.status ?? null

    const data = e?.response?.data
    const serverMsg =
      data?.message ||
      data?.error ||
      (typeof data === 'string' ? data.slice(0, 300) : '') ||
      null

    console.error('Speaking submit failed', {
      status,
      data,
      headers: e?.response?.headers,
      message: e?.message,
    })

    if (status === 422) {
      error.value = serverMsg || 'Could not recognize speech.'
    } else if (status === 401) {
      error.value = 'Unauthorized. Please login again.'
    } else if (status === 419) {
      error.value = 'Session expired / CSRF (419). Refresh and try again.'
    } else if (status === 413) {
      error.value = 'Audio file is too large. Try a shorter recording.'
    } else if (status === 404) {
      error.value = 'API endpoint not found (404). Check Vite proxy and Laravel routes.'
    } else if (status) {
      error.value = `Server error (${status}): ${serverMsg || 'Request failed.'}`
    } else {
      error.value = `Network error: ${e?.message || 'Request failed.'}`
    }
  } finally {
    isSubmitting.value = false
  }
}

const playShadowAudio = async () => {
  if (!feedback.value?.audio_url) return

  try {
    if (!shadowAudio.value) {
      shadowAudio.value = new Audio(feedback.value.audio_url)
      shadowAudio.value.onended = () => {
        isPlayingShadow.value = false
      }
    }

    if (isPlayingShadow.value) {
      shadowAudio.value.pause()
      shadowAudio.value.currentTime = 0
      isPlayingShadow.value = false
      return
    }

    await shadowAudio.value.play()
    isPlayingShadow.value = true
  } catch {
    isPlayingShadow.value = false
  }
}
</script>

<template>
  <section class="flex h-full w-full flex-col text-[var(--app-text)]">
    <header class="flex items-center justify-between gap-3 px-1 pb-3 border-b border-[var(--app-border)]/60">
      <div class="space-y-0.5">
        <p class="text-xs font-semibold font-display tracking-wider uppercase text-[var(--app-accent)]">
          Speaking Practice
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)]">
          Record yourself and get instant feedback.
        </p>
      </div>
    </header>

    <div class="flex flex-1 flex-col gap-4 p-3 md:p-4 overflow-y-auto custom-scrollbar">
      <div class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
          <div class="space-y-1">
            <p class="text-xs font-semibold text-[var(--app-text-muted)] uppercase tracking-widest">
              Microphone
            </p>
            <p class="text-sm font-medium">
              Tap to {{ isRecording ? 'stop' : 'start' }} recording
            </p>
          </div>
          <button
            type="button"
            class="inline-flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm transition active:scale-95 disabled:opacity-50"
            :class="isRecording ? 'bg-red-500/10 border-red-500/40 text-red-500' : ''"
            :disabled="isSubmitting"
            @click="isRecording ? stopRecording() : startRecording()"
          >
            <Icon
              :icon="isRecording ? 'solar:stop-bold-duotone' : 'solar:microphone-large-bold-duotone'"
              class="h-6 w-6"
            />
          </button>
        </div>

        <p class="text-[11px] text-[var(--app-text-muted)]">
          We’ll automatically analyze your speech, correct it, and generate slow audio so you can shadow the correct version.
        </p>

        <div class="flex flex-col gap-2 mt-1">
          <label class="text-[11px] font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
            Optional prompt / context
          </label>
          <textarea
            v-model="prompt"
            class="min-h-[60px] resize-none rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 text-xs outline-none focus:ring-1 focus:ring-[var(--app-accent)] focus:border-[var(--app-accent)]"
            placeholder="E.g. Introduce yourself, describe your day, answer a question from the lesson…"
          />
        </div>
      </div>

      <div v-if="isSubmitting" class="flex items-center gap-3 text-xs text-[var(--app-text-muted)]">
        <Icon icon="svg-spinners:90-ring-with-bg" class="h-4 w-4 text-[var(--app-accent)]" />
        Analyzing your speaking sample…
      </div>

      <div
        v-if="error"
        class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-600 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-400"
      >
        {{ error }}
      </div>

      <div
        v-if="feedback"
        class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 space-y-4"
      >
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
              Result
            </p>
            <p class="mt-1 text-sm font-medium">
              Score: <span class="font-semibold text-[var(--app-accent)]">{{ feedback.feedback.score }}</span> / 100
            </p>
            <p v-if="feedback.confidence != null" class="mt-0.5 text-[11px] text-[var(--app-text-muted)]">
              STT confidence: {{ Math.round((feedback.confidence || 0) * 100) }}%
            </p>
          </div>
          <button
            v-if="feedback.audio_url"
            type="button"
            class="inline-flex items-center gap-2 rounded-full bg-[var(--app-accent)] px-4 py-2 text-xs font-semibold text-white shadow-md shadow-[var(--app-accent)]/30 active:scale-95 transition"
            @click="playShadowAudio"
          >
            <Icon :icon="isPlayingShadow ? 'solar:pause-circle-bold-duotone' : 'solar:play-circle-bold-duotone'" class="h-4 w-4" />
            <span>{{ isPlayingShadow ? 'Stop shadowing audio' : 'Play slow audio' }}</span>
          </button>
        </div>

        <div class="space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
            What you said
          </p>
          <p class="rounded-xl bg-[var(--app-surface-elevated)] px-3 py-2 text-sm">
            {{ feedback.spoken }}
          </p>
        </div>

        <div class="space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
            Corrected / natural version
          </p>
          <p class="rounded-xl bg-[var(--app-surface-elevated)] px-3 py-2 text-sm font-medium">
            {{ feedback.feedback.corrected || feedback.spoken }}
          </p>
        </div>

        <div v-if="feedback.feedback.suggested_answer" class="space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
            Suggested answer
          </p>
          <p class="rounded-xl bg-[var(--app-surface-elevated)] px-3 py-2 text-sm">
            {{ feedback.feedback.suggested_answer }}
          </p>
        </div>

        <div v-if="feedback.feedback.notes?.length" class="space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-widest text-[var(--app-text-muted)]">
            Notes
          </p>
          <ul class="space-y-1.5 text-sm list-disc pl-5">
            <li v-for="(note, idx) in feedback.feedback.notes" :key="idx">
              {{ note }}
            </li>
          </ul>
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

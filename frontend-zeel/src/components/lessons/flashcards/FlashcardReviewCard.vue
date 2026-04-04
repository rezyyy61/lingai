<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import { fetchLessonWordTts } from '@/api/lessonFlashcards'

const props = defineProps<{
  wordId: number
  term: string
  phonetic?: string | null
  meaning?: string | null
  translation?: string | null
  exampleSentence?: string | null
  revealed: boolean
}>()

const isLoadingAudio = ref(false)
const isPlaying = ref(false)
const audioUrl = ref<string | null>(null)
let audio: HTMLAudioElement | null = null

async function handlePlayClick(event: MouseEvent) {
  event.stopPropagation()

  if (!audioUrl.value) {
    try {
      isLoadingAudio.value = true
      audioUrl.value = await fetchLessonWordTts(props.wordId)
      audio = new Audio(audioUrl.value)
    } catch {
      return
    } finally {
      isLoadingAudio.value = false
    }
  }

  if (!audio && audioUrl.value) {
    audio = new Audio(audioUrl.value)
  }

  if (!audio) return

  if (isPlaying.value) {
    audio.pause()
    audio.currentTime = 0
    isPlaying.value = false
    return
  }

  try {
    await audio.play()
    isPlaying.value = true
    audio.onended = () => {
      isPlaying.value = false
      if (audio) {
        audio.currentTime = 0
      }
    }
  } catch {
    isPlaying.value = false
  }
}
</script>

<template>
  <div class="relative flex items-center justify-center w-full py-4">
    <div class="relative w-full max-w-[340px] aspect-[3/4.2] max-h-[70vh] sm:h-[55vh] sm:w-full sm:max-w-md sm:aspect-[3/4] select-none">
      <div class="absolute inset-0 bg-[var(--app-surface-elevated)] rounded-[32px] border border-[var(--app-border)] opacity-40 scale-90 translate-y-4 -z-20"></div>
      <div class="absolute inset-0 bg-[var(--app-surface-elevated)] rounded-[32px] border border-[var(--app-border)] opacity-70 scale-95 translate-y-2 -z-10"></div>

      <div class="absolute inset-0 rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-6 text-[var(--app-text)] shadow-xl sm:px-8 sm:py-10 dark:border-white/5 dark:bg-[#1e1e20] dark:shadow-2xl overflow-y-auto custom-scrollbar">
        <div class="flex h-full flex-col justify-between">
          <div class="flex items-center justify-between">
            <span class="rounded-full bg-[var(--app-surface)] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">
              {{ revealed ? 'Answer' : 'Prompt' }}
            </span>
            <button
              type="button"
              class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] shadow-sm transition active:scale-95 disabled:opacity-50"
              :disabled="isLoadingAudio"
              @click.stop="handlePlayClick"
            >
              <Icon
                v-if="!isLoadingAudio && !isPlaying"
                icon="solar:soundwave-bold-duotone"
                class="h-5 w-5"
              />
              <Icon
                v-else-if="isPlaying"
                icon="solar:pause-circle-bold-duotone"
                class="h-5 w-5"
              />
              <Icon
                v-else
                icon="svg-spinners:90-ring-with-bg"
                class="h-5 w-5"
              />
            </button>
          </div>

          <div class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
            <p class="font-display text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl break-words">
              {{ term }}
            </p>
            <p v-if="phonetic" class="font-mono text-lg text-[var(--app-accent-secondary)] sm:text-xl">
              {{ phonetic }}
            </p>
          </div>

          <div v-if="revealed" class="space-y-4 pt-4">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">Meaning</p>
              <p class="mt-2 text-base leading-relaxed text-[var(--app-text)]">{{ meaning || '—' }}</p>
            </div>

            <div v-if="translation">
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">Translation</p>
              <p class="mt-2 text-base leading-relaxed text-[var(--app-text)]">{{ translation }}</p>
            </div>

            <div v-if="exampleSentence">
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">Example</p>
              <p class="mt-2 text-sm leading-relaxed text-[var(--app-text)]">{{ exampleSentence }}</p>
            </div>
          </div>

          <div v-else class="pt-4 text-center text-xs font-medium text-[var(--app-text-muted)] uppercase tracking-widest opacity-60">
            Think first, then reveal the answer
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

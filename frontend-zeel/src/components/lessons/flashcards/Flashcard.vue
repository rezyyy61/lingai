<script setup lang="ts">
import { ref } from 'vue'
import { fetchLessonWordTts } from '@/api/lessonFlashcards'

const props = defineProps<{
  wordId: number
  term: string
  meaning: string
  translation: string
  exampleSentence?: string | null
  phonetic?: string | null
  partOfSpeech?: string | null
}>()

const isFlipped = ref(false)
const isLoadingAudio = ref(false)
const isPlaying = ref(false)
const audioUrl = ref<string | null>(null)
let audio: HTMLAudioElement | null = null

function toggle() {
  isFlipped.value = !isFlipped.value
}

async function handlePlayClick(event: MouseEvent) {
  event.stopPropagation()

  if (!audioUrl.value) {
    try {
      isLoadingAudio.value = true
      const url = await fetchLessonWordTts(props.wordId)
      audioUrl.value = url
      audio = new Audio(url)
    } catch {
    } finally {
      isLoadingAudio.value = false
    }
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
  <div
    class="relative w-full aspect-[3/4] cursor-pointer select-none transition-transform duration-500 [transform-style:preserve-3d] sm:aspect-[4/3]"
    :class="isFlipped ? 'rotate-y-180' : ''"
    @click="toggle"
  >
    <!-- FRONT -->
    <div
      class="absolute inset-0 rounded-[24px] border border-[var(--app-border)] bg-gradient-to-br from-[var(--app-panel)] via-[var(--app-surface-elevated)] to-[var(--app-panel)] px-6 py-6 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] [backface-visibility:hidden] sm:rounded-[32px] sm:px-8 sm:py-8 lg:rounded-[40px] lg:px-12 lg:py-10 dark:border-white/15 dark:bg-gradient-to-br dark:from-[var(--app-surface-dark)] dark:via-[#050505] dark:to-[var(--app-surface-dark)] dark:text-white dark:shadow-[0_40px_90px_rgba(0,0,0,0.65)]"
    >
      <div class="flex h-full flex-col justify-between">
        <div class="flex items-center justify-between text-sm text-[var(--app-text-muted)] dark:text-white/60">
          <span>Flashcard</span>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-lg text-[var(--app-text)] hover:bg-[var(--app-panel-muted)] disabled:opacity-40 dark:border-transparent dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
              :disabled="isLoadingAudio"
              @click.stop="handlePlayClick"
            >
              <span v-if="isLoadingAudio">…</span>
              <span v-else-if="isPlaying">⏸</span>
              <span v-else>🔊</span>
            </button>
            <span
              v-if="partOfSpeech"
              class="rounded-full bg-[var(--app-surface-elevated)] px-4 py-1 text-xs uppercase tracking-[0.3em] text-[var(--app-text)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
            >
              {{ partOfSpeech }}
            </span>
          </div>
        </div>

        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
          <p class="max-w-full break-words text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-6xl">
            {{ term }}
          </p>
          <p v-if="phonetic" class="text-lg text-[var(--app-accent-secondary)] sm:text-2xl">
            {{ phonetic }}
          </p>
        </div>

        <div class="pt-1 text-center text-xs text-[var(--app-text-muted)] dark:text-white/60">
          Tap to see meaning, example, and translation
        </div>
      </div>
    </div>

    <!-- BACK -->
    <div
      class="absolute inset-0 rounded-[24px] border border-[var(--app-accent-secondary)] bg-[var(--app-panel)] px-6 py-6 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] [backface-visibility:hidden] rotate-y-180 sm:rounded-[32px] sm:px-8 sm:py-8 lg:rounded-[40px] lg:px-12 lg:py-10 dark:bg-[var(--app-surface-dark)] dark:text-white dark:shadow-[0_40px_90px_rgba(0,0,0,0.65)]"
    >
      <div class="flex h-full flex-col gap-5">
        <div class="flex items-center justify-between text-sm text-[var(--app-text-muted)] dark:text-white/60">
          <span>Details</span>
          <span
            v-if="partOfSpeech"
            class="rounded-full bg-[var(--app-surface-elevated)] px-4 py-1 text-xs uppercase tracking-[0.3em] text-[var(--app-text)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
          >
            {{ partOfSpeech }}
          </span>
        </div>

        <div class="flex-1 space-y-4 text-left text-sm sm:text-[15px]">
          <div class="space-y-1">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--app-text-muted)] dark:text-white/60">
              Meaning
            </p>
            <p
              class="rounded-2xl bg-[var(--app-surface-elevated)] px-4 py-3 text-sm leading-relaxed text-[var(--app-text)] sm:text-[15px] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
            >
              {{ meaning }}
            </p>
          </div>

          <div v-if="exampleSentence" class="space-y-1">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--app-text-muted)] dark:text-white/60">
              Example
            </p>
            <p
              class="rounded-2xl bg-[var(--app-surface-elevated)] px-4 py-3 text-xs leading-relaxed italic text-[var(--app-text)] sm:text-[14px] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
            >
              “{{ exampleSentence }}”
            </p>
          </div>

          <div class="space-y-1">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--app-text-muted)] dark:text-white/60">
              Translation
            </p>
            <div
              class="inline-flex max-w-full flex-wrap items-center gap-2 rounded-full bg-[var(--app-accent-secondary-soft)] px-4 py-2"
            >
              <span class="h-2 w-2 rounded-full bg-[var(--app-accent-secondary)]" />
              <span class="break-words text-sm font-semibold text-[var(--app-accent-secondary)] sm:text-[15px]">
                {{ translation }}
              </span>
            </div>
          </div>
        </div>

        <div class="pt-1 text-center text-[11px] text-[var(--app-text-muted)] dark:text-white/60">
          Tap again to flip back
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rotate-y-180 {
  transform: rotateY(180deg);
}
</style>

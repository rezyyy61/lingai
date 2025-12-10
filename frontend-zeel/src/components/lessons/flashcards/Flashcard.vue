<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
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
      class="absolute inset-0 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-6 text-[var(--app-text)] shadow-md [backface-visibility:hidden] sm:rounded-[32px] sm:px-8 sm:py-8 lg:rounded-[40px] lg:px-12 lg:py-10 dark:border-white/10 dark:bg-[#202124] dark:text-white"
    >
      <!-- pronunciation icon -->
      <button
        type="button"
        class="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/10 text-white backdrop-blur-sm dark:bg-white/10"
        :disabled="isLoadingAudio"
        @click.stop="handlePlayClick"
      >
        <Icon
          v-if="!isLoadingAudio && !isPlaying"
          icon="solar:soundwave-bold-duotone"
          class="h-4 w-4"
        />
        <Icon
          v-else-if="isPlaying"
          icon="solar:pause-circle-bold-duotone"
          class="h-4 w-4"
        />
        <svg
          v-else
          class="h-4 w-4 animate-spin text-current"
          viewBox="0 0 24 24"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="3"
            fill="none"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 0 1 8-8v3.5a4.5 4.5 0 0 0-4.5 4.5H4Z"
          />
        </svg>
      </button>

      <div class="flex h-full flex-col justify-between">
        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
          <p class="max-w-full break-words text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-6xl">
            {{ term }}
          </p>
          <p v-if="phonetic" class="text-lg text-[var(--app-accent-secondary)] sm:text-2xl">
            {{ phonetic }}
          </p>
        </div>

        <div class="pt-1 text-center text-xs text-[var(--app-text-muted)] dark:text-white/60">
          See answer
        </div>
      </div>
    </div>

    <!-- BACK -->
    <div
      class="absolute inset-0 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-panel)] px-6 py-6 text-[var(--app-text)] shadow-md [backface-visibility:hidden] rotate-y-180 sm:rounded-[32px] sm:px-8 sm:py-8 lg:rounded-[40px] lg:px-12 lg:py-10 dark:border-white/10 dark:bg-[#202124] dark:text-white"
    >
      <div class="flex h-full flex-col gap-5">
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

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
    class="relative w-full aspect-[3/4.2] cursor-pointer select-none transition-transform duration-500 [transform-style:preserve-3d] sm:aspect-[3/4]"
    :class="isFlipped ? 'rotate-y-180' : ''"
    @click="toggle"
  >
    <!-- FRONT -->
    <div
      class="absolute inset-0 flex flex-col justify-between rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-6 text-[var(--app-text)] shadow-sm [backface-visibility:hidden] sm:px-8 sm:py-10 dark:border-white/5 dark:bg-[#1e1e20]"
    >
      <!-- pronunciation icon -->
      <button
        type="button"
        class="absolute right-5 top-5 inline-flex h-10 w-10 items-center justify-center rounded-full bg-[var(--app-surface)] text-[var(--app-text)] shadow-sm transition active:scale-95 disabled:opacity-50 dark:bg-white/10 dark:text-white"
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

      <div class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
        <div class="w-full">
          <p class="font-display text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl break-words">
            {{ term }}
          </p>
          <p v-if="phonetic" class="mt-2 font-mono text-lg text-[var(--app-accent-secondary)] sm:text-xl">
            {{ phonetic }}
          </p>
        </div>
        
        <div v-if="partOfSpeech" class="rounded-full bg-[var(--app-surface)] px-3 py-1 text-xs font-medium text-[var(--app-text-muted)] dark:bg-white/5">
          {{ partOfSpeech }}
        </div>
      </div>

      <div class="pt-2 text-center text-xs font-medium text-[var(--app-text-muted)] uppercase tracking-widest opacity-60">
        Tap to flip
      </div>
    </div>

    <!-- BACK -->
    <div
      class="absolute inset-0 rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-6 text-[var(--app-text)] shadow-sm [backface-visibility:hidden] rotate-y-180 sm:px-8 sm:py-10 dark:border-white/5 dark:bg-[#1e1e20]"
    >
      <div class="flex h-full flex-col gap-6">
        <div class="flex-1 space-y-6 text-left">
          <div class="space-y-2">
            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">
              Meaning
            </p>
            <p
              class="text-lg font-medium leading-relaxed text-[var(--app-text)] sm:text-xl"
            >
              {{ meaning }}
            </p>
          </div>

          <div v-if="exampleSentence" class="space-y-2">
            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">
              Example
            </p>
            <div class="relative pl-4 border-l-2 border-[var(--app-accent)]/30">
              <p
                class="text-sm leading-relaxed text-[var(--app-text)] sm:text-base italic"
              >
                “{{ exampleSentence }}”
              </p>
            </div>
          </div>

          <div class="space-y-2">
            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--app-text-muted)]">
              Translation
            </p>
            <div
              class="inline-flex items-center gap-2 rounded-xl bg-[var(--app-accent-secondary)]/10 px-4 py-2 text-[var(--app-accent-secondary)]"
            >
              <span class="text-sm font-semibold sm:text-base">
                {{ translation }}
              </span>
            </div>
          </div>
        </div>

        <div class="text-center text-xs font-medium text-[var(--app-text-muted)] uppercase tracking-widest opacity-60">
          Tap to flip back
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

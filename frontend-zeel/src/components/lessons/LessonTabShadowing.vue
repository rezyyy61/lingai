<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import type { LessonDetail } from '@/types/lesson'
import { useLessonShadowing } from '@/composables/useLessonShadowing'
import { fetchLessonSentenceTts } from '@/api/lessonShadowing'
import GenerateShadowingModal from './GenerateShadowingModal.vue'

const props = defineProps<{
  lesson: LessonDetail
}>()

const {
  sentences,
  activeSentence,
  activeIndex,
  total,
  isLoading,
  isError,
  isEmpty,
  isReady,
  hasPrev,
  hasNext,
  goPrev,
  goNext,
  reload,
} = useLessonShadowing(props.lesson.id)

const progressLabel = computed(() =>
  total.value === 0 ? '0 / 0' : `${activeIndex.value + 1} / ${total.value}`,
)
const progressPercent = computed(() =>
  total.value === 0 ? 0 : Math.round(((activeIndex.value + 1) / total.value) * 100),
)
const sentencesSignature = computed(() => sentences.value.map((sentence) => sentence.id).join('-'))
const showTranslation = ref(false)

const audioUrls = ref<Record<number, string>>({})
const isAudioLoading = ref(false)
const isAudioPlaying = ref(false)
const playbackRate = ref(1)
const playbackRateOptions = [0.75, 1, 1.25]

let audio: HTMLAudioElement | null = null

const showGenerateModal = ref(false)
const isGenerationPending = ref(false)
const toastMessage = ref('')
const pendingBaselineSignature = ref<string | null>(null)
let toastTimeout: number | null = null
let pollingInterval: number | null = null
const isFocusMode = ref(false)

async function ensureAudio(sentenceId: number): Promise<string | null> {
  if (audioUrls.value[sentenceId]) {
    return audioUrls.value[sentenceId]
  }
  try {
    isAudioLoading.value = true
    const url = await fetchLessonSentenceTts(sentenceId)
    audioUrls.value = {
      ...audioUrls.value,
      [sentenceId]: url,
    }
    return url
  } catch {
    return null
  } finally {
    isAudioLoading.value = false
  }
}

async function handlePlayClick() {
  const sentence = activeSentence.value
  if (!sentence) return

  if (audio && isAudioPlaying.value) {
    audio.pause()
    audio.currentTime = 0
    isAudioPlaying.value = false
    return
  }

  const url = await ensureAudio(sentence.id)
  if (!url) return

  if (!audio || audio.src !== url) {
    audio = new Audio(url)
  }

  audio.playbackRate = playbackRate.value

  try {
    await audio.play()
    isAudioPlaying.value = true
    audio.onended = () => {
      isAudioPlaying.value = false
      if (audio) {
        audio.currentTime = 0
      }
    }
  } catch {
    isAudioPlaying.value = false
  }
}

function setRate(rate: number) {
  playbackRate.value = rate
  if (audio) {
    audio.playbackRate = rate
  }
}

watch(activeSentence, () => {
  if (audio && isAudioPlaying.value) {
    audio.pause()
    audio.currentTime = 0
    isAudioPlaying.value = false
  }
  showTranslation.value = false
})

const openGenerateModal = () => {
  showGenerateModal.value = true
}

const closeGenerateModal = () => {
  showGenerateModal.value = false
}

const pushToast = (message: string) => {
  toastMessage.value = message
  if (toastTimeout) {
    clearTimeout(toastTimeout)
  }
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 4000)
}

const startPolling = () => {
  if (pollingInterval !== null) return
  pollingInterval = window.setInterval(() => {
    reload()
  }, 6000)
}

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

const handleGenerationQueued = () => {
  showGenerateModal.value = false
  pendingBaselineSignature.value = sentencesSignature.value
  isGenerationPending.value = true
  startPolling()
  pushToast('Shadowing sentence generation queued')
}

watch(isGenerationPending, (pending) => {
  if (pending) {
    startPolling()
  } else {
    stopPolling()
  }
})

watch(sentencesSignature, (signature) => {
  if (!isGenerationPending.value) return
  if (signature && signature !== pendingBaselineSignature.value) {
    isGenerationPending.value = false
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Shadowing sentences are ready')
  }
})

onMounted(() => {
  // if generation is pending and we already have some sentences, keep showing loading
  if (isGenerationPending.value && !isReady.value) {
    startPolling()
  }
})

onBeforeUnmount(() => {
  if (toastTimeout) {
    clearTimeout(toastTimeout)
  }
  stopPolling()
})

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)
</script>

<template>
  <section
    class="flex h-full w-full max-w-full flex-col overflow-x-hidden text-[var(--app-text)]"
  >
    <!-- Header (hidden in focus mode) -->
    <div
      v-if="!isFocusMode"
      class="flex w-full flex-wrap items-center justify-between gap-3 px-1"
    >
      <div class="space-y-0.5">
        <p
          class="text-xs font-semibold font-display tracking-wider uppercase text-[var(--app-accent)]"
        >
          Shadowing
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)] hidden sm:block">
          Listen and repeat each sentence
        </p>
      </div>
      <div
        class="flex items-center gap-2 text-xs text-[var(--app-text-muted)]"
      >
        <span class="rounded-full bg-[var(--app-surface-elevated)] border border-[var(--app-border)] px-3 py-1 font-medium">
          {{ progressLabel }}
        </span>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          <span class="text-[10px] font-bold">AI</span>
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="!isReady || !activeSentence"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:maximize-square-minimalistic-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Progress & toast (hidden in focus mode) -->
    <div
      v-if="!isFocusMode"
      class="mt-4 h-1.5 w-full max-w-full overflow-hidden rounded-full bg-[var(--app-panel-muted)]"
    >
      <div
        class="h-full rounded-full bg-[var(--app-accent)] transition-all duration-300"
        :style="{ width: progressPercent + '%' }"
      />
    </div>

    <div
      v-if="toastMessage && !isFocusMode"
      class="mt-4 w-full max-w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-2 text-center text-xs font-medium text-[var(--app-text)] shadow-sm"
    >
      {{ toastMessage }}
    </div>

    <div
      class="mt-6 flex w-full max-w-full flex-1 flex-col items-center justify-center gap-6 overflow-x-hidden px-1 sm:px-0 relative"
    >
      <Transition name="fade-scale" mode="out-in">
        <div
          v-if="isError"
          class="flex w-full max-w-full flex-col items-center gap-3 px-4 text-sm text-[var(--app-accent-strong)]"
          key="error"
        >
          <p>Could not load shadowing sentences.</p>
          <button
            class="rounded-full border border-[var(--app-accent-strong)] px-4 py-1.5 text-xs font-medium text-[var(--app-accent-strong)]"
            @click="reload"
          >
            Try again
          </button>
        </div>

        <div v-else-if="isGenerationPending" class="w-full max-w-full" key="generating">
           <div
             class="flex flex-col items-center gap-4 text-sm text-[var(--app-text-muted)]"
           >
             <span class="flex items-center gap-3 text-[var(--app-text)]">
               <Icon icon="svg-spinners:90-ring-with-bg" class="h-5 w-5 text-[var(--app-accent)]" />
               Generating sentences...
             </span>
             <button
               class="rounded-full border border-[var(--app-border)] px-4 py-1.5 text-xs font-medium text-[var(--app-text-muted)] transition hover:text-[var(--app-text)]"
               @click="reload"
             >
               Check status
             </button>
           </div>
        </div>

        <div
          v-else-if="isLoading && !isReady && !isEmpty"
          class="flex w-full max-w-sm flex-col items-center gap-4 sm:max-w-md"
          key="loading"
        >
          <div
            class="aspect-[3/4] w-full animate-pulse rounded-[32px] bg-[var(--app-surface-elevated)] border border-[var(--app-border)]"
          />
          <div class="mx-auto flex gap-4">
            <div
              class="h-12 w-12 animate-pulse rounded-full bg-[var(--app-surface-elevated)]"
            />
          </div>
        </div>

        <div
          v-else-if="emptyStateVisible"
          class="flex w-full max-w-full flex-col items-center justify-center gap-5 rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/50 px-6 py-10 text-center text-sm"
          key="empty"
        >
          <div class="rounded-full bg-[var(--app-surface-elevated)] p-4 text-[var(--app-accent)] ring-1 ring-[var(--app-border)]">
            <Icon icon="solar:microphone-3-bold-duotone" class="h-8 w-8" />
          </div>
          <div class="space-y-1">
            <p class="text-base font-semibold text-[var(--app-text)]">
              No shadowing sentences
            </p>
            <p class="text-xs text-[var(--app-text-muted)] max-w-[200px] mx-auto leading-relaxed">
              Generate AI-powered sentences to start practicing pronunciation.
            </p>
          </div>
          <button
            class="rounded-full bg-[var(--app-accent)] px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[var(--app-accent)]/30 transition active:scale-95"
            @click="openGenerateModal"
          >
            Generate exercises
          </button>
        </div>

        <div v-else-if="isReady && activeSentence" class="w-full max-w-full px-1" key="active">
           <div
             class="relative mx-auto flex h-full w-full max-w-sm flex-col justify-center rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-5 py-6 text-[var(--app-text)] shadow-sm sm:max-w-md sm:px-8 sm:py-8 dark:border-white/5"
           >
             <!-- exit focus -->
             <button
               v-if="isFocusMode"
               type="button"
               class="absolute left-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm backdrop-blur-md"
               @click="isFocusMode = false"
             >
               <Icon
                 icon="solar:minimize-square-minimalistic-bold-duotone"
                 class="h-4 w-4"
               />
             </button>

             <!-- top controls (hidden in focus mode to keep card clean) -->
             <div
               v-if="!isFocusMode"
               class="mb-6 flex items-center justify-center"
             >
               <span class="rounded-full bg-[var(--app-surface)] px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-text-muted)]">
                 Sentence {{ activeSentence.orderIndex }} / {{ total }}
               </span>
             </div>

             <div class="flex flex-1 flex-col items-center justify-center gap-6 sm:gap-8">
               <div class="space-y-4 text-center w-full">
                 <p class="font-display text-2xl font-semibold leading-relaxed sm:text-3xl">
                   {{ activeSentence.text }}
                 </p>
                 <div class="h-px w-12 mx-auto bg-[var(--app-border)]"></div>
                 
                 <div class="min-h-[3rem]">
                   <p
                     v-if="showTranslation && activeSentence.translation"
                     class="text-sm leading-relaxed text-[var(--app-text-muted)] font-medium"
                   >
                     {{ activeSentence.translation }}
                   </p>
                   <button
                      v-else
                      @click="showTranslation = true"
                      class="text-xs text-[var(--app-text-muted)] hover:text-[var(--app-text)] transition underline underline-offset-4 decoration-[var(--app-border)]"
                   >
                     Show translation
                   </button>
                 </div>
               </div>

               <div class="w-full space-y-4">
                 <!-- Play Controls -->
                 <div class="flex items-center justify-center gap-4"> 
                    <button
                     type="button"
                     class="group relative flex h-16 w-16 items-center justify-center rounded-full bg-[var(--app-accent)] text-white shadow-xl shadow-[var(--app-accent)]/20 transition active:scale-95 disabled:opacity-70"
                     :disabled="isAudioLoading"
                     @click.stop="handlePlayClick"
                   >
                     <Icon
                        v-if="!isAudioLoading && !isAudioPlaying"
                        icon="solar:play-bold"
                        class="h-8 w-8 ml-1"
                     />
                      <Icon
                        v-else-if="isAudioPlaying"
                        icon="solar:pause-bold"
                        class="h-8 w-8"
                     />
                      <Icon
                        v-else
                        icon="svg-spinners:90-ring-with-bg"
                        class="h-8 w-8 text-white/80"
                     />
                   </button>
                 </div>

                 <!-- Secondary Controls -->
                  <div
                   class="flex items-center justify-center gap-2"
                 >
                   <div class="flex items-center rounded-full bg-[var(--app-surface)] p-1 border border-[var(--app-border)]">
                     <button
                       v-for="rate in playbackRateOptions"
                       :key="rate"
                       type="button"
                       class="rounded-full px-3 py-1.5 text-[11px] font-medium transition"
                       :class="
                         playbackRate === rate
                           ? 'bg-[var(--app-surface-elevated)] text-[var(--app-accent)] shadow-sm font-bold'
                           : 'text-[var(--app-text-muted)] hover:text-[var(--app-text)]'
                       "
                       @click.stop="setRate(rate)"
                     >
                       {{ rate }}x
                     </button>
                   </div>
                 </div>
               </div>

               <!-- Navigation -->
               <div
                 v-if="!isFocusMode"
                 class="flex w-full items-center justify-between gap-4 mt-2"
               >
                 <button
                   type="button"
                   class="flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition active:scale-95 disabled:opacity-30 disabled:active:scale-100"
                   :disabled="!hasPrev"
                   @click.stop="goPrev"
                 >
                   <Icon icon="solar:arrow-left-linear" class="h-6 w-6" />
                 </button>
                 
                 <span class="text-[10px] font-medium text-[var(--app-text-muted)] uppercase tracking-widest">
                    Navigate
                 </span>

                 <button
                   type="button"
                   class="flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition active:scale-95 disabled:opacity-30 disabled:active:scale-100"
                   :disabled="!hasNext"
                   @click.stop="goNext"
                 >
                    <Icon icon="solar:arrow-right-linear" class="h-6 w-6" />
                 </button>
               </div>
             </div>
           </div>
        </div>

        <div
          v-else-if="isReady && !activeSentence"
          class="flex h-full w-full max-w-full items-center justify-center rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-6 py-6 text-sm text-[var(--app-text-muted)]"
          key="done"
        >
          No active sentence.
        </div>
      </Transition>
    </div>

    <GenerateShadowingModal
      :open="showGenerateModal"
      :lesson-id="props.lesson.id"
      @close="closeGenerateModal"
      @queued="handleGenerationQueued"
    />
  </section>
</template>

<style scoped>
.fade-scale-enter-active,
.fade-scale-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.fade-scale-enter-from,
.fade-scale-leave-to {
  opacity: 0;
  transform: translateY(10px) scale(0.98);
}
</style>

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
    class="flex h-full w-full max-w-full flex-col overflow-x-hidden text-[var(--app-text)] dark:text-white"
  >
    <!-- Header (hidden in focus mode) -->
    <div
      v-if="!isFocusMode"
      class="flex w-full flex-wrap items-center justify-between gap-3"
    >
      <div class="space-y-1">
        <p
          class="text-[10px] font-semibold uppercase tracking-[0.25em] text-[var(--app-text-muted)] dark:text-white/60"
        >
          Shadowing
        </p>
        <p class="text-sm text-[var(--app-text-muted)] dark:text-white/60">
          Listen and repeat each sentence several times
        </p>
      </div>
      <div
        class="flex w-full flex-wrap items-center gap-2 text-xs text-[var(--app-text-muted)] sm:w-auto sm:justify-end dark:text-white/60"
      >
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Sentence: {{ progressLabel }}
        </span>
        <span class="rounded-full border border-[var(--app-border)] px-3 py-1 dark:border-white/15">
          Total: {{ total }}
        </span>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15"
          :disabled="isGenerationPending"
          @click="openGenerateModal"
        >
          <span class="text-[11px]">AI</span>
        </button>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15"
          :disabled="!isReady || !activeSentence"
          @click="isFocusMode = true"
        >
          <Icon
            icon="solar:fullscreen-bold-duotone"
            class="h-4 w-4"
          />
        </button>
      </div>
    </div>

    <!-- Progress & toast (hidden in focus mode) -->
    <div
      v-if="!isFocusMode"
      class="mt-3 h-2 w-full max-w-full overflow-hidden rounded-full bg-[var(--app-panel-muted)] dark:bg-white/10"
    >
      <div
        class="h-full rounded-full bg-[var(--app-accent-secondary)] transition-all duration-300"
        :style="{ width: progressPercent + '%' }"
      />
    </div>

    <div
      v-if="toastMessage && !isFocusMode"
      class="mt-4 w-full max-w-full rounded-full border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-2 text-center text-xs text-[var(--app-text)] dark:border-white/10 dark:bg-white/5 dark:text-white/80"
    >
      {{ toastMessage }}
    </div>

    <div
      class="mt-6 flex w-full max-w-full flex-1 flex-col items-center justify-center gap-8 overflow-x-hidden px-1 sm:px-0"
    >
      <div
        v-if="isError"
        class="flex w-full max-w-full flex-col items-center gap-3 px-4 text-sm text-[var(--app-accent-strong)]"
      >
        <p>Could not load shadowing sentences.</p>
        <button
          class="rounded-full border border-[var(--app-accent-strong)] px-4 py-1.5 text-xs font-medium text-[var(--app-accent-strong)]"
          @click="reload"
        >
          Try again
        </button>
      </div>

      <div v-if="isGenerationPending" class="w-full max-w-full">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="shadowing-generating"
            class="flex flex-col items-center gap-4 text-sm text-[var(--app-text-muted)] dark:text-white/70"
          >
            <span class="flex items-center gap-3 text-[var(--app-text)] dark:text-white">
              <svg
                class="h-4 w-4 animate-spin text-[var(--app-accent)]"
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
              Generating shadowing sentences… This may take a moment.
            </span>
            <button
              class="rounded-full border border-[var(--app-border)] px-4 py-1 text-xs text-[var(--app-text)] transition hover:text-[var(--app-accent-strong)] dark:border-white/15 dark:text-white/80 dark:hover:text-white"
              @click="reload"
            >
              Check again
            </button>
          </div>
        </Transition>
      </div>

      <div
        v-else-if="isLoading && !isReady && !isEmpty"
        class="flex w-full max-w-sm flex-col items-center gap-4 sm:max-w-md"
      >
        <div
          class="aspect-[3/4] w-full animate-pulse rounded-3xl bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80"
        />
        <div class="mx-auto flex gap-4">
          <div
            class="h-10 w-10 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80"
          />
          <div
            class="h-10 w-10 animate-pulse rounded-full bg-[var(--app-panel-muted)] dark:bg-[var(--app-surface-dark)]/80"
          />
        </div>
      </div>

      <div
        v-else-if="emptyStateVisible"
        class="flex w-full max-w-full flex-col items-center justify-center gap-4 rounded-[20px] border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-10 text-center text-sm text-[var(--app-text-muted)] sm:px-6 dark:border-white/10 dark:bg-white/5 dark:text-white/70"
      >
        <p class="text-base text-[var(--app-text)] dark:text-white">
          No shadowing sentences for this lesson yet.
        </p>
        <p class="text-xs text-[var(--app-text-muted)] dark:text-white/60">
          Generate AI-powered sentences to start shadowing practice.
        </p>
        <button
          class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_15px_30px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)]"
          @click="openGenerateModal"
        >
          Generate shadowing sentences
        </button>
      </div>

      <div v-else-if="isReady && activeSentence" class="w-full max-w-full px-1">
        <Transition name="fade-scale" mode="out-in">
          <div
            key="shadowing-ready"
            class="relative mx-auto flex h-full w-full max-w-sm flex-col justify-center rounded-3xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-5 text-[var(--app-text)] shadow-md sm:max-w-md sm:px-6 sm:py-6 dark:border-[var(--app-border-dark)] dark:bg-[#202124] dark:text-white"
          >
            <!-- exit focus -->
            <button
              v-if="isFocusMode"
              type="button"
              class="absolute left-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]/80 text-[var(--app-text)] shadow-sm dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
              @click="isFocusMode = false"
            >
              <Icon
                icon="solar:arrow-left-linear"
                class="h-4 w-4"
              />
            </button>

            <!-- top controls (hidden in focus mode to keep card clean) -->
            <div
              v-if="!isFocusMode"
              class="mb-4 flex items-center justify-between text-[11px] text-[var(--app-text-muted)] dark:text-white/60"
            >
              <span>Shadowing card</span>
              <span>#{{ activeSentence.orderIndex }} / {{ total }}</span>
            </div>

            <div class="flex flex-1 flex-col items-center justify-center gap-4">
              <div class="space-y-2 text-center">
                <p class="px-1 text-base leading-relaxed sm:text-lg md:text-xl">
                  {{ activeSentence.text }}
                </p>
                <p
                  v-if="showTranslation && activeSentence.translation"
                  class="px-1 text-xs leading-relaxed text-[var(--app-text-muted)] sm:text-sm dark:text-white/70"
                >
                  {{ activeSentence.translation }}
                </p>
              </div>

              <div
                class="mt-2 flex flex-col items-center gap-2 text-[11px] text-[var(--app-text-muted)] sm:flex-row sm:justify-center sm:gap-3 dark:text-white/60"
              >
                <div
                  class="flex items-center gap-1 rounded-full bg-[var(--app-surface)]/60 px-2 py-1 dark:bg-black/20"
                >
                  <button
                    v-for="rate in playbackRateOptions"
                    :key="rate"
                    type="button"
                    class="rounded-full px-2 py-0.5 text-[10px] transition"
                    :class="
                      playbackRate === rate
                        ? 'bg-[var(--app-accent-secondary)] text-white'
                        : 'text-[var(--app-text-muted)] hover:bg-[var(--app-surface-elevated)] dark:text-white/60 dark:hover:bg-[var(--app-surface-dark)]'
                    "
                    @click.stop="setRate(rate)"
                  >
                    {{ rate }}x
                  </button>
                </div>
                <button
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full bg-[var(--app-accent-secondary)] px-3 py-1.5 text-[11px] font-semibold text-white disabled:opacity-40 dark:text-[var(--app-surface-dark)]"
                  :disabled="isAudioLoading"
                  @click.stop="handlePlayClick"
                >
                  <span v-if="isAudioLoading">Loading…</span>
                  <span v-else-if="isAudioPlaying">Pause</span>
                  <span v-else>Play</span>
                </button>
                <button
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-1 text-[11px] text-[var(--app-text)] disabled:opacity-40 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-white"
                  :disabled="!activeSentence.translation"
                  @click.stop="showTranslation = !showTranslation"
                >
                  <span v-if="showTranslation">Hide translation</span>
                  <span v-else>Show translation</span>
                </button>
              </div>

              <div
                v-if="!isFocusMode"
                class="mt-4 flex w-full items-center justify-center gap-10 text-[11px] text-[var(--app-text-muted)] dark:text-white/60"
              >
                <button
                  type="button"
                  class="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] disabled:opacity-30 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
                  :disabled="!hasPrev"
                  @click.stop="goPrev"
                >
                  ←
                </button>
                <span class="text-[11px] font-semibold">
                  {{ progressLabel }}
                </span>
                <button
                  type="button"
                  class="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] disabled:opacity-30 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
                  :disabled="!hasNext"
                  @click.stop="goNext"
                >
                  →
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </div>

      <div
        v-else-if="isReady && !activeSentence"
        class="flex h-full w-full max-w-full items-center justify-center rounded-3xl border border-[var(--app-border)] bg-[var(--app-panel)] px-4 py-6 text-sm text-[var(--app-text-muted)] shadow-[var(--app-card-shadow)] sm:px-6 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-white/70 dark:shadow-2xl"
      >
        No active sentence.
      </div>
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

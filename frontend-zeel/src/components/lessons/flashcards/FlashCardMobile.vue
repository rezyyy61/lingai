<template>
  <section class="lg:hidden h-full min-h-0">
    <div class="h-full min-h-0 overflow-hidden" style="background: var(--app-bg)">
      <div
        class="flex h-full min-h-0 flex-col gap-3 px-4 pt-4"
        :style="{ paddingBottom: 'max(16px, env(safe-area-inset-bottom))' }"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-3 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]">
              {{ review.dueCount }} due
            </span>
            <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-3 py-1 text-[11px] font-semibold text-emerald-300">
              {{ review.successCount }} good
            </span>
            <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-3 py-1 text-[11px] font-semibold text-red-300">
              {{ review.failureCount }} bad
            </span>
          </div>

          <button
            class="zee-card px-3 py-2 text-[11px] font-semibold"
            type="button"
            :disabled="review.isLoading.value"
            @click="handleResetReview"
          >
            Restart all
          </button>
        </div>

        <div class="grid grid-cols-4 gap-2">
          <button
            class="zee-btn py-2 text-[11px] font-semibold"
            type="button"
            :disabled="isGenerationPending || isGenerating"
            @click="handleGenerate"
          >
            Generate
          </button>
          <button
            class="zee-card py-2 text-[11px] font-semibold"
            type="button"
            @click="isImportModalOpen = true"
          >
            Import
          </button>
          <button
            class="zee-card py-2 text-[11px] font-semibold disabled:opacity-40"
            type="button"
            :disabled="!activeCard"
            @click="isEditModalOpen = true"
          >
            Edit
          </button>
          <button
            class="zee-card py-2 text-[11px] font-semibold text-red-300 disabled:opacity-40"
            type="button"
            :disabled="!activeCard || isDeleting"
            @click="handleDelete"
          >
            {{ isDeleting ? 'Deleting…' : 'Delete' }}
          </button>
        </div>

        <div class="flex-1 min-h-0">
          <div v-if="isError" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Couldn’t load flashcards</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">Try again.</div>
            <button class="zee-btn mt-4 w-full py-3" type="button" @click="handleReloadClick">
              Reload
            </button>
          </div>

          <div v-else-if="isLoading" class="zee-card h-full overflow-hidden p-5">
            <div class="animate-pulse space-y-3">
              <div class="h-5 w-28 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-10 w-3/4 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-1/2 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-2/3 rounded bg-[color:var(--app-panel-muted)]"></div>
            </div>
          </div>

          <div v-else-if="emptyStateVisible" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">No flashcards yet</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
              Generate words for this lesson to start practicing.
            </div>
            <button
              class="zee-btn mt-4 w-full py-3"
              type="button"
              :disabled="isGenerationPending || isGenerating"
              @click="handleGenerate"
            >
              Generate flashcards
            </button>
            <button
              class="zee-card mt-3 w-full py-3 text-sm font-semibold"
              type="button"
              @click="isImportModalOpen = true"
            >
              Import JSON
            </button>
          </div>

          <div v-else-if="isGenerationPending" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Generating…</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
              We’re extracting vocabulary. This usually takes a few seconds.
            </div>

            <div class="mt-5">
              <div class="h-2 w-full overflow-hidden rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel-muted)]">
                <div
                  class="h-full rounded-full"
                  :style="{ width: '55%', background: 'linear-gradient(90deg, var(--app-accent), var(--app-accent-strong))' }"
                />
              </div>
            </div>

            <button class="zee-btn mt-5 w-full py-3" type="button" @click="manualReload">
              Check again
            </button>
          </div>

          <div
            v-else-if="displayCard"
            class="zee-card relative h-full overflow-hidden"
            :style="cardStyle"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @touchstart.passive="onTouchStart"
            @touchmove.passive="onTouchMove"
            @touchend.passive="onTouchEnd"
            @touchcancel.passive="onTouchEnd"
            role="button"
            tabindex="0"
            @click="flip"
            @keydown.enter.prevent="flip"
            @keydown.space.prevent="flip"
          >
            <div
              class="pointer-events-none absolute -inset-10 opacity-60 blur-3xl"
              :style="{ background: 'radial-gradient(60% 60% at 50% 10%, var(--app-accent-soft) 0%, transparent 70%)' }"
            />

            <div class="relative h-full w-full flip-perspective">
              <div class="h-full w-full flip-inner" :class="isFlipped ? 'is-flipped' : ''">
                <div class="face p-5">
                  <div class="flex h-full min-h-0 flex-col">
                    <div class="flex items-start justify-between">
                      <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-2 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]">
                        Prompt
                      </span>

                      <button
                        class="grid h-11 w-11 place-items-center rounded-2xl border border-[color:var(--app-border)]
                               bg-[color:var(--app-surface-elevated)] active:scale-[0.99]"
                        type="button"
                        aria-label="Play audio"
                        @click="playAudio($event)"
                      >
                        <Icon v-if="isPlaying" icon="solar:pause-circle-outline" class="h-7 w-7" :style="{ color: 'var(--app-accent)' }" />
                        <Icon v-else icon="solar:play-circle-outline" class="h-7 w-7" :style="{ color: 'var(--app-accent)' }" />
                      </button>
                    </div>

                    <div class="flex flex-1 min-h-0 flex-col items-center justify-center text-center px-2">
                      <div class="text-[36px] font-semibold leading-[1.08] tracking-tight">
                        {{ displayCard.term }}
                      </div>
                      <div class="mt-3 text-xs font-medium text-[color:var(--app-text-muted)]">
                        Tap to flip • Swipe left/right to review
                      </div>
                    </div>

                    <div class="flex items-center justify-between text-xs text-[color:var(--app-text-muted)]">
                      <span v-if="isLoadingAudio">Loading audio…</span>
                      <span v-else-if="cardHasTts">Audio ready</span>
                      <span v-else>Play available</span>
                      <span class="font-semibold">
                        {{ `${review.reviewedCount.value + 1}/${Math.max(review.sessionInitialCount.value, 1)}` }}
                      </span>
                    </div>
                  </div>
                </div>

                <div class="face back p-5">
                  <div class="flex h-full min-h-0 flex-col">
                    <div class="flex items-start justify-between">
                      <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-2 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]">
                        Answer
                      </span>

                      <button
                        class="grid h-11 w-11 place-items-center rounded-2xl border border-[color:var(--app-border)]
                               bg-[color:var(--app-surface-elevated)] active:scale-[0.99]"
                        type="button"
                        aria-label="Play audio"
                        @click="playAudio($event)"
                      >
                        <Icon v-if="isPlaying" icon="solar:pause-circle-outline" class="h-7 w-7" :style="{ color: 'var(--app-accent)' }" />
                        <Icon v-else icon="solar:play-circle-outline" class="h-7 w-7" :style="{ color: 'var(--app-accent)' }" />
                      </button>
                    </div>

                    <div class="mt-4 flex-1 min-h-0 overflow-hidden">
                      <div class="rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] p-4">
                        <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Translation</div>
                        <div class="mt-1 text-2xl font-semibold leading-tight" dir="auto">
                          {{ displayCard.translation || '—' }}
                        </div>
                      </div>

                      <div class="mt-4 space-y-3 overflow-hidden">
                        <div class="overflow-hidden">
                          <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Meaning</div>
                          <div class="mt-1 text-sm leading-relaxed clamp-3">
                            {{ displayCard.meaning || '—' }}
                          </div>
                        </div>

                        <div class="overflow-hidden">
                          <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Example</div>
                          <div class="mt-1 text-sm leading-relaxed clamp-3">
                            {{ cardExample || '—' }}
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-[color:var(--app-text-muted)]">
                      <span>Swipe left bad • Swipe right good</span>
                      <span class="font-semibold">{{ displayCard.id ? `#${displayCard.id}` : '' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else-if="total" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Review complete</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
              All wrong cards have now been cleared. Restart all if you want to see the whole deck again.
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-[color:var(--app-text-muted)]">
              <span>{{ review.successCount }} good</span>
              <span>•</span>
              <span>{{ review.failureCount }} bad</span>
            </div>
            <button class="zee-btn mt-5 w-full py-3" type="button" @click="handleResetReview">
              Review all again
            </button>
          </div>

          <div v-else class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Nothing to show</div>
            <button
              class="zee-btn mt-4 w-full py-3"
              type="button"
              :disabled="isGenerationPending || isGenerating"
              @click="handleGenerate"
            >
              Generate flashcards
            </button>
          </div>
        </div>

        <div class="mt-2 grid grid-cols-3 gap-3">
          <button
            class="zee-card flex h-11 w-11 items-center justify-center rounded-full active:scale-[0.99] disabled:opacity-40 mx-auto text-red-300"
            type="button"
            :disabled="review.isSubmitting.value || !displayCard"
            @click="handleReviewSubmit('dont_know')"
            aria-label="Don't know"
          >
            <Icon icon="solar:close-circle-bold-duotone" class="h-6 w-6" />
          </button>

          <button
            class="zee-btn py-2 text-xs font-semibold"
            type="button"
            :disabled="!displayCard"
            @click="flip"
          >
            {{ isFlipped ? 'Answer shown' : 'Show answer' }}
          </button>

          <button
            class="zee-card flex h-11 w-11 items-center justify-center rounded-full active:scale-[0.99] disabled:opacity-40 mx-auto text-emerald-400"
            type="button"
            :disabled="review.isSubmitting.value || !displayCard"
            @click="handleReviewSubmit('know')"
            aria-label="Know"
          >
            <Icon icon="solar:check-circle-bold-duotone" class="h-6 w-6" />
          </button>
        </div>
      </div>

      <transition name="fade">
        <div v-if="toastMessage" class="fixed bottom-24 left-1/2 z-50 -translate-x-1/2 px-4">
          <div class="flex items-center gap-2 rounded-full border border-white/10 bg-[var(--app-surface-dark-elevated)]/90 px-4 py-2.5 text-xs font-medium text-white shadow-xl backdrop-blur-md">
            <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
            {{ toastMessage }}
          </div>
        </div>
      </transition>
    </div>
  </section>

  <FlashcardJsonImportModal
    :open="isImportModalOpen"
    :lesson-id="lessonId"
    @close="isImportModalOpen = false"
    @imported="handleImported"
  />

  <FlashcardEditModal
    :open="isEditModalOpen"
    :lesson-id="lessonId"
    :card="activeCard"
    @close="isEditModalOpen = false"
    @saved="handleEdited"
  />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useFlashcardReview } from '@/composables/useFlashcardReview'
import { useLessonFlashcards } from '@/composables/useLessonFlashcards'
import { deleteLessonWord, fetchLessonWordTts, generateLessonFlashcards } from '@/api/lessonFlashcards'
import FlashcardEditModal from './FlashcardEditModal.vue'
import FlashcardJsonImportModal from './FlashcardJsonImportModal.vue'

const props = defineProps<{ lessonId: number }>()

const {
  currentCard,
  total,
  isLoading,
  isError,
  isEmpty,
  isReady,
  reload,
} = useLessonFlashcards(props.lessonId)

const review = useFlashcardReview(props.lessonId)
const displayCard = computed(() => review.currentCard.value)
const activeCard = computed(() => review.currentCard.value ?? currentCard.value)

const isGenerationPending = ref(false)
const isGenerationTimedOut = ref(false)
const isGenerating = ref(false)
const isImportModalOpen = ref(false)
const isEditModalOpen = ref(false)
const isDeleting = ref(false)
const isFlipped = ref(false)
const didSwipe = ref(false)

const toastMessage = ref('')
let toastTimeout: number | null = null
let pollingInterval: number | null = null
let generationTimeout: number | null = null

const generationStorageKey = computed(() => `zeel:flashcards-generating:${props.lessonId}`)

const loadGenerationState = () => {
  if (typeof window === 'undefined') return false
  try {
    return window.localStorage.getItem(generationStorageKey.value) === '1'
  } catch {
    return false
  }
}

const persistGenerationState = (pending: boolean) => {
  if (typeof window === 'undefined') return
  try {
    if (pending) window.localStorage.setItem(generationStorageKey.value, '1')
    else window.localStorage.removeItem(generationStorageKey.value)
  } catch {
    // ignore
  }
}

const pushToast = (message: string) => {
  toastMessage.value = message
  if (toastTimeout) clearTimeout(toastTimeout)
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 3000)
}

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
  if (generationTimeout !== null) {
    clearTimeout(generationTimeout)
    generationTimeout = null
  }
}

const handleTimeout = () => {
  stopPolling()
  isGenerationPending.value = false
  isGenerationTimedOut.value = true
  persistGenerationState(false)
}

const startPolling = () => {
  if (pollingInterval !== null) return

  isGenerationTimedOut.value = false

  if (generationTimeout === null) {
    generationTimeout = window.setTimeout(handleTimeout, 40000)
  }

  pollingInterval = window.setInterval(() => {
    reload()
  }, 4000)
}

const manualReload = () => {
  isGenerationTimedOut.value = false
  isGenerationPending.value = true
  startPolling()
  reload()
}

const handleReloadClick = () => {
  reload()
}

const handleGenerate = async () => {
  if (isGenerating.value) return
  isGenerationTimedOut.value = false
  isGenerationPending.value = true
  isGenerating.value = true
  persistGenerationState(true)
  startPolling()
  try {
    await generateLessonFlashcards(props.lessonId, { replace_existing: true })
    pushToast('Vocabulary extraction started')
  } catch (error) {
    console.error(error)
    isGenerationPending.value = false
    persistGenerationState(false)
    stopPolling()
    pushToast('Could not start vocabulary extraction')
  } finally {
    isGenerating.value = false
  }
}

const handleResetReview = async () => {
  await review.reset()
  isFlipped.value = false
  if (!review.currentCard.value) {
    pushToast('No flashcards are available to review')
  }
}

const handleReviewSubmit = async (result: 'know' | 'dont_know') => {
  if (!displayCard.value) {
    return
  }

  await review.submit(result)
  isFlipped.value = false

  if (review.isComplete.value) {
    pushToast('Review session complete')
  }
}

const handleImported = async (count: number) => {
  await reload()
  await review.start()
  pushToast(`${count} flashcard${count > 1 ? 's' : ''} imported`)
}

const handleEdited = async () => {
  await reload(activeCard.value?.id ?? null)
  await review.start()
  pushToast('Flashcard updated')
}

const handleDelete = async () => {
  if (!activeCard.value || isDeleting.value) return
  const confirmed = window.confirm(`Delete "${activeCard.value.term}"?`)
  if (!confirmed) return

  isDeleting.value = true
  try {
    await deleteLessonWord(props.lessonId, activeCard.value.id)
    await reload()
    await review.start()
    pushToast('Flashcard deleted')
  } catch (error) {
    console.error(error)
    pushToast('Could not delete flashcard')
  } finally {
    isDeleting.value = false
  }
}

watch(isReady, (ready) => {
  if (ready) {
    if (isGenerationPending.value) {
      pushToast('Vocabulary is ready')
      review.start()
    }
    isGenerationPending.value = false
    isGenerationTimedOut.value = false
    persistGenerationState(false)
    stopPolling()
  }
})

watch(isGenerationPending, (pending) => {
  if (pending) startPolling()
  else stopPolling()
  persistGenerationState(pending)
})

watch(
  () => displayCard.value?.id,
  () => {
    isFlipped.value = false
    stopAudio()
    const card = displayCard.value as any
    audioUrl.value = card?.tts_audio_url ?? card?.tts_audio_path ?? null
  },
)

onMounted(() => {
  const pending = loadGenerationState()
  if (pending) {
    isGenerationPending.value = true
    startPolling()
  }
  review.start()
})

onBeforeUnmount(() => {
  if (toastTimeout) clearTimeout(toastTimeout)
  stopPolling()
  stopAudio()
})

const startX = ref<number | null>(null)
const deltaX = ref(0)
const isDragging = ref(false)

const cardStyle = computed(() => {
  const x = deltaX.value
  const rotate = Math.max(-7, Math.min(7, x / 20))
  const scale = isDragging.value ? 0.996 : 1
  return { transform: `translateX(${x}px) rotate(${rotate}deg) scale(${scale})` }
})

function flip() {
  if (!displayCard.value) return
  if (didSwipe.value) {
    didSwipe.value = false
    return
  }
  isFlipped.value = !isFlipped.value
}

function onPointerDown(event: PointerEvent) {
  if (!displayCard.value) return
  startX.value = event.clientX
  deltaX.value = 0
  isDragging.value = true
}

function onPointerMove(event: PointerEvent) {
  if (!isDragging.value || startX.value === null) return
  const dx = event.clientX - startX.value
  deltaX.value = Math.max(-140, Math.min(140, dx))
}

function onPointerUp() {
  if (!isDragging.value) return
  isDragging.value = false

  const dx = deltaX.value
  const threshold = 70

  if (dx <= -threshold) {
    didSwipe.value = true
    handleReviewSubmit('dont_know')
  } else if (dx >= threshold) {
    didSwipe.value = true
    handleReviewSubmit('know')
  }

  deltaX.value = 0
  startX.value = null
}

function onTouchStart(event: TouchEvent) {
  if (!displayCard.value) return
  const touch = event.touches[0]
  if (!touch) return
  startX.value = touch.clientX
  deltaX.value = 0
  isDragging.value = true
}

function onTouchMove(event: TouchEvent) {
  if (!isDragging.value || startX.value === null) return
  const touch = event.touches[0]
  if (!touch) return
  const dx = touch.clientX - startX.value
  deltaX.value = Math.max(-140, Math.min(140, dx))
}

function onTouchEnd() {
  onPointerUp()
}

const isLoadingAudio = ref(false)
const isPlaying = ref(false)
const audioUrl = ref<string | null>(null)
let audio: HTMLAudioElement | null = null

const cardHasTts = computed(() => {
  const card: any = displayCard.value
  return !!(card?.tts_audio_url || card?.tts_audio_path)
})

const cardExample = computed(() => {
  const card: any = displayCard.value
  return card?.exampleSentence ?? card?.example_sentence ?? ''
})

function stopAudio() {
  isPlaying.value = false
  if (audio) {
    audio.pause()
    audio.currentTime = 0
    audio = null
  }
}

async function playAudio(event?: Event) {
  event?.stopPropagation()
  if (!displayCard.value?.id) return

  if (audio && isPlaying.value) {
    audio.pause()
    isPlaying.value = false
    return
  }

  try {
    isLoadingAudio.value = true

    if (!audioUrl.value) {
      const card: any = displayCard.value
      audioUrl.value = card?.tts_audio_url ?? card?.tts_audio_path ?? null
    }

    if (!audioUrl.value) {
      audioUrl.value = await fetchLessonWordTts(displayCard.value.id)
    }

    audio = new Audio(audioUrl.value)
    audio.onended = () => (isPlaying.value = false)
    audio.onerror = () => (isPlaying.value = false)

    isPlaying.value = true
    await audio.play()
  } catch {
    isPlaying.value = false
  } finally {
    isLoadingAudio.value = false
  }
}

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)
</script>

<style scoped>
.flip-perspective { perspective: 1200px; }
.flip-inner {
  position: relative;
  height: 100%;
  width: 100%;
  transform-style: preserve-3d;
  transition: transform 500ms ease;
}
.flip-inner.is-flipped { transform: rotateY(180deg); }

.face {
  position: absolute;
  inset: 0;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  transform: translateZ(0.1px);
}
.face.back { transform: rotateY(180deg) translateZ(0.1px); }

.clamp-3 {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.fade-enter-active,
.fade-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.fade-enter-from { opacity: 0; transform: translateY(8px); }
.fade-leave-to { opacity: 0; transform: translateY(-8px); }
</style>

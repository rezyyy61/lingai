<template>
  <section class="lg:hidden h-full min-h-0">
    <div class="h-full min-h-0 overflow-hidden" style="background: var(--app-bg)">
      <div
        class="flex h-full min-h-0 flex-col gap-3 px-4 pt-4"
        :style="{ paddingBottom: 'max(16px, env(safe-area-inset-bottom))' }"
      >
        <!-- Header -->
        <header class="flex items-center justify-between">
          <div class="min-w-0">
            <div class="text-xs font-medium tracking-wide text-[color:var(--app-text-muted)]">
              Flashcards
            </div>
          </div>

          <div class="flex items-center gap-2">
            <!-- Generate -->
            <button
              class="zee-card flex h-11 w-11 items-center justify-center active:scale-[0.99]"
              type="button"
              aria-label="Generate"
              @click="openGenerateModal"
            >
              <Icon icon="solar:magic-stick-3-outline" class="h-5 w-5 text-[color:var(--app-text)]" />
            </button>

            <!-- Reload (manual) when timed out -->
            <button
              v-if="isGenerationTimedOut"
              class="zee-card flex h-11 w-11 items-center justify-center active:scale-[0.99]"
              type="button"
              aria-label="Reload"
              @click="manualReload"
            >
              <Icon icon="solar:refresh-outline" class="h-5 w-5 text-[color:var(--app-text)]" />
            </button>

            <!-- Shuffle (only if we have data) -->
            <button
              v-if="isReady && total > 1"
              class="zee-card flex h-11 w-11 items-center justify-center active:scale-[0.99]"
              type="button"
              aria-label="Shuffle"
              @click="shuffleOrder"
            >
              <Icon icon="solar:shuffle-outline" class="h-5 w-5 text-[color:var(--app-text)]" />
            </button>
          </div>
        </header>

        <!-- Progress -->
        <div>
          <div class="h-2 w-full overflow-hidden rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel-muted)]">
            <div
              class="h-full rounded-full"
              :style="{
                width: progressPercent + '%',
                background: 'linear-gradient(90deg, var(--app-accent), var(--app-accent-strong))',
              }"
            />
          </div>

          <div class="mt-2 flex items-center justify-between text-xs text-[color:var(--app-text-muted)]">
            <span v-if="isGenerationPending">Generating…</span>
            <span v-else-if="isGenerationTimedOut">Generation taking longer than expected</span>
            <span v-else-if="isReady">{{ reviewed }}/{{ total }} reviewed</span>
            <span v-else>&nbsp;</span>

            <span class="font-semibold" v-if="isReady">{{ reviewed }}/{{ total }}</span>
            <span class="font-semibold" v-else>0/0</span>
          </div>
        </div>

        <!-- Card area -->
        <div class="flex-1 min-h-0">
          <!-- Error -->
          <div v-if="isError" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Couldn’t load flashcards</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">Try again.</div>
            <button class="zee-btn mt-4 w-full py-3" type="button" @click="reload">
              Reload
            </button>
          </div>

          <!-- Loading -->
          <div v-else-if="isLoading" class="zee-card h-full overflow-hidden p-5">
            <div class="animate-pulse space-y-3">
              <div class="h-5 w-28 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-10 w-3/4 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-1/2 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-2/3 rounded bg-[color:var(--app-panel-muted)]"></div>
            </div>
          </div>

          <!-- Empty (no pending) -->
          <div v-else-if="emptyStateVisible" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">No flashcards yet</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
              Generate words for this lesson to start practicing.
            </div>
            <button class="zee-btn mt-4 w-full py-3" type="button" @click="openGenerateModal">
              Generate flashcards
            </button>
          </div>

          <!-- Pending state -->
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

          <!-- Ready: Flashcard -->
          <div
            v-else-if="isReady && card"
            class="zee-card relative h-full overflow-hidden"
            :style="cardStyle"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            role="button"
            tabindex="0"
            @click="flip"
            @keydown.enter.prevent="flip"
            @keydown.space.prevent="flip"
          >
            <!-- subtle glow -->
            <div
              class="pointer-events-none absolute -inset-10 opacity-60 blur-3xl"
              :style="{ background: 'radial-gradient(60% 60% at 50% 10%, var(--app-accent-soft) 0%, transparent 70%)' }"
            />

            <div class="relative h-full w-full flip-perspective">
              <div class="h-full w-full flip-inner" :class="isFlipped ? 'is-flipped' : ''">
                <!-- FRONT -->
                <div class="face p-5">
                  <div class="flex h-full min-h-0 flex-col">
                    <div class="flex items-start justify-between">
                      <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-2 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]">
                        Front
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
                        {{ card.term }}
                      </div>
                      <div class="mt-3 text-xs font-medium text-[color:var(--app-text-muted)]">
                        Tap to flip • Swipe to change
                      </div>
                    </div>

                    <div class="flex items-center justify-between text-xs text-[color:var(--app-text-muted)]">
                      <span v-if="isLoadingAudio">Loading audio…</span>
                      <span v-else-if="cardHasTts">Audio ready</span>
                      <span v-else>Play available</span>
                      <span class="font-semibold">{{ reviewed }}/{{ total }}</span>
                    </div>
                  </div>
                </div>

                <!-- BACK -->
                <div class="face back p-5">
                  <div class="flex h-full min-h-0 flex-col">
                    <div class="flex items-start justify-between">
                      <span class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-2 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]">
                        Back
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
                          {{ card.translation || '—' }}
                        </div>
                      </div>

                      <div class="mt-4 space-y-3 overflow-hidden">
                        <div class="overflow-hidden">
                          <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Meaning</div>
                          <div class="mt-1 text-sm leading-relaxed clamp-3">
                            {{ card.meaning || '—' }}
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
                      <span>Tap to flip back</span>
                      <span class="font-semibold">{{ card.id ? `#${card.id}` : '' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Fallback -->
          <div v-else class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Nothing to show</div>
            <button class="zee-btn mt-4 w-full py-3" type="button" @click="openGenerateModal">
              Generate flashcards
            </button>
          </div>
        </div>

        <!-- Actions -->
        <div class="grid grid-cols-3 gap-3">
          <button
            class="zee-card flex items-center justify-center gap-2 py-3 active:scale-[0.99] disabled:opacity-50"
            type="button"
            :disabled="!hasPrev"
            @click="goPrevLocal"
          >
            <Icon icon="solar:arrow-left-outline" class="h-5 w-5" />
            <span class="text-sm font-semibold">Prev</span>
          </button>

          <button class="zee-btn py-3" type="button" @click="flip" :disabled="!isReady || !card">
            <div class="flex items-center justify-center gap-2">
              <Icon icon="solar:refresh-outline" class="h-5 w-5" />
              <span class="text-sm font-semibold">Flip</span>
            </div>
          </button>

          <button
            class="zee-card flex items-center justify-center gap-2 py-3 active:scale-[0.99] disabled:opacity-50"
            type="button"
            :disabled="!hasNext"
            @click="goNextLocal"
          >
            <span class="text-sm font-semibold">Next</span>
            <Icon icon="solar:arrow-right-outline" class="h-5 w-5" />
          </button>
        </div>
      </div>

      <!-- Modal -->
      <GenerateFlashcardsModal
        :open="showGenerateModal"
        :lesson-id="props.lessonId"
        @close="closeGenerateModal"
        @queued="handleGenerationQueued"
      />

      <!-- Toast -->
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
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useLessonFlashcards } from '@/composables/useLessonFlashcards'
import GenerateFlashcardsModal from '@/components/lessons/GenerateFlashcardsModal.vue'
import { fetchLessonWordTts } from '@/api/lessonFlashcards'

const props = defineProps<{ lessonId: number }>()

/**
 * Use existing system (same as old component)
 */
const {
  currentCard,
  total,
  reviewed,
  remaining,
  isLoading,
  isError,
  isEmpty,
  isReady,
  hasPrev,
  hasNext,
  goNext,
  goPrev,
  reload,
} = useLessonFlashcards(props.lessonId)

const progressPercent = computed(() => (total.value === 0 ? 0 : Math.round((reviewed.value / total.value) * 100)))

/**
 * Generate + polling system
 */
const showGenerateModal = ref(false)
const isGenerationPending = ref(false)
const isGenerationTimedOut = ref(false)

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

const openGenerateModal = () => (showGenerateModal.value = true)
const closeGenerateModal = () => (showGenerateModal.value = false)

const handleGenerationQueued = () => {
  showGenerateModal.value = false
  if (isEmpty.value) {
    isGenerationPending.value = true
    startPolling()
  }
  pushToast('Vocabulary extraction started')
}

watch(isReady, (ready) => {
  if (ready) {
    if (isGenerationPending.value) pushToast('Vocabulary is ready')
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

onMounted(() => {
  const pending = loadGenerationState()
  if (pending) {
    isGenerationPending.value = true
    startPolling()
  }
})

onBeforeUnmount(() => {
  if (toastTimeout) clearTimeout(toastTimeout)
  stopPolling()
})

/**
 * Card binding
 */
const card = computed(() => currentCard.value ?? null)
const isFlipped = ref(false)
watch(() => card.value?.id, () => (isFlipped.value = false))

/**
 * Swipe UI (local only)
 */
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
  if (!isReady.value || !card.value) return
  isFlipped.value = !isFlipped.value
}

function goNextLocal() {
  if (!hasNext.value) return
  isFlipped.value = false
  goNext()
}
function goPrevLocal() {
  if (!hasPrev.value) return
  isFlipped.value = false
  goPrev()
}

function onPointerDown(e: PointerEvent) {
  if (!isReady.value || !card.value) return
  startX.value = e.clientX
  deltaX.value = 0
  isDragging.value = true
}
function onPointerMove(e: PointerEvent) {
  if (!isDragging.value || startX.value === null) return
  const dx = e.clientX - startX.value
  deltaX.value = Math.max(-140, Math.min(140, dx))
}
function onPointerUp() {
  if (!isDragging.value) return
  isDragging.value = false

  const dx = deltaX.value
  const threshold = 70
  if (dx <= -threshold) goNextLocal()
  else if (dx >= threshold) goPrevLocal()

  deltaX.value = 0
  startX.value = null
}

/**
 * Shuffle: we keep it simple (UI-only) by doing multiple next jumps
 * (If you want real shuffle order, do it in composable later.)
 */
function shuffleOrder() {
  if (!isReady.value || total.value < 2) return
  const jumps = Math.min(5, total.value - 1)
  for (let i = 0; i < jumps; i++) goNext()
}

/**
 * Audio (same as before but uses card.id)
 */
const isLoadingAudio = ref(false)
const isPlaying = ref(false)
const audioUrl = ref<string | null>(null)
let audio: HTMLAudioElement | null = null

const cardHasTts = computed(() => {
  const c: any = card.value
  return !!(c?.tts_audio_url || c?.tts_audio_path)
})

const cardExample = computed(() => {
  const c: any = card.value
  return c?.exampleSentence ?? c?.example_sentence ?? ''
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
  if (!card.value?.id) return

  if (audio && isPlaying.value) {
    audio.pause()
    isPlaying.value = false
    return
  }

  try {
    isLoadingAudio.value = true

    if (!audioUrl.value) {
      const c: any = card.value
      audioUrl.value = c?.tts_audio_url ?? c?.tts_audio_path ?? null
    }
    if (!audioUrl.value) {
      audioUrl.value = await fetchLessonWordTts(card.value.id)
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

watch(
  () => card.value?.id,
  async () => {
    stopAudio()
    const c: any = card.value
    audioUrl.value = c?.tts_audio_url ?? c?.tts_audio_path ?? null
    await nextTick()
  },
)

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

/* toast fade */
.fade-enter-active,
.fade-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.fade-enter-from { opacity: 0; transform: translateY(8px); }
.fade-leave-to { opacity: 0; transform: translateY(-8px); }
</style>

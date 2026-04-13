<template>
  <section class="lg:hidden h-full min-h-0">
    <div class="h-full min-h-0 overflow-hidden flex flex-col" style="background: var(--app-bg)">
      <div
        class="flex h-full min-h-0 flex-col gap-3 px-4 pt-4"
        :style="{ paddingBottom: 'max(16px, env(safe-area-inset-bottom))' }"
      >
        <div v-if="!emptyStateVisible" class="flex flex-wrap items-center justify-between gap-2">
          <span
            class="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel)] px-3 py-1 text-[11px] font-semibold text-[color:var(--app-text-muted)]"
          >
            {{ progressLabel }}
          </span>
          <div class="flex flex-wrap items-center gap-2">
            <button
              class="zee-btn px-3 py-2 text-[11px] font-semibold"
              type="button"
              :disabled="isGenerationPending || isGenerating"
              @click="handleGenerate"
            >
              Generate
            </button>
            <button
              class="zee-card px-3 py-2 text-[11px] font-semibold"
              type="button"
              @click="isImportModalOpen = true"
            >
              Import
            </button>
            <button
              class="zee-card px-3 py-2 text-[11px] font-semibold disabled:opacity-40"
              type="button"
              :disabled="!activeSentence"
              @click="isEditModalOpen = true"
            >
              Edit
            </button>
            <button
              class="zee-card px-3 py-2 text-[11px] font-semibold text-red-300 disabled:opacity-40"
              type="button"
              :disabled="!activeSentence || isDeleting"
              @click="handleDelete"
            >
              {{ isDeleting ? 'Deleting…' : 'Delete' }}
            </button>
          </div>
        </div>

        <!-- Main card -->
        <div class="flex-1 min-h-0">
          <!-- Error -->
          <div v-if="isError" class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Couldn’t load sentences</div>
            <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">Try again.</div>
            <button class="zee-btn mt-4 w-full py-3" type="button" @click="handleReloadClick">
              Reload
            </button>
          </div>

          <!-- Loading -->
          <div v-else-if="isLoading" class="zee-card h-full overflow-hidden p-5">
            <div class="animate-pulse space-y-3">
              <div class="h-5 w-28 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-10 w-5/6 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-2/3 rounded bg-[color:var(--app-panel-muted)]"></div>
              <div class="h-4 w-3/4 rounded bg-[color:var(--app-panel-muted)]"></div>
            </div>
          </div>

          <!-- Empty -->
          <div v-else-if="emptyStateVisible" class="zee-card relative h-full overflow-hidden">
            <div
              class="pointer-events-none absolute left-1/2 top-1/2 h-32 w-32 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-70 blur-3xl"
              :style="{
                background: 'radial-gradient(circle, var(--app-accent-soft) 0%, transparent 72%)',
              }"
            />
            <div class="relative flex h-full items-center justify-center p-5">
              <div class="flex flex-col items-center gap-3">
                <div
                  class="flex h-14 w-14 items-center justify-center rounded-[20px] border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] text-[color:var(--app-accent)] shadow-[var(--app-card-shadow)]"
                >
                  <Icon icon="solar:microphone-3-bold-duotone" class="h-6 w-6" />
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2">
                  <button
                    class="zee-btn min-w-[120px] px-4 py-2.5 text-[13px] font-semibold"
                    type="button"
                    :disabled="isGenerationPending || isGenerating"
                    @click="handleGenerate"
                  >
                    Generate
                  </button>
                  <button
                    class="zee-card px-4 py-2.5 text-[13px] font-semibold"
                    type="button"
                    @click="isImportModalOpen = true"
                  >
                    Import
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Pending -->
          <div v-else-if="isGenerationPending" class="zee-card h-full overflow-hidden p-5">
            <div class="flex h-full flex-col justify-center text-center">
              <div
                class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] text-[color:var(--app-accent)]"
              >
                <Icon icon="svg-spinners:90-ring-with-bg" class="h-7 w-7" />
              </div>
              <div class="mt-4 text-base font-semibold">Generating audio sentences</div>
              <div class="mt-1 text-sm leading-relaxed text-[color:var(--app-text-muted)]">
                No refresh needed. We’ll show the sentences here as soon as they’re ready.
              </div>
              <div class="mt-3 text-[11px] font-medium text-[color:var(--app-text-muted)]">
                Waiting {{ generationWaitingLabel }}
              </div>

              <div class="mt-5">
                <div
                  class="h-2 w-full overflow-hidden rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-panel-muted)]"
                >
                  <div
                    class="h-full rounded-full"
                    :style="{
                      width: '55%',
                      background:
                        'linear-gradient(90deg, var(--app-accent), var(--app-accent-strong))',
                    }"
                  />
                </div>
              </div>

              <button
                class="zee-card mt-5 w-full py-3 text-sm font-semibold"
                type="button"
                @click="handleReloadClick"
              >
                Check now
              </button>
            </div>
          </div>

          <!-- Ready -->
          <div
            v-else-if="isReady && activeSentence"
            class="zee-card relative h-full overflow-hidden"
            :style="cardStyle"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
          >
            <!-- subtle glow -->
            <div
              class="pointer-events-none absolute -inset-10 opacity-60 blur-3xl"
              :style="{
                background:
                  'radial-gradient(60% 60% at 50% 10%, var(--app-accent-soft) 0%, transparent 70%)',
              }"
            />

            <div class="relative h-full min-h-0 p-5 flex flex-col">
              <!-- Top row: rate + translate toggle -->
              <div class="flex items-center justify-between gap-3 shrink-0">
                <!-- Rate pills -->
                <div class="flex items-center gap-2">
                  <button
                    v-for="r in playbackRateOptions"
                    :key="r"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-[11px] font-semibold transition active:scale-[0.99]"
                    :class="r === playbackRate ? rateActiveClass : rateIdleClass"
                    @click="setRate(r)"
                  >
                    {{ r }}x
                  </button>
                </div>

                <button
                  type="button"
                  class="rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] px-3 py-2 text-xs font-semibold text-[color:var(--app-text)] active:scale-[0.99]"
                  @click="showTranslation = !showTranslation"
                >
                  <div class="flex items-center gap-2">
                    <Icon
                      :icon="showTranslation ? 'solar:eye-closed-outline' : 'solar:eye-outline'"
                      class="h-4 w-4"
                    />
                    <span>{{ showTranslation ? 'Hide' : 'Show' }}</span>
                  </div>
                </button>
              </div>

              <!-- Sentence -->
              <div class="mt-5 flex-1 min-h-0 flex flex-col justify-center text-center">
                <div
                  class="text-[18px] font-semibold leading-relaxed tracking-tight text-[color:var(--app-text)]"
                >
                  {{ activeSentence.text }}
                </div>

                <div
                  v-if="showTranslation"
                  class="mt-4 rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] p-4"
                >
                  <div
                    class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]"
                  >
                    Translation
                  </div>
                  <div class="mt-1 text-base font-semibold leading-snug" dir="auto">
                    {{ activeSentence.translation || '—' }}
                  </div>
                </div>

                <div class="mt-4 text-xs text-[color:var(--app-text-muted)]">
                  Tap play, then repeat out loud.
                </div>
              </div>

              <!-- Bottom controls -->
              <div class="mt-3 grid grid-cols-3 gap-3 shrink-0">
                <button
                  class="zee-card flex h-9 w-9 items-center justify-center rounded-full active:scale-[0.99] disabled:opacity-40 mx-auto"
                  type="button"
                  :disabled="!hasPrev"
                  @click="goPrevLocal"
                  aria-label="Previous sentence"
                >
                  <Icon icon="solar:arrow-left-outline" class="h-4 w-4" />
                </button>

                <button
                  class="zee-btn py-2 text-xs font-semibold"
                  type="button"
                  @click="handlePlayClick"
                  :disabled="isAudioLoading"
                >
                  {{ isAudioPlaying ? 'Stop' : 'Play' }}
                </button>

                <button
                  class="zee-card flex h-9 w-9 items-center justify-center rounded-full active:scale-[0.99] disabled:opacity-40 mx-auto"
                  type="button"
                  :disabled="!hasNext"
                  @click="goNextLocal"
                  aria-label="Next sentence"
                >
                  <Icon icon="solar:arrow-right-outline" class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>

          <!-- Fallback -->
          <div v-else class="zee-card h-full overflow-hidden p-5">
            <div class="text-base font-semibold">Nothing to show</div>
            <button
              class="zee-btn mt-4 w-full py-3"
              type="button"
              :disabled="isGenerationPending || isGenerating"
              @click="handleGenerate"
            >
              Generate shadowing
            </button>
          </div>
        </div>
      </div>

      <!-- Toast -->
      <transition name="fade">
        <div v-if="toastMessage" class="fixed bottom-24 left-1/2 z-50 -translate-x-1/2 px-4">
          <div
            class="flex items-center gap-2 rounded-full border border-white/10 bg-[var(--app-surface-dark-elevated)]/90 px-4 py-2.5 text-xs font-medium text-white shadow-xl backdrop-blur-md"
          >
            <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
            {{ toastMessage }}
          </div>
        </div>
      </transition>
    </div>
  </section>

  <ShadowingSentenceJsonImportModal
    :open="isImportModalOpen"
    :lesson-id="lesson.id"
    @close="isImportModalOpen = false"
    @imported="handleImported"
  />

  <ShadowingSentenceEditModal
    :open="isEditModalOpen"
    :lesson-id="lesson.id"
    :sentence="activeSentence"
    @close="isEditModalOpen = false"
    @saved="handleEdited"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'
import type { LessonDetail } from '@/types/lesson'
import { useLessonShadowing } from '@/composables/useLessonShadowing'
import { useLessonContentGeneration } from '@/composables/useLessonContentGeneration'
import {
  deleteLessonSentence,
  fetchLessonSentenceTts,
  generateLessonShadowingSentences,
} from '@/api/lessonShadowing'
import ShadowingSentenceEditModal from '../shadowing/ShadowingSentenceEditModal.vue'
import ShadowingSentenceJsonImportModal from '../shadowing/ShadowingSentenceJsonImportModal.vue'

const props = defineProps<{ lesson: LessonDetail }>()

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

const showTranslation = ref(false)

/**
 * Audio
 */
const audioUrls = ref<Record<number, string>>({})
const isAudioLoading = ref(false)
const isAudioPlaying = ref(false)
const playbackRate = ref(1)
const playbackRateOptions = [0.75, 1, 1.25]
let audio: HTMLAudioElement | null = null

async function ensureAudio(sentenceId: number): Promise<string | null> {
  if (audioUrls.value[sentenceId]) return audioUrls.value[sentenceId]
  try {
    isAudioLoading.value = true
    const url = await fetchLessonSentenceTts(sentenceId)
    audioUrls.value = { ...audioUrls.value, [sentenceId]: url }
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
      if (audio) audio.currentTime = 0
    }
  } catch {
    isAudioPlaying.value = false
  }
}

function setRate(rate: number) {
  playbackRate.value = rate
  if (audio) audio.playbackRate = rate
}

watch(activeSentence, () => {
  if (audio && isAudioPlaying.value) {
    audio.pause()
    audio.currentTime = 0
    isAudioPlaying.value = false
  }
  showTranslation.value = false
})

/**
 * Generate / polling
 */
const toastMessage = ref('')
let toastTimeout: number | null = null

const pushToast = (message: string) => {
  toastMessage.value = message
  if (toastTimeout) clearTimeout(toastTimeout)
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 4000)
}

const generationTracker = useLessonContentGeneration({
  lessonId: props.lesson.id,
  feature: 'shadowing',
  reloadContent: async () => {
    await reload()
  },
  onReady: () => {
    pushToast('Shadowing sentences are ready')
  },
  onFailed: (state) => {
    pushToast(String(state?.message ?? 'Sentence generation failed'))
  },
})

const isGenerationPending = computed(() => generationTracker.isPending.value)
const generationWaitingLabel = computed(() => {
  const seconds = generationTracker.waitingSeconds.value
  if (seconds < 10) return 'a few seconds'
  return `${seconds}s`
})

const isGenerating = ref(false)
const isImportModalOpen = ref(false)
const isEditModalOpen = ref(false)
const isDeleting = ref(false)

const handleReloadClick = () => {
  void reload()
  void generationTracker.syncStatus()
}

const handleGenerate = async () => {
  if (isGenerating.value) return
  isGenerating.value = true
  try {
    const response = await generateLessonShadowingSentences(props.lesson.id, {
      replace_existing: true,
    })
    await generationTracker.beginTracking()
    pushToast(response.message ?? 'Shadowing sentence generation queued')
  } catch (e) {
    console.error(e)
    pushToast('Could not start shadowing generation')
  } finally {
    isGenerating.value = false
  }
}

const handleImported = async (count: number) => {
  await reload()
  pushToast(`${count} sentence${count > 1 ? 's' : ''} imported`)
}

const handleEdited = async () => {
  await reload(activeSentence.value?.id ?? null)
  pushToast('Sentence updated')
}

const handleDelete = async () => {
  if (!activeSentence.value || isDeleting.value) return

  const confirmed = window.confirm('Delete this sentence?')
  if (!confirmed) return

  isDeleting.value = true
  try {
    const currentId = activeSentence.value.id
    await deleteLessonSentence(props.lesson.id, currentId)
    await reload(currentId)
    pushToast('Sentence deleted')
  } catch (error) {
    console.error(error)
    pushToast('Could not delete sentence')
  } finally {
    isDeleting.value = false
  }
}

onBeforeUnmount(() => {
  if (toastTimeout) clearTimeout(toastTimeout)
  if (audio) {
    audio.pause()
    audio.currentTime = 0
    audio = null
  }
})

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)

/**
 * Swipe navigation
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

function goNextLocal() {
  if (!hasNext.value) return
  goNext()
}
function goPrevLocal() {
  if (!hasPrev.value) return
  goPrev()
}

function onPointerDown(e: PointerEvent) {
  if (!isReady.value || !activeSentence.value) return
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
 * Rate button classes
 */
const rateActiveClass =
  'border-[color:var(--app-border-strong)] bg-[color:var(--app-accent-soft)] text-[color:var(--app-accent)]'
const rateIdleClass =
  'border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] text-[color:var(--app-text-muted)]'
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition:
    opacity 0.25s ease,
    transform 0.25s ease;
}
.fade-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>

<template>
  <section class="h-full min-h-0">
    <div class="h-full min-h-0 overflow-hidden flex flex-col" style="background: var(--app-bg)">
      <!-- Scrollable content -->
      <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 pt-4 pb-6">
        <!-- Error -->
        <div v-if="isError" class="zee-card mt-4 p-5">
          <div class="text-base font-semibold">Couldn’t load grammar</div>
          <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">Try again.</div>
          <button class="zee-btn mt-4 w-full py-3" type="button" @click="reload">Reload</button>
        </div>

        <!-- Loading -->
        <div v-else-if="isLoading" class="zee-card mt-4 p-5">
          <div class="animate-pulse space-y-3">
            <div class="h-5 w-24 rounded bg-[color:var(--app-panel-muted)]"></div>
            <div class="h-10 w-5/6 rounded bg-[color:var(--app-panel-muted)]"></div>
            <div class="h-4 w-2/3 rounded bg-[color:var(--app-panel-muted)]"></div>
            <div class="h-4 w-3/4 rounded bg-[color:var(--app-panel-muted)]"></div>
          </div>
        </div>

        <!-- Empty -->
        <div v-else-if="emptyStateVisible" class="zee-card mt-4 p-5">
          <div class="text-base font-semibold">No grammar notes yet</div>
          <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
            Generate grammar points for this lesson.
          </div>
          <button
            class="zee-btn mt-4 w-full py-3"
            type="button"
            :disabled="isGenerationPending"
            @click="handleGenerateGrammar"
          >
            Generate grammar
          </button>
        </div>

        <!-- Pending -->
        <div v-else-if="isGenerationPending" class="zee-card mt-4 p-5">
          <div class="text-base font-semibold">Generating…</div>
          <div class="mt-1 text-sm text-[color:var(--app-text-muted)]">
            We’re preparing grammar notes. This may take a bit.
          </div>
          <button class="zee-btn mt-4 w-full py-3" type="button" @click="reload">
            Check again
          </button>
        </div>

        <!-- List -->
        <div v-else class="mt-4 space-y-3">
          <div
            v-for="p in grammarPoints"
            :key="p.id"
            class="zee-card overflow-hidden"
          >
            <!-- Item header -->
            <button
              type="button"
              class="w-full px-4 py-4 text-left active:scale-[0.995]"
              @click="toggle(p.id)"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-base font-semibold leading-snug text-[color:var(--app-text)] line-clamp-2">
                    {{ p.title || (p as any).name || 'Grammar point' }}
                  </div>

                  <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span
                      v-if="parseMeta(p).importance"
                      class="rounded-full border border-[color:var(--app-border)]
                             bg-[color:var(--app-accent-soft)] px-2.5 py-1 text-[11px]
                             font-semibold text-[color:var(--app-accent)]"
                    >
                      {{ parseMeta(p).importance }}
                    </span>

                    <span
                      v-for="t in parseMeta(p).tags"
                      :key="t"
                      class="rounded-full border border-[color:var(--app-border)]
                             bg-[color:var(--app-surface-elevated)] px-2.5 py-1 text-[11px]
                             font-semibold text-[color:var(--app-text-muted)]"
                    >
                      {{ t }}
                    </span>
                  </div>
                </div>

                <div
                  class="shrink-0 grid h-10 w-10 place-items-center rounded-2xl border border-[color:var(--app-border)]
                         bg-[color:var(--app-surface-elevated)]"
                >
                  <Icon
                    :icon="expandedId === p.id ? 'solar:alt-arrow-up-outline' : 'solar:alt-arrow-down-outline'"
                    class="h-5 w-5 text-[color:var(--app-text)]"
                  />
                </div>
              </div>
            </button>

            <!-- Item body -->
            <div v-if="expandedId === p.id" class="px-4 pb-4">
              <div class="h-px bg-[color:var(--app-border)]/70 mb-4"></div>

              <div class="space-y-4" :dir="detectDir(p)">
                <!-- Pattern -->
                <div v-if="patternLines(p).length" class="rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] p-4">
                  <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Pattern</div>
                  <div class="mt-2 space-y-1">
                    <div
                      v-for="(line, idx) in patternLines(p)"
                      :key="idx"
                      class="text-sm font-semibold leading-relaxed text-[color:var(--app-text)]"
                    >
                      {{ line }}
                    </div>
                  </div>
                </div>

                <!-- Summary -->
                <div v-if="p.description" class="rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-panel)] p-4">
                  <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Summary</div>
                  <div class="mt-2 text-sm leading-relaxed text-[color:var(--app-text)] whitespace-pre-line">
                    {{ p.description }}
                  </div>
                </div>

                <!-- Explanation -->
                <div v-if="p.explanation" class="rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-panel)] p-4">
                  <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Explanation</div>
                  <div class="mt-2 text-sm leading-relaxed text-[color:var(--app-text)] whitespace-pre-line">
                    {{ p.explanation }}
                  </div>
                </div>

                <!-- Examples -->
                <div v-if="parseExamples(p).length" class="rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] p-4">
                  <div class="text-[11px] font-semibold tracking-wide text-[color:var(--app-text-muted)]">Examples</div>

                  <div class="mt-3 space-y-3">
                    <div
                      v-for="(ex, i) in parseExamples(p)"
                      :key="i"
                      class="rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-3"
                    >
                      <div class="text-sm font-semibold leading-relaxed text-[color:var(--app-text)]">
                        {{ ex.sentence }}
                      </div>
                      <div v-if="ex.translation" class="mt-2 text-sm leading-relaxed text-[color:var(--app-text-muted)]" dir="auto">
                        {{ ex.translation }}
                      </div>
                    </div>
                  </div>
                </div>

                <div class="pt-1 text-xs text-[color:var(--app-text-muted)] flex items-center justify-between">
                  <span>#{{ p.id }}</span>
                  <span>{{ (p as any).created_at ? 'Saved' : '' }}</span>
                </div>
              </div>
            </div>
          </div>

          <div class="h-2"></div>
        </div>
      </div>

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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useLessonGrammar } from '@/composables/useLessonGrammar'
import { generateLessonGrammar } from '@/api/lessonGrammar'

const props = defineProps<{ lessonId: number }>()

const {
  grammarPoints,
  total,
  isLoading,
  isError,
  isEmpty,
  isReady,
  reload,
} = useLessonGrammar(props.lessonId)

type UiExample = { sentence: string; translation?: string }

const expandedId = ref<number | null>(null)

const toastMessage = ref('')
let toastTimeout: number | null = null

const isGenerationPending = ref(false)
const pendingBaselineSignature = ref<string | null>(null)
let pollingInterval: number | null = null

const pointsSignature = computed(() => grammarPoints.value.map((p) => p.id).join('-'))

const generationStorageKey = computed(() => `zeel:grammar-generating:${props.lessonId}`)

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

const emptyStateVisible = computed(() => isEmpty.value && !isGenerationPending.value)

const totalLabel = computed(() => (total.value ? `${total.value} items` : '0 items'))
const progressPercent = computed(() => (total.value === 0 ? 0 : 100))

const pushToast = (message: string) => {
  toastMessage.value = message
  if (toastTimeout) clearTimeout(toastTimeout)
  toastTimeout = window.setTimeout(() => {
    toastMessage.value = ''
    toastTimeout = null
  }, 4000)
}

const startPolling = () => {
  if (pollingInterval !== null) return
  pollingInterval = window.setInterval(() => reload(), 6000)
}

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

const handleGenerateGrammar = async () => {
  if (isGenerationPending.value) return
  try {
    isGenerationPending.value = true
    persistGenerationState(true)
    pendingBaselineSignature.value = pointsSignature.value || null
    await generateLessonGrammar(props.lessonId, {})
    startPolling()
    pushToast('Grammar notes generation queued')
  } catch {
    isGenerationPending.value = false
    persistGenerationState(false)
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Failed to queue grammar generation')
  }
}

watch(pointsSignature, (signature) => {
  if (!isGenerationPending.value) return
  if (signature && signature !== pendingBaselineSignature.value) {
    isGenerationPending.value = false
    persistGenerationState(false)
    pendingBaselineSignature.value = null
    stopPolling()
    pushToast('Grammar notes are ready')
  }
})

onMounted(() => {
  const pending = loadGenerationState()
  if (pending) {
    isGenerationPending.value = true
    startPolling()
  }

  // expand first item when ready
  watch(
    () => grammarPoints.value.length,
    (len) => {
      if (len && expandedId.value === null) expandedId.value = grammarPoints.value[0]?.id ?? null
    },
    { immediate: true },
  )
})

onBeforeUnmount(() => {
  if (toastTimeout) clearTimeout(toastTimeout)
  stopPolling()
})

function toggle(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

function parseMeta(p: any): { importance?: string; tags: string[] } {
  const raw = p?.meta
  if (!raw) return { tags: [] }
  if (typeof raw === 'object') return { importance: raw.importance, tags: raw.tags || [] }
  try {
    const obj = JSON.parse(raw)
    return { importance: obj?.importance, tags: obj?.tags || [] }
  } catch {
    return { tags: [] }
  }
}

function parseExamples(p: any): UiExample[] {
  const raw = p?.examples
  if (!raw) return []
  if (Array.isArray(raw)) {
    return raw
      .map((e: any) => ({
        sentence: e.sentence || e.text || '',
        translation: e.translation || '',
      }))
      .filter((e: UiExample) => e.sentence)
  }
  if (typeof raw === 'string') {
    try {
      const arr = JSON.parse(raw)
      if (!Array.isArray(arr)) return []
      return arr
        .map((e: any) => ({
          sentence: e.sentence || e.text || '',
          translation: e.translation || '',
        }))
        .filter((e: UiExample) => e.sentence)
    } catch {
      return []
    }
  }
  return []
}

function patternLines(p: any): string[] {
  const pattern = p?.pattern || ''
  return String(pattern)
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.length > 0)
}

function detectDir(p: any): 'rtl' | 'ltr' {
  const base =
    (p?.description ?? '') +
    (p?.explanation ?? '') +
    (parseExamples(p)[0]?.translation || '')
  const rtlPattern = /[\u0590-\u08FF]/
  return rtlPattern.test(base) ? 'rtl' : 'ltr'
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
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

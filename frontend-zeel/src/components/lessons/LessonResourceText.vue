<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import type { LessonDetail } from '@/types/lesson'
import { Icon } from '@iconify/vue'
import LessonReadAloudPlayer from '@/components/lessons/LessonReadAloudPlayer.vue'

const props = defineProps<{ lesson: LessonDetail }>()

const isSerif = ref(false)
const fontSize = ref<'normal' | 'large'>('normal')
const showReadAloud = ref(false)

const isMobile = ref(false)
const isReadExpanded = ref(false)
const isAudioPlaying = ref(false)
const readAloudActiveTokenIndex = ref<number | null>(null)
const readAloudHighlightAvailable = ref(false)
const readAloudTimings = ref<Array<{ text: string }>>([])
const readAloudMappedBlocks = ref<Array<{
  segments: Array<{ type: 'text'; text: string } | { type: 'token'; text: string; tokenIndex: number }>
}>>([])

let mq: MediaQueryList | null = null
let onMqChange: ((e: MediaQueryListEvent) => void) | null = null

const lessonPack = computed(() => (props.lesson as any)?.lesson_pack ?? null)
const isTextAi = computed(() => (props.lesson as any)?.resource_type === 'text_ai')
const isReadAloudSupported = computed(() => {
  const resourceType = String((props.lesson as any)?.resource_type ?? '').toLowerCase()
  return resourceType !== 'video' && resourceType !== 'youtube'
})
const canReadAloud = computed(() => {
  if (!isReadAloudSupported.value) {
    return false
  }

  return String((props.lesson as any)?.original_text ?? '').trim().length > 0
})

const dialogueRows = computed(() => {
  const d = lessonPack.value?.dialogue
  return Array.isArray(d) ? d : []
})

const hasDialogue = computed(() => dialogueRows.value.length > 0)
const leftSpeaker = computed(() => dialogueRows.value?.[0]?.speaker ?? '')


const storyText = computed(() => {
  if (hasDialogue.value) return ''
  const t = lessonPack.value?.lesson_text
  const fallback = (props.lesson as any)?.original_text
  return String(t ?? fallback ?? '')
})

const toggleFont = () => {
  isSerif.value = !isSerif.value
}

const toggleSize = () => {
  fontSize.value = fontSize.value === 'normal' ? 'large' : 'normal'
}

const toggleRead = () => {
  if (!canReadAloud.value) return

  const next = !showReadAloud.value
  showReadAloud.value = next

  if (isMobile.value) {
    isReadExpanded.value = next
    if (!next) {
      isAudioPlaying.value = false
    }
  } else {
    isReadExpanded.value = next
  }
}

const toggleReadExpand = () => {
  if (!showReadAloud.value) return
  isReadExpanded.value = !isReadExpanded.value
}

const closeReadAloud = () => {
  if (!showReadAloud.value) return
  showReadAloud.value = false
  isReadExpanded.value = false
  isAudioPlaying.value = false
}

const readBottomInset = computed(() => {
  return 'calc(var(--app-bottom-nav-height, 72px) + env(safe-area-inset-bottom))'
})

const headerOnlyHeight = computed(() => '56px')

const textBottomPadding = computed(() => {
  if (!(showReadAloud.value && isMobile.value)) return undefined
  if (isReadExpanded.value) return 'calc(var(--read-bottom-inset) + 52vh)'
  return `calc(var(--read-bottom-inset) + ${headerOnlyHeight.value})`
})

const escapeHtml = (s: string) =>
  s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')

const decodeHtml = (html: string) => {
  if (typeof document === 'undefined') return html
  const txt = document.createElement('textarea')
  txt.innerHTML = html
  return txt.value
}

const stripHtml = (html: string) => {
  if (typeof document === 'undefined') return html.replace(/<[^>]*>/g, '')
  const div = document.createElement('div')
  div.innerHTML = html
  return (div.textContent ?? '').toString()
}

const hashText = (s: string) => {
  let h = 2166136261
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return (h >>> 0).toString(16)
}

const formatText = (text: string) => {
  const decoded = decodeHtml(text)
  let safe = escapeHtml(decoded)
  safe = safe.replace(
    /\[(.*?)\]/g,
    '<span class="text-[0.75em] font-medium tracking-wide uppercase text-[var(--app-text-muted)] opacity-70">[$1]</span>',
  )
  return safe
}

const hasContent = computed(() => {
  if (hasDialogue.value) return true
  return storyText.value.trim().length > 0
})

const typedKey = computed(() => {
  const id = (props.lesson as any)?.id ?? 'x'
  return `zeel_typed_once_v1_${id}_${hashText(storyText.value)}`
})

const wasTypedOnce = ref(false)
const isTyping = ref(false)
const typedBlocks = ref<string[]>([])
const typedDone = ref<boolean[]>([])
let abortTyping = false

const loadTypedOnceFlag = () => {
  if (typeof window === 'undefined') return false
  return localStorage.getItem(typedKey.value) === '1'
}

const saveTypedOnceFlag = () => {
  if (typeof window === 'undefined') return
  localStorage.setItem(typedKey.value, '1')
}

const formattedBlocks = computed(() => {
  const original = storyText.value
  if (!original.trim()) return []

  return original
    .split('\n')
    .filter((p) => p.trim().length > 0)
    .map((p) => {
      const html = formatText(p)
      return {
        html,
        plain: stripHtml(html),
      }
    })
})

const tokenizeDisplayBlock = (text: string) => {
  const segments: Array<{ type: 'text'; text: string } | { type: 'token'; text: string }> = []
  const regex = /(\s+|[^\p{L}\p{N}'’-]+|[\p{L}\p{N}'’-]+)/gu
  const matches = text.match(regex) ?? []

  matches.forEach((part) => {
    if (/[\p{L}\p{N}]/u.test(part)) {
      segments.push({ type: 'token', text: part })
      return
    }

    segments.push({ type: 'text', text: part })
  })

  return segments
}

const rebuildHighlightMapping = (timedTokens: Array<{ text: string }>) => {
  if (!timedTokens.length || hasDialogue.value || isTyping.value) {
    readAloudMappedBlocks.value = []
    readAloudHighlightAvailable.value = false
    return
  }

  let tokenCursor = 0
  const mappedBlocks = formattedBlocks.value.map((block) => {
    const plain = block.plain ?? ''
    const baseSegments = tokenizeDisplayBlock(plain)
    const segments = baseSegments.map((segment) => {
      if (segment.type !== 'token') {
        return segment
      }

      const mapped = {
        type: 'token' as const,
        text: segment.text,
        tokenIndex: tokenCursor,
      }

      tokenCursor += 1
      return mapped
    })

    return { segments }
  })

  readAloudMappedBlocks.value = mappedBlocks
  readAloudHighlightAvailable.value = tokenCursor >= timedTokens.length && tokenCursor > 0
}

const handleReadAloudSyncChange = (payload: {
  activeTokenIndex: number | null
  timings: Array<{ text: string }>
  available: boolean
}) => {
  readAloudActiveTokenIndex.value = payload.activeTokenIndex
  readAloudTimings.value = payload.timings

  if (!payload.available) {
    readAloudHighlightAvailable.value = false
    readAloudMappedBlocks.value = []
    return
  }

  rebuildHighlightMapping(payload.timings)
}

const resetTypingState = () => {
  abortTyping = true
  isTyping.value = false
  typedBlocks.value = []
  typedDone.value = []
  abortTyping = false
}

const runTypewriterOnce = async () => {
  resetTypingState()

  if (!isTextAi.value) {
    return
  }

  if (!storyText.value.trim()) return

  wasTypedOnce.value = loadTypedOnceFlag()
  if (wasTypedOnce.value) return

  const blocks = formattedBlocks.value
  if (!blocks.length) return

  isTyping.value = true
  typedBlocks.value = blocks.map(() => '')
  typedDone.value = blocks.map(() => false)

  await nextTick()

  const delay = (ms: number) => new Promise((r) => setTimeout(r, ms))

  for (let i = 0; i < blocks.length; i++) {
    if (abortTyping) return

    const block = blocks[i]
    if (!block) continue

    const text = block.plain ?? ''
    let acc = ''

    for (let j = 0; j < text.length; j++) {
      if (abortTyping) return
      acc += text[j]
      typedBlocks.value[i] = acc
      await delay(10)
    }

    typedDone.value[i] = true
    await delay(80)
  }

  isTyping.value = false
  wasTypedOnce.value = true
  saveTypedOnceFlag()
}

watch(
  () => [storyText.value, hasDialogue.value],
  async () => {
    if (hasDialogue.value) {
      resetTypingState()
      return
    }
    await runTypewriterOnce()
  },
  { immediate: true },
)

watch(
  () => [formattedBlocks.value, hasDialogue.value, isTyping.value] as const,
  () => {
    if (!readAloudTimings.value.length) {
      return
    }

    rebuildHighlightMapping(readAloudTimings.value)
  },
  { deep: true },
)

const copySourceText = computed(() => {
  if (hasDialogue.value) {
    return dialogueRows.value.map((r) => `${r.speaker}: ${r.text}`).join('\n')
  }
  return storyText.value
})

const copyText = async () => {
  try {
    await navigator.clipboard.writeText(copySourceText.value || '')
  } catch (e) {
    console.error(e)
  }
}

const onKeyDown = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && showReadAloud.value) {
    closeReadAloud()
  }
}

watch(
  () => canReadAloud.value,
  (enabled) => {
    if (!enabled) {
      showReadAloud.value = false
      isReadExpanded.value = false
      isAudioPlaying.value = false
    }
  },
)

onMounted(() => {
  mq = window.matchMedia('(max-width: 767px)')
  isMobile.value = mq.matches

  onMqChange = (e) => {
    isMobile.value = e.matches
    if (!isMobile.value) isReadExpanded.value = false
  }

  mq.addEventListener?.('change', onMqChange)
  window.addEventListener('keydown', onKeyDown)
})

onBeforeUnmount(() => {
  abortTyping = true
  if (mq && onMqChange) mq.removeEventListener?.('change', onMqChange)
  window.removeEventListener('keydown', onKeyDown)
})
</script>

<template>
  <div
    class="flex flex-col w-full h-full overflow-hidden bg-[var(--app-surface-elevated)] rounded-[24px] border border-[var(--app-border)] shadow-sm relative isolate dark:bg-[var(--app-surface-dark-elevated)]/50"
    :style="{ '--read-bottom-inset': readBottomInset }"
  >
    <header
      class="sticky top-0 z-20 px-5 pt-3 pb-3 border-b border-[var(--app-border)] bg-[var(--app-surface)]/80 backdrop-blur-md dark:bg-[#1a1a1c]/80 rounded-t-[24px]"
    >
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-[var(--app-text-muted)]">
          <Icon :icon="hasDialogue ? 'solar:chat-round-dots-bold-duotone' : 'solar:document-text-bold-duotone'" class="h-4 w-4" />
          <span>{{ hasDialogue ? 'Dialogue' : 'Lesson Text' }}</span>
        </div>

        <div class="flex items-center gap-1">
          <button
            v-if="canReadAloud"
            @click="toggleRead"
            class="h-8 px-3 flex items-center gap-2 rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)] hover:text-[var(--app-text)]"
            :title="isMobile ? (showReadAloud ? 'Hide Read Aloud' : 'Show Read Aloud') : 'Open Read Aloud'"
          >
            <Icon
              :icon="isAudioPlaying ? 'svg-spinners:bars-scale' : 'solar:soundwave-bold-duotone'"
              class="h-4 w-4"
              :class="isAudioPlaying ? 'text-[var(--app-accent)]' : ''"
            />
            <span class="text-xs font-bold">
              {{ isMobile ? (showReadAloud ? 'Close' : 'Read Aloud') : 'Read Aloud' }}
            </span>
          </button>

          <button
            @click="toggleFont"
            class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
            :title="isSerif ? 'Switch to Sans' : 'Switch to Serif'"
          >
            <span class="text-xs font-bold">{{ isSerif ? 'Aa' : 'Tt' }}</span>
          </button>

          <button
            @click="toggleSize"
            class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
            title="Toggle Font Size"
          >
            <Icon icon="solar:text-field-linear" class="h-4 w-4" />
          </button>

          <div class="w-px h-4 bg-[var(--app-border)] mx-1"></div>

          <button
            @click="copyText"
            class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)] hover:text-[var(--app-accent)]"
            title="Copy"
          >
            <Icon icon="solar:copy-bold-duotone" class="h-4 w-4" />
          </button>
        </div>
      </div>

      <transition name="slide-fade">
        <div v-if="showReadAloud && !isMobile" class="mt-3">
          <LessonReadAloudPlayer
            :lesson-id="lesson.id"
            variant="sheet"
            @playing-change="isAudioPlaying = $event"
            @sync-change="handleReadAloudSyncChange"
          />
        </div>
      </transition>

    </header>

    <div
      class="flex-1 overflow-y-auto custom-scrollbar p-6 md:p-8 min-h-0"
      :style="textBottomPadding ? { paddingBottom: textBottomPadding } : undefined"
    >
      <article
        class="max-w-2xl mx-auto space-y-6"
        :class="[
          isSerif ? 'font-serif' : 'font-sans',
          fontSize === 'large' ? 'text-lg md:text-xl' : 'text-base md:text-lg',
        ]"
      >
        <div
          v-if="!hasContent && isTextAi"
          class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-5"
        >
          <div class="flex items-center gap-3">
            <Icon icon="svg-spinners:270-ring-with-bg" class="h-5 w-5 text-[var(--app-accent)]" />
            <div class="min-w-0">
              <div class="text-sm font-semibold text-[var(--app-text)]">
                {{ hasDialogue ? 'Generating dialogue…' : 'Generating lesson text…' }}
              </div>
              <div class="text-xs text-[var(--app-text-muted)] mt-1">Please wait a moment.</div>
            </div>
          </div>

          <div class="mt-4 space-y-3">
            <div class="h-3 w-10/12 rounded-full bg-[var(--app-border)]/70 animate-pulse"></div>
            <div class="h-3 w-11/12 rounded-full bg-[var(--app-border)]/60 animate-pulse"></div>
            <div class="h-3 w-9/12 rounded-full bg-[var(--app-border)]/60 animate-pulse"></div>
          </div>
        </div>

        <template v-else>
          <template v-if="!hasDialogue">
            <div
              v-for="(block, index) in formattedBlocks"
              :key="index"
              class="leading-relaxed text-[var(--app-text)] dark:text-slate-100"
            >
              <template v-if="isTyping && !typedDone[index]">
                <span class="whitespace-pre-wrap">{{ typedBlocks[index] }}</span>
                <span class="inline-block w-[0.6ch] animate-pulse text-[var(--app-accent)]">▍</span>
              </template>

              <template v-else>
                <template v-if="readAloudHighlightAvailable && readAloudMappedBlocks[index]?.segments?.length">
                  <span class="whitespace-pre-wrap">
                    <template
                      v-for="(segment, segmentIndex) in readAloudMappedBlocks[index]?.segments"
                      :key="`${index}-${segmentIndex}`"
                    >
                      <span
                        v-if="segment.type === 'token'"
                        class="transition-[background-color,box-shadow] duration-150 [box-decoration-break:clone]"
                        :class="segment.tokenIndex === readAloudActiveTokenIndex
                          ? 'bg-amber-300/35 rounded-[3px] shadow-[0_0_0_1px_rgba(245,158,11,0.35)]'
                          : ''"
                      >
                        {{ segment.text }}
                      </span>
                      <span v-else>{{ segment.text }}</span>
                    </template>
                  </span>
                </template>
                <span v-else class="whitespace-pre-wrap" v-html="block.html"></span>
              </template>
            </div>
          </template>

          <div v-if="hasDialogue" class="space-y-2">
            <div
              v-for="(row, i) in dialogueRows"
              :key="i"
              class="flex"
              :class="row.speaker === leftSpeaker ? 'justify-start' : 'justify-end'"
            >
              <div
                class="max-w-[82%] rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm shadow-sm"
                :class="row.speaker === leftSpeaker ? '' : 'bg-[var(--app-surface-elevated)]'"
              >
                <div class="text-[10px] font-bold opacity-70">{{ row.speaker }}</div>
                <div class="mt-1 leading-relaxed whitespace-pre-wrap" v-html="formatText(String(row?.text ?? ''))"></div>
              </div>
            </div>
          </div>
        </template>
      </article>
    </div>

    <transition name="bottom-player">
      <div
        v-if="showReadAloud && isMobile && canReadAloud"
        class="fixed inset-x-0 z-[200] px-3 pb-3 pointer-events-none"
        :style="{ bottom: 'var(--read-bottom-inset)' }"
      >
        <div
          class="mx-auto w-full max-w-xl pointer-events-auto rounded-3xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl overflow-hidden"
        >
          <div
            class="flex items-center justify-between px-4 py-3"
            :class="isReadExpanded ? 'border-b border-[var(--app-border)]' : ''"
          >
            <button
              @click="toggleReadExpand"
              class="flex items-center gap-2 text-xs font-bold tracking-widest uppercase text-[var(--app-text-muted)] hover:text-[var(--app-text)] transition"
              title="Expand / Collapse"
            >
              <Icon
                :icon="isAudioPlaying ? 'svg-spinners:bars-scale' : 'solar:soundwave-bold-duotone'"
                class="h-4 w-4"
                :class="isAudioPlaying ? 'text-[var(--app-accent)]' : ''"
              />
              <span>Read Aloud</span>
              <Icon
                :icon="isReadExpanded ? 'solar:alt-arrow-down-linear' : 'solar:alt-arrow-up-linear'"
                class="h-4 w-4 opacity-70"
              />
            </button>

            <button
              @click="closeReadAloud"
              class="h-9 w-9 flex items-center justify-center rounded-xl hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
              title="Close"
            >
              <Icon icon="solar:close-circle-bold" class="h-5 w-5" />
            </button>
          </div>

          <div
            class="overflow-hidden transition-[max-height,opacity] duration-200 ease-out"
            :class="isReadExpanded ? 'max-h-[45vh] opacity-100' : 'max-h-0 opacity-0 pointer-events-none'"
          >
            <div class="p-3">
              <LessonReadAloudPlayer
                :lesson-id="lesson.id"
                variant="sheet"
                @playing-change="isAudioPlaying = $event"
                @sync-change="handleReadAloudSyncChange"
              />
            </div>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<style scoped>
article {
  transition: font-size 0.2s ease, font-family 0.2s ease;
}

.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: all 0.2s ease-out;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
  transform: translateY(10px);
}

.bottom-player-enter-active,
.bottom-player-leave-active {
  transition: transform 0.22s ease, opacity 0.22s ease;
}
.bottom-player-enter-from,
.bottom-player-leave-to {
  transform: translateY(12px);
  opacity: 0;
}

</style>

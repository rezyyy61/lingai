<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import type { LessonDetail } from '@/types/lesson'
import { fetchLesson } from '@/api/lessonApi'

const props = defineProps<{
  lesson: LessonDetail
  compact?: boolean
}>()

const emit = defineEmits<{
  updated: [lesson: LessonDetail]
  toast: [message: string]
}>()

const lesson = ref<LessonDetail>(props.lesson)

watch(
  () => props.lesson,
  (newLesson) => {
    lesson.value = newLesson
  },
  { deep: true },
)

type AnalysisSection = {
  label: string
  value: string | null
}

const sections = computed<AnalysisSection[]>(() => [
  { label: 'Overview', value: lesson.value.analysis_overview ?? null },
  { label: 'Grammar notes', value: lesson.value.analysis_grammar ?? null },
  { label: 'Vocabulary notes', value: lesson.value.analysis_vocabulary ?? null },
  { label: 'Study tips', value: lesson.value.analysis_study_tips ?? null },
])

const hasAnalysis = computed(() => sections.value.some((section) => !!section.value))

const shouldPoll = computed(() => !hasAnalysis.value)

const analysisDirection = computed<'rtl' | 'ltr'>(() => {
  const metaDir = lesson.value.analysis_meta?.language_direction
  if (metaDir === 'rtl' || metaDir === 'ltr') return metaDir

  const text = sections.value.map((section) => section.value ?? '').join(' ')
  const rtlPattern = /[\u0590-\u08FF]/
  return rtlPattern.test(text) ? 'rtl' : 'ltr'
})

const visibleText = ref<Record<string, string>>({})
const isTyping = ref(false)
let typingTimeout: number | null = null
let pollingInterval: number | null = null

const ANALYSIS_SEEN_KEY = 'zeel:analysis-seen-lessons'

const loadSeenCache = (): Record<string, boolean> => {
  if (typeof window === 'undefined') return {}
  try {
    const raw = window.localStorage.getItem(ANALYSIS_SEEN_KEY)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}

const seenAnimationCache = ref<Record<string, boolean>>(loadSeenCache())

const persistSeenCache = () => {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(ANALYSIS_SEEN_KEY, JSON.stringify(seenAnimationCache.value))
  } catch {
  }
}

const lessonKey = computed(() => String(lesson.value.id))

const hasSeenAnimation = computed(() => {
  if (lesson.value.analysis_meta?.has_shown_animation) return true
  return !!seenAnimationCache.value[lessonKey.value]
})

const shouldAnimate = computed(() => hasAnalysis.value && !hasSeenAnimation.value)

const markAnimationSeen = () => {
  if (hasSeenAnimation.value) return
  seenAnimationCache.value = {
    ...seenAnimationCache.value,
    [lessonKey.value]: true,
  }
  persistSeenCache()
}

const resetTyping = () => {
  visibleText.value = sections.value.reduce<Record<string, string>>((acc, section) => {
    if (section.value) {
      acc[section.label] = ''
    }
    return acc
  }, {})
}

const stopTyping = () => {
  if (typingTimeout) {
    clearTimeout(typingTimeout)
    typingTimeout = null
  }
  isTyping.value = false
}

const revealAll = () => {
  stopTyping()
  sections.value.forEach((section) => {
    if (section.value) {
      visibleText.value[section.label] = section.value
    }
  })
  markAnimationSeen()
}

const startTyping = () => {
  if (!shouldAnimate.value) {
    revealAll()
    return
  }

  resetTyping()
  isTyping.value = true

  const totalSections = sections.value.filter(
    (section): section is AnalysisSection & { value: string } => !!section.value,
  )

  const totalChars = totalSections.reduce((sum, section) => {
    return sum + section.value.length
  }, 0)

  const charsPerStep = 2
  const stepInterval = 25
  const expectedDuration = (totalChars / charsPerStep) * stepInterval
  const minDuration = 3000
  const maxDuration = 20000
  const maxTypingDuration = Math.min(Math.max(expectedDuration * 1.2, minDuration), maxDuration)

  const startedAt = Date.now()
  let sectionIndex = 0
  let charIndex = 0

  const typeNext = () => {
    if (Date.now() - startedAt > maxTypingDuration) {
      revealAll()
      return
    }

    if (sectionIndex >= totalSections.length) {
      isTyping.value = false
      typingTimeout = null
      markAnimationSeen()
      return
    }

    const section = totalSections[sectionIndex]
    if (!section) {
      isTyping.value = false
      typingTimeout = null
      markAnimationSeen()
      return
    }
    const content = section.value ?? ''

    if (charIndex <= content.length) {
      visibleText.value[section.label] = content.slice(0, charIndex)
      charIndex += charsPerStep
    } else {
      sectionIndex += 1
      charIndex = 0
    }

    typingTimeout = window.setTimeout(typeNext, stepInterval)
  }

  typeNext()
}

const loadLatestLesson = async () => {
  try {
    const latest = await fetchLesson(lesson.value.id)
    lesson.value = latest
    emit('updated', latest)

    if (
      latest.analysis_overview ||
      latest.analysis_grammar ||
      latest.analysis_vocabulary ||
      latest.analysis_study_tips
    ) {
      stopPolling()
      emit('toast', 'Lesson analysis is ready')
    }
  } catch (error) {
    console.error('Failed to poll lesson analysis', error)
  }
}

const startPolling = () => {
  if (pollingInterval !== null) return
  pollingInterval = window.setInterval(() => {
    loadLatestLesson()
  }, 6000)
}

const stopPolling = () => {
  if (pollingInterval !== null) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

const analysisSignature = computed(() =>
  sections.value.map((section) => section.value ?? '').join('||'),
)

const syncAnalysisState = () => {
  stopTyping()

  if (hasAnalysis.value) {
    if (shouldAnimate.value) {
      startTyping()
    } else {
      revealAll()
    }
  } else {
    resetTyping()
  }
}

watch(
  () => [lessonKey.value, analysisSignature.value],
  () => {
    syncAnalysisState()
  },
  { immediate: true },
)

watch(
  shouldPoll,
  (pending) => {
    if (pending) {
      startPolling()
    } else {
      stopPolling()
    }
  },
  { immediate: true },
)

onMounted(() => {
  syncAnalysisState()
})

onBeforeUnmount(() => {
  stopTyping()
  stopPolling()
})
</script>

<template>
  <section
    class="w-full text-[var(--app-text)] transition dark:text-white"
    :class="props.compact
      ? 'rounded-none border-0 bg-transparent p-0 shadow-none'
      : 'rounded-[28px] border border-[var(--app-border)] bg-[var(--app-panel)] p-6 shadow-[var(--app-card-shadow-strong)] dark:border-white/10 dark:bg-[var(--app-surface-dark)]/90 dark:shadow-[0_25px_80px_rgba(0,0,0,0.5)]'
    "
  >
    <div class="flex items-start justify-between">
      <div>
        <p class="text-[11px] uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-white/60">
          {{ props.compact ? 'Summary' : 'Teacher’s summary' }}
        </p>
        <h3
          v-if="!props.compact"
          class="mt-2 text-2xl font-semibold"
        >
          Lesson analysis
        </h3>
      </div>
      <button
        v-if="!props.compact && hasAnalysis && isTyping"
        class="text-xs uppercase tracking-[0.25em] text-[var(--app-accent)] hover:text-[var(--app-accent-strong)]"
        @click="revealAll"
      >
        Skip animation
      </button>
    </div>

    <div v-if="shouldPoll" class="mt-4 space-y-4">
      <div class="flex items-center gap-3 text-sm text-[var(--app-text-muted)] dark:text-white/70">
        <svg class="h-4 w-4 animate-spin text-[var(--app-accent)]" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 0 1 8-8v3.5a4.5 4.5 0 0 0-4.5 4.5H4Z"
          />
        </svg>
        Teacher is analyzing this lesson…
      </div>
      <p class="text-xs text-[var(--app-text-muted)] dark:text-white/60">
        This usually takes a short moment. We’ll show the teacher’s summary as soon as it’s ready.
      </p>
      <div class="space-y-2">
        <div class="h-3 w-full rounded-full bg-[var(--app-panel-muted)] dark:bg-white/5"></div>
        <div class="h-3 w-11/12 rounded-full bg-[var(--app-panel-muted)] dark:bg-white/5"></div>
        <div class="h-3 w-10/12 rounded-full bg-[var(--app-panel-muted)] dark:bg-white/5"></div>
      </div>
    </div>

    <div v-else-if="hasAnalysis">
      <div
        class="mt-5 space-y-6"
        :dir="analysisDirection"
        :class="analysisDirection === 'rtl' ? 'text-right' : 'text-left'"
      >
        <template v-for="section in sections" :key="section.label">
          <div v-if="section.value" class="space-y-2">
            <p class="text-[11px] uppercase tracking-[0.25em] text-[var(--app-text-muted)] dark:text-white/60">
              {{ section.label }}
            </p>
            <p
              class="leading-relaxed text-[var(--app-text)] dark:text-white/90"
              :class="props.compact ? 'text-sm' : 'text-base'"
            >
              {{ visibleText[section.label] ?? section.value }}
              <span
                v-if="isTyping && visibleText[section.label] !== section.value"
                class="ml-0.5 animate-pulse"
              >
                ▌
              </span>
            </p>
          </div>
        </template>
      </div>
    </div>
  </section>
</template>

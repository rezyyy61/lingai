<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import LessonHeader from '@/components/lessons/LessonHeader.vue'
import LessonResourceText from '@/components/lessons/LessonResourceText.vue'
import LessonTabs from '@/components/lessons/LessonTabs.vue'
import LessonFirstRunState from '@/components/lessons/LessonFirstRunState.vue'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{
  lesson: LessonDetail | null
  loading: boolean
  error: string
  lessonCount?: number
  hasActiveFilters?: boolean
}>()

const emit = defineEmits<{
  (e: 'create', tab: 'text' | 'youtube' | 'ai'): void
  (e: 'clear-filters'): void
}>()

const layoutRef = ref<HTMLElement | null>(null)
const rightPanelWidth = ref(640)
const isResizing = ref(false)

const RIGHT_PANEL_STORAGE_KEY = 'lingai:lesson-right-panel-width'
const RIGHT_PANEL_MIN = 420
const RIGHT_PANEL_MAX = 920

const clampRightPanelWidth = (value: number) => {
  const width = Number.isFinite(value) ? value : RIGHT_PANEL_MIN
  return Math.max(RIGHT_PANEL_MIN, Math.min(RIGHT_PANEL_MAX, Math.round(width)))
}

const syncWidthToViewport = () => {
  if (typeof window === 'undefined') return
  if (window.innerWidth < 1280) return

  const maxFromViewport = Math.min(RIGHT_PANEL_MAX, Math.round(window.innerWidth * 0.48))
  rightPanelWidth.value = Math.max(RIGHT_PANEL_MIN, Math.min(maxFromViewport, rightPanelWidth.value))
}

const workspaceStyle = computed(() => ({
  '--lesson-right-panel-width': `${rightPanelWidth.value}px`,
}))

const handlePointerMove = (event: PointerEvent) => {
  if (!isResizing.value || !layoutRef.value) return

  const rect = layoutRef.value.getBoundingClientRect()
  const nextWidth = rect.right - event.clientX
  rightPanelWidth.value = clampRightPanelWidth(nextWidth)
}

const stopResize = () => {
  if (!isResizing.value) return
  isResizing.value = false
  document.body.style.userSelect = ''
  document.body.style.cursor = ''
}

const startResize = (event: PointerEvent) => {
  if (typeof window !== 'undefined' && window.innerWidth < 1280) return

  isResizing.value = true
  document.body.style.userSelect = 'none'
  document.body.style.cursor = 'col-resize'
  event.preventDefault()
}

onMounted(() => {
  if (typeof window === 'undefined') return

  const stored = Number(window.localStorage.getItem(RIGHT_PANEL_STORAGE_KEY) || '')
  if (!Number.isNaN(stored) && stored > 0) {
    rightPanelWidth.value = clampRightPanelWidth(stored)
  }

  syncWidthToViewport()
  window.addEventListener('pointermove', handlePointerMove)
  window.addEventListener('pointerup', stopResize)
  window.addEventListener('resize', syncWidthToViewport)
})

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(RIGHT_PANEL_STORAGE_KEY, String(rightPanelWidth.value))
    window.removeEventListener('pointermove', handlePointerMove)
    window.removeEventListener('pointerup', stopResize)
    window.removeEventListener('resize', syncWidthToViewport)
  }

  stopResize()
})
</script>

<template>
  <template v-if="props.loading">
    <div
      class="rounded-[28px] border border-[var(--app-border)]/70 bg-[var(--app-surface-elevated)]/85 px-6 py-6 text-center text-sm text-slate-500 shadow-sm dark:border-white/5 dark:bg-white/5 dark:text-slate-200 xl:col-span-2"
    >
      Loading lesson...
    </div>
  </template>
  <template v-else-if="props.error">
    <div
      class="rounded-[28px] border border-[var(--app-accent-strong)]/50 bg-[var(--app-accent-soft)]/90 px-6 py-6 text-center text-sm text-[var(--app-accent-strong)] dark:bg-[color:rgba(194,65,12,0.12)] dark:text-[var(--app-accent)] xl:col-span-2"
    >
      {{ props.error }}
    </div>
  </template>
  <template v-else-if="props.lesson">
    <div
      ref="layoutRef"
      class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_8px_var(--lesson-right-panel-width)]"
      :style="workspaceStyle"
    >
      <section
        class="relative flex h-[calc(100vh-160px)] min-w-0 flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-panel)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white dark:shadow-[0_30px_80px_rgba(0,0,0,0.5)]"
      >
        <LessonHeader :lesson="props.lesson" />
        <div class="mt-6 flex-1 min-h-0">
          <LessonResourceText
            :lesson="props.lesson"
            class="h-full"
          />
        </div>
      </section>

      <div class="relative hidden xl:flex h-[calc(100vh-160px)] items-center justify-center">
        <div
          class="group flex h-full w-2 cursor-col-resize items-center justify-center"
          @pointerdown="startResize"
        >
          <div
            class="h-24 w-[3px] rounded-full bg-[var(--app-border)]/80 transition-all duration-150 group-hover:h-36 group-hover:bg-[var(--app-accent)]"
            :class="isResizing ? '!h-40 !bg-[var(--app-accent)] shadow-[0_0_0_4px_rgba(249,115,22,0.12)]' : ''"
          />
        </div>
      </div>

      <section
        class="flex h-[calc(100vh-160px)] min-w-0 flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90 dark:text-white dark:shadow-[0_35px_95px_rgba(0,0,0,0.6)]"
      >
        <LessonTabs :lesson="props.lesson" />
      </section>
    </div>
  </template>
  <template v-else>
    <LessonFirstRunState
      :has-lessons="(props.lessonCount ?? 0) > 0"
      :has-active-filters="props.hasActiveFilters ?? false"
      @create="emit('create', $event)"
      @clear-filters="emit('clear-filters')"
    />
  </template>
</template>

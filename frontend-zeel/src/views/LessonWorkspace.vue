<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import LessonHeader from '@/components/lessons/LessonHeader.vue'
import LessonResourceText from '@/components/lessons/LessonResourceText.vue'
import LessonTabs from '@/components/lessons/LessonTabs.vue'
import LessonReadAloudPlayer from '@/components/lessons/LessonReadAloudPlayer.vue'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{ lesson: LessonDetail | null; loading: boolean; error: string }>()

const desktopReadOpen = ref(false)
const desktopAudioPlaying = ref(false)

const modalOffsetX = ref(0)
const modalOffsetY = ref(0)
const dragState = ref<{
  startX: number
  startY: number
  originX: number
  originY: number
} | null>(null)

const handleDesktopReadChange = (open: boolean) => {
  desktopReadOpen.value = open
}

const handleDesktopPlayingChange = (playing: boolean) => {
  desktopAudioPlaying.value = playing
}

const onModalDragStart = (e: MouseEvent | TouchEvent) => {
  let point: MouseEvent | Touch | null = null
  if ('touches' in e) {
    const t = e.touches[0]
    if (!t) return
    point = t
  } else {
    point = e
  }

  dragState.value = {
    startX: point.clientX,
    startY: point.clientY,
    originX: modalOffsetX.value,
    originY: modalOffsetY.value,
  }

  window.addEventListener('mousemove', onModalDragMove)
  window.addEventListener('mouseup', onModalDragEnd)
  window.addEventListener('touchmove', onModalDragMove)
  window.addEventListener('touchend', onModalDragEnd)
}

const onModalDragMove = (e: MouseEvent | TouchEvent) => {
  if (!dragState.value) return
  let point: MouseEvent | Touch | null = null
  if ('touches' in e) {
    const t = e.touches[0]
    if (!t) return
    point = t
  } else {
    point = e
  }

  const dx = point.clientX - dragState.value.startX
  const dy = point.clientY - dragState.value.startY
  modalOffsetX.value = dragState.value.originX + dx
  modalOffsetY.value = dragState.value.originY + dy
}

const onModalDragEnd = () => {
  dragState.value = null
  window.removeEventListener('mousemove', onModalDragMove)
  window.removeEventListener('mouseup', onModalDragEnd)
  window.removeEventListener('touchmove', onModalDragMove)
  window.removeEventListener('touchend', onModalDragEnd)
}

onMounted(() => {
  modalOffsetX.value = 0
  modalOffsetY.value = 0
})

onBeforeUnmount(() => {
  onModalDragEnd()
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
    <section
      class="relative flex h-[calc(100vh-160px)] flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-panel)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white dark:shadow-[0_30px_80px_rgba(0,0,0,0.5)]"
    >
      <LessonHeader :lesson="props.lesson" />
      <div class="mt-6 flex-1 min-h-0">
        <LessonResourceText
          :lesson="props.lesson"
          class="h-full"
          @desktop-read-change="handleDesktopReadChange"
        />
      </div>

      <transition name="desktop-read-modal">
        <div
          v-if="desktopReadOpen"
          class="pointer-events-none absolute left-1/2 top-0 z-30 flex w-full justify-center"
          :style="{ transform: `translate(calc(-50% + ${modalOffsetX}px), ${modalOffsetY}px)` }"
        >
          <div
            class="pointer-events-auto w-full max-w-2xl rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-2xl dark:bg-[var(--app-surface-dark-elevated)]/95 overflow-hidden"
          >
            <div
              class="flex items-center justify-between px-3 py-2 border-b border-[var(--app-border)] bg-[var(--app-surface)]/95 cursor-move select-none"
              @mousedown.prevent="onModalDragStart"
              @touchstart.stop.prevent="onModalDragStart"
            >
              <div
                class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-[var(--app-text-muted)]"
              >
                <span>Read Aloud</span>
              </div>

              <button
                @click="handleDesktopReadChange(false)"
                class="h-7 w-7 flex items-center justify-center rounded-xl hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
                title="Close"
              >
                ✕
              </button>
            </div>

            <div class="px-3 pb-3 pt-2">
              <LessonReadAloudPlayer
                :lesson-id="props.lesson.id"
                variant="sheet"
                @playing-change="handleDesktopPlayingChange"
              />
            </div>
          </div>
        </div>
      </transition>
    </section>

    <section
      class="flex h-[calc(100vh-160px)] flex-col rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-6 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90 dark:text-white dark:shadow-[0_35px_95px_rgba(0,0,0,0.6)]"
    >
      <LessonTabs :lesson="props.lesson" />
    </section>
  </template>
  <template v-else>
    <div
      class="rounded-[28px] border border-dashed border-[var(--app-border)] bg-[var(--app-surface-elevated)]/85 px-8 py-12 text-center text-[var(--app-text-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/85 dark:text-slate-400 xl:col-span-2"
    >
      Select a lesson from the list to begin learning.
    </div>
  </template>
</template>

<style scoped>
.desktop-read-modal-enter-active,
.desktop-read-modal-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.desktop-read-modal-enter-from,
.desktop-read-modal-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>

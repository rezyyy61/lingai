<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'
import { generateLessonReadAloud, getLessonReadAloud } from '@/api/lessonApi'

const props = defineProps<{
  lessonId: number
  variant?: 'inline' | 'sheet'
}>()

const emit = defineEmits<{
  (e: 'playing-change', value: boolean): void
}>()

type Speed = 'slow' | 'normal' | 'fast'

type ReadAloudRes = {
  exists?: boolean
  parts?: { index: number; url: string; chars: number }[]
  speed?: Speed
  locale?: string
  generated_at?: string | null
}

const variant = computed(() => props.variant ?? 'inline')
const speed = ref<Speed>('normal')

const isLoading = ref(false)
const error = ref('')

const readyMap = ref<Record<Speed, boolean>>({
  slow: false,
  normal: false,
  fast: false,
})

const parts = ref<{ index: number; url: string; chars: number }[]>([])
const currentIndex = ref(0)
const isPlaying = ref(false)
let audio: HTMLAudioElement | null = null

const isReadySelected = computed(() => readyMap.value[speed.value] === true)
const canGenerateSelected = computed(() => !isLoading.value && !isReadySelected.value)
const hasParts = computed(() => parts.value.length > 0)

const wrapperClass = computed(() => {
  if (variant.value === 'sheet') {
    return 'rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-3'
  }
  return 'rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4'
})

watch(
  () => isPlaying.value,
  (v) => emit('playing-change', v),
  { immediate: true },
)

const destroyAudio = () => {
  if (!audio) return
  audio.pause()
  audio.src = ''
  audio.onended = null
  audio.onpause = null
  audio.onplay = null
  audio = null
  isPlaying.value = false
}

const setPartsAndReset = (p: { index: number; url: string; chars: number }[]) => {
  destroyAudio()
  parts.value = Array.isArray(p) ? p : []
  currentIndex.value = 0
  isPlaying.value = false
}

const playFrom = (idx: number) => {
  if (!parts.value.length) return
  const target = parts.value[idx]
  if (!target?.url) return

  destroyAudio()
  currentIndex.value = idx

  audio = new Audio(target.url)
  audio.preload = 'auto'

  audio.onended = () => {
    const nextIdx = currentIndex.value + 1
    if (nextIdx < parts.value.length) {
      playFrom(nextIdx)
      return
    }
    isPlaying.value = false
  }

  audio.onplay = () => {
    isPlaying.value = true
  }

  audio.onpause = () => {
    isPlaying.value = false
  }

  audio.play().catch(() => {
    isPlaying.value = false
  })
}

const togglePlay = () => {
  if (!audio) {
    if (parts.value.length) playFrom(currentIndex.value)
    return
  }
  if (audio.paused) audio.play().catch(() => {})
  else audio.pause()
}

const fetchExistingFor = async (s: Speed) => {
  const res = (await getLessonReadAloud(props.lessonId, { speed: s, format: 'mp3' })) as ReadAloudRes
  const ok = !!(res && (res.exists === true || (res.parts && res.parts.length)))
  readyMap.value[s] = ok
  return res
}

const refreshStatuses = async () => {
  const [a, b, c] = await Promise.all([
    fetchExistingFor('slow'),
    fetchExistingFor('normal'),
    fetchExistingFor('fast'),
  ])
  return { slow: a, normal: b, fast: c }
}

const loadSelectedIfExists = async () => {
  error.value = ''
  setPartsAndReset([])

  try {
    const res = await fetchExistingFor(speed.value)
    if (readyMap.value[speed.value] && res.parts?.length) {
      setPartsAndReset(res.parts)
    }
  } catch (e) {
    error.value = 'Failed to load saved audio.'
    console.error(e)
  }
}

const generateSelected = async () => {
  if (!canGenerateSelected.value) return

  error.value = ''
  isLoading.value = true
  setPartsAndReset([])

  try {
    const res = (await generateLessonReadAloud(props.lessonId, {
      speed: speed.value,
      format: 'mp3',
      mode: 'auto',
      voice_pair: 'auto',
    })) as ReadAloudRes

    const p = res.parts || []
    if (!p.length) {
      readyMap.value[speed.value] = false
      error.value = 'No audio generated.'
      return
    }

    readyMap.value[speed.value] = true
    setPartsAndReset(p)
    playFrom(0)

    await refreshStatuses()
  } catch (e) {
    error.value = 'Failed to generate audio.'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

const speedLabel = (s: Speed) => (s === 'slow' ? 'Slow' : s === 'fast' ? 'Fast' : 'Normal')

watch(
  () => props.lessonId,
  async () => {
    destroyAudio()
    error.value = ''
    readyMap.value = { slow: false, normal: false, fast: false }
    setPartsAndReset([])
    await refreshStatuses()
    await loadSelectedIfExists()
  },
)

watch(
  () => speed.value,
  async () => {
    await loadSelectedIfExists()
  },
)

onMounted(async () => {
  await refreshStatuses()
  await loadSelectedIfExists()
})

onBeforeUnmount(() => {
  destroyAudio()
})
</script>

<template>
  <div :class="wrapperClass">
    <div class="grid grid-cols-3 gap-2">
      <button
        v-for="s in (['slow', 'normal', 'fast'] as const)"
        :key="s"
        class="rounded-xl border px-3 py-2 text-sm font-semibold transition flex items-center justify-center gap-2"
        :class="speed === s
          ? 'border-[var(--app-accent)] bg-[var(--app-surface)] text-[var(--app-text)]'
          : 'border-[var(--app-border)] bg-[var(--app-panel-muted)] text-[var(--app-text-muted)] hover:text-[var(--app-text)] hover:bg-[var(--app-surface)]/60'"
        @click="speed = s"
        :disabled="isLoading"
      >
        <span class="h-2 w-2 rounded-full" :class="readyMap[s] ? 'bg-emerald-500' : 'bg-[var(--app-border)]'" />
        <span>{{ speedLabel(s) }}</span>
      </button>
    </div>

    <div
      v-if="variant !== 'sheet'"
      class="mt-3 flex items-center justify-between text-[11px] font-semibold text-[var(--app-text-muted)]"
    >
      <div class="flex items-center gap-2">
        <span>Slow:</span>
        <span :class="readyMap.slow ? 'text-emerald-400' : ''">{{ readyMap.slow ? 'Ready' : 'Not generated' }}</span>
      </div>
      <div class="flex items-center gap-2">
        <span>Normal:</span>
        <span :class="readyMap.normal ? 'text-emerald-400' : ''">{{ readyMap.normal ? 'Ready' : 'Not generated' }}</span>
      </div>
      <div class="flex items-center gap-2">
        <span>Fast:</span>
        <span :class="readyMap.fast ? 'text-emerald-400' : ''">{{ readyMap.fast ? 'Ready' : 'Not generated' }}</span>
      </div>
    </div>

    <div
      v-if="error"
      class="mt-3 flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-medium text-red-600 border border-red-100 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-400"
    >
      <Icon icon="solar:danger-triangle-bold" class="h-4 w-4 shrink-0" />
      {{ error }}
    </div>

    <div v-if="hasParts" class="mt-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-3 py-2">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span
            class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)] text-[var(--app-text-muted)]"
          >
            <Icon icon="solar:music-library-bold-duotone" class="h-4 w-4" />
          </span>
          <div class="flex flex-col leading-tight">
            <span class="text-[11px] font-semibold text-[var(--app-text-muted)]">
              Audio • {{ speedLabel(speed) }}
            </span>
            <span class="text-[10px] text-[var(--app-text-muted)]/80">
              Part {{ currentIndex + 1 }} / {{ parts.length }}
            </span>
          </div>
        </div>

        <button
          class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[var(--app-accent)] text-white shadow-sm hover:bg-[var(--app-accent-strong)] transition disabled:opacity-60 disabled:cursor-not-allowed"
          @click="togglePlay"
          :disabled="isLoading"
          :title="isPlaying ? 'Pause audio' : 'Play audio'"
        >
          <Icon v-if="isPlaying" icon="solar:pause-bold" class="h-4 w-4" />
          <Icon v-else icon="solar:play-bold" class="h-4 w-4" />
        </button>
      </div>
    </div>

    <div
      v-else
      class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-3 text-xs text-[var(--app-text-muted)]"
    >
      <span>
        {{ isReadySelected ? 'Saved audio exists but parts are empty.' : 'No saved audio for this speed yet.' }}
      </span>

      <button
        v-if="!isReadySelected"
        type="button"
        class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text-muted)] hover:text-[var(--app-text)] hover:border-[var(--app-accent)] hover:bg-[var(--app-surface-elevated)] transition disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="!canGenerateSelected"
        @click="generateSelected"
        title="Generate audio"
      >
        <Icon
          v-if="isLoading"
          icon="svg-spinners:90-ring-with-bg"
          class="h-4 w-4"
        />
        <Icon
          v-else
          icon="solar:add-circle-bold-duotone"
          class="h-4 w-4"
        />
      </button>
    </div>
  </div>
</template>

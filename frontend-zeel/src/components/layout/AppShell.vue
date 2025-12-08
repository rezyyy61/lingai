<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchLesson, fetchLessons, type LessonQuery } from '@/api/lessonApi'
import type { Lesson, LessonDetail } from '@/types/lesson'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import LessonWorkspace from '@/views/LessonWorkspace.vue'
import LessonCreateModal from '@/components/lessons/LessonCreateModal.vue'
import LessonAnalysisPanel from '@/components/lessons/LessonAnalysisPanel.vue'

type LessonFilters = {
  q: string
  level: string
  resource_type: string
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { theme, toggleTheme } = useTheme()

const lessons = ref<Lesson[]>([])
const lessonsLoading = ref(false)
const lessonsError = ref('')

const selectedLesson = ref<LessonDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const analysisToastMessage = ref('')
let analysisToastTimeout: number | null = null

const query = reactive<LessonFilters>({
  q: '',
  level: '',
  resource_type: '',
})

const sidebarOpen = ref(false)
const createModalOpen = ref(false)
const loggingOut = ref(false)

const workspaceId = computed<number | null>(() => {
  const raw = route.params.id
  if (typeof raw === 'string') {
    const parsed = Number(raw)
    if (!Number.isNaN(parsed)) {
      return parsed
    }
  }
  return null
})

const matchesFilters = (lesson: Lesson) => {
  const matchesLevel = !query.level || lesson.level === query.level
  const matchesResource = !query.resource_type || lesson.resource_type === query.resource_type
  return matchesLevel && matchesResource
}

const upsertLessonInSidebar = (lesson: Lesson) => {
  if (!matchesFilters(lesson)) {
    if (lessons.value.some((item) => item.id === lesson.id)) {
      lessons.value = lessons.value.filter((item) => item.id !== lesson.id)
    }
    return
  }

  const existingIndex = lessons.value.findIndex((item) => item.id === lesson.id)
  if (existingIndex === -1) {
    lessons.value = [lesson, ...lessons.value]
  } else {
    lessons.value = [
      ...lessons.value.slice(0, existingIndex),
      { ...lessons.value[existingIndex], ...lesson },
      ...lessons.value.slice(existingIndex + 1),
    ]
  }
}

const loadLessons = async (options: { autoSelect?: boolean } = {}) => {
  if (!workspaceId.value) {
    lessons.value = []
    selectedLesson.value = null
    return
  }

  const autoSelect = options.autoSelect ?? true
  lessonsLoading.value = true
  lessonsError.value = ''

  try {
    const { data } = await fetchLessons({
      q: query.q || undefined,
      level: query.level || undefined,
      resource_type: query.resource_type || undefined,
      workspace_id: workspaceId.value ?? undefined,
    } as LessonQuery)

    lessons.value = data

    const firstLesson = lessons.value[0]

    if (autoSelect && !selectedLesson.value && firstLesson) {
      fetchLessonDetail(firstLesson.id)
    }
  } catch (error) {
    lessonsError.value = 'Failed to load lessons'
    console.error(error)
  } finally {
    lessonsLoading.value = false
  }
}

const fetchLessonDetail = async (id: number) => {
  detailLoading.value = true
  detailError.value = ''
  try {
    const detail = await fetchLesson(id)
    selectedLesson.value = detail
    upsertLessonInSidebar(detail)
    return detail
  } catch (error) {
    detailError.value = 'Failed to load lesson details'
    console.error(error)
  } finally {
    detailLoading.value = false
  }
}

watch(
  () => ({ ...query }),
  () => {
    loadLessons({ autoSelect: false })
  },
)

watch(
  () => workspaceId.value,
  () => {
    selectedLesson.value = null
    loadLessons()
  },
)

const handleLessonClick = (id: number) => {
  sidebarOpen.value = false
  fetchLessonDetail(id)
}

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value
}

const closeSidebar = () => {
  sidebarOpen.value = false
}

const openCreateModal = () => {
  if (!workspaceId.value) return
  createModalOpen.value = true
}

const closeCreateModal = () => {
  createModalOpen.value = false
}

const handleLessonCreated = async (lessonId: number) => {
  createModalOpen.value = false
  await loadLessons({ autoSelect: false })
  await fetchLessonDetail(lessonId)
}

const handleLogout = async () => {
  if (loggingOut.value) return
  loggingOut.value = true
  try {
    await auth.logout()
    router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

const goBackToWorkspaces = () => {
  router.push({ name: 'dashboard' })
}

const handleLessonUpdated = (lesson: LessonDetail) => {
  selectedLesson.value = lesson
  upsertLessonInSidebar(lesson)
}

const handleAnalysisToast = (message: string) => {
  analysisToastMessage.value = message
  if (analysisToastTimeout) {
    clearTimeout(analysisToastTimeout)
  }
  analysisToastTimeout = window.setTimeout(() => {
    analysisToastMessage.value = ''
    analysisToastTimeout = null
  }, 4000)
}

onMounted(() => {
  loadLessons()
})

onBeforeUnmount(() => {
  if (analysisToastTimeout) {
    clearTimeout(analysisToastTimeout)
  }
})

</script>

<template>
  <div
    class="min-h-screen bg-[var(--app-surface)] text-slate-900 transition-colors duration-200 dark:bg-[var(--app-surface-dark)] dark:text-slate-50"
  >
    <div class="mx-auto w-full max-w-[1800px] px-4 py-6 sm:px-6 lg:px-10 xl:px-12">
      <TopBar
        :theme="theme"
        @toggle-theme="toggleTheme"
        @toggle-sidebar="toggleSidebar"
        @logout="handleLogout"
      />

      <div class="mt-4 flex items-center justify-between gap-3">
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm hover:border-[var(--app-accent)] hover:text-[var(--app-accent-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-300"
          @click="goBackToWorkspaces"
        >
          <svg
            class="h-3.5 w-3.5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
          <span>Back to workspaces</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center justify-center rounded-full bg-[var(--app-accent)] px-4 py-2 text-xs font-semibold text-white shadow-sm shadow-[var(--app-accent)]/35 transition hover:bg-[var(--app-accent-strong)] disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="!workspaceId"
          @click="openCreateModal"
        >
          <span class="mr-2 text-base leading-none">+</span>
          New lesson
        </button>
      </div>

      <div class="mt-6 flex items-center justify-between gap-3">
        <div>
          <p
            class="text-[11px] font-semibold uppercase tracking-[0.35em] text-slate-400 dark:text-slate-500"
          >
            Lessons
          </p>
          <p class="text-xs text-slate-500 dark:text-slate-400">
            Lessons inside this workspace only. Use search and filters to focus your practice.
          </p>
        </div>
      </div>

      <div class="mt-8 grid items-start gap-6 xl:grid-cols-[25%_35%_40%]">
        <Sidebar
          class="hidden xl:flex"
          :lessons="lessons"
          :loading="lessonsLoading"
          :error="lessonsError"
          :selected-id="selectedLesson?.id ?? null"
          v-model:q="query.q"
          v-model:level="query.level"
          v-model:resource-type="query.resource_type"
          @select="handleLessonClick"
        />
        <LessonWorkspace
          :lesson="selectedLesson"
          :loading="detailLoading"
          :error="detailError"
        />
      </div>

      <div
        v-if="selectedLesson"
        class="mt-12"
      >
        <LessonAnalysisPanel
          :lesson="selectedLesson!"
          @updated="handleLessonUpdated"
          @toast="handleAnalysisToast"
        />
        <transition name="fade">
          <div
            v-if="analysisToastMessage"
            class="mt-4 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/80"
          >
            <span class="inline-flex size-2 rounded-full bg-[var(--app-accent-secondary)]" />
            <span>{{ analysisToastMessage }}</span>
          </div>
        </transition>
      </div>
    </div>

    <transition name="fade">
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 bg-[var(--app-overlay)] backdrop-blur-sm lg:hidden"
        @click="closeSidebar"
      ></div>
    </transition>
    <transition name="slide">
      <div
        v-if="sidebarOpen"
        class="fixed inset-y-0 left-0 z-50 w-80 max-w-full bg-[var(--app-surface)] p-4 shadow-xl dark:bg-[var(--app-surface-dark)] lg:hidden"
      >
        <Sidebar
          :lessons="lessons"
          :loading="lessonsLoading"
          :error="lessonsError"
          :selected-id="selectedLesson?.id ?? null"
          v-model:q="query.q"
          v-model:level="query.level"
          v-model:resource-type="query.resource_type"
          @select="handleLessonClick"
        />
      </div>
    </transition>

    <LessonCreateModal
      v-if="workspaceId"
      :open="createModalOpen"
      :workspace-id="workspaceId!"
      @close="closeCreateModal"
      @created="handleLessonCreated"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}
.slide-enter-from,
.slide-leave-to {
  transform: translateX(-100%);
}
</style>

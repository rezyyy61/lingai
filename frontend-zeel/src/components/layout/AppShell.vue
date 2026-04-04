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

type LessonStartTab = 'text' | 'youtube' | 'ai'
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

const query = reactive<LessonFilters>({
  q: '',
  level: '',
  resource_type: '',
})

const sidebarOpen = ref(false)
const desktopSidebarCollapsed = ref(false)
const createModalOpen = ref(false)
const createModalInitialTab = ref<LessonStartTab>('text')
const loggingOut = ref(false)

const desktopGridClass = computed(() =>
  desktopSidebarCollapsed.value
    ? 'xl:grid-cols-[92px_minmax(0,1fr)]'
    : 'xl:grid-cols-[280px_minmax(0,1fr)]',
)

const hasActiveFilters = computed(() => {
  return Boolean(query.q || query.level || query.resource_type)
})

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

const toggleDesktopSidebar = () => {
  desktopSidebarCollapsed.value = !desktopSidebarCollapsed.value
}

const openCreateModal = (tab: LessonStartTab = 'text') => {
  if (!workspaceId.value) return
  createModalInitialTab.value = tab
  createModalOpen.value = true
}

const closeCreateModal = () => {
  createModalOpen.value = false
}

const clearLessonFilters = () => {
  query.q = ''
  query.level = ''
  query.resource_type = ''
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

onMounted(() => {
  loadLessons()
})

onBeforeUnmount(() => {})

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

      <div class="mt-6 grid items-start gap-6" :class="desktopGridClass">
        <Sidebar
          class="hidden xl:flex"
          :collapsed="desktopSidebarCollapsed"
          :lessons="lessons"
          :loading="lessonsLoading"
          :error="lessonsError"
          :selected-id="selectedLesson?.id ?? null"
          v-model:q="query.q"
          v-model:level="query.level"
          v-model:resource-type="query.resource_type"
          @select="handleLessonClick"
          @back="goBackToWorkspaces"
          @create="openCreateModal"
          @toggle-collapse="toggleDesktopSidebar"
        />
        <LessonWorkspace
          :lesson="selectedLesson"
          :loading="detailLoading"
          :error="detailError"
          :lesson-count="lessons.length"
          :has-active-filters="hasActiveFilters"
          @create="openCreateModal"
          @clear-filters="clearLessonFilters"
        />
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
          :collapsed="false"
          v-model:q="query.q"
          v-model:level="query.level"
          v-model:resource-type="query.resource_type"
          @select="handleLessonClick"
          @back="goBackToWorkspaces"
          @create="openCreateModal"
          @toggle-collapse="toggleDesktopSidebar"
        />
      </div>
    </transition>

    <LessonCreateModal
      v-if="workspaceId"
      :open="createModalOpen"
      :workspace-id="workspaceId!"
      :initial-tab="createModalInitialTab"
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

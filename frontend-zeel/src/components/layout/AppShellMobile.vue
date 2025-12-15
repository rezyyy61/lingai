<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchLesson, fetchLessons, type LessonQuery } from '@/api/lessonApi'
import type { Lesson, LessonDetail } from '@/types/lesson'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import LessonCreateModal from '@/components/lessons/LessonCreateModal.vue'
import LessonAnalysisPanel from '@/components/lessons/LessonAnalysisPanel.vue'
import LessonTabs from '@/components/lessons/LessonTabs.vue'
import LessonResourceText from '@/components/lessons/LessonResourceText.vue'
import { Icon } from '@iconify/vue'

type LessonFilters = {
  q: string
  level: string
  resource_type: string
}

const isMobile = ref(false)

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

const createModalOpen = ref(false)
const loggingOut = ref(false)

const activeMobileTab = ref<'lessons' | 'source' | 'practice'>('lessons')
const isMenuOpen = ref(false)

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
      // Don't auto-fetch detail on mobile list load to save data/speed
      // unless we explicitly want to restore state (future improvement)
      // fetchLessonDetail(firstLesson.id)
      
      // On mobile, we start in 'lessons' tab typically, so no need to select one immediately
      // unless we are deep linking.
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
  activeMobileTab.value = 'practice' // Or 'source', depending on preference. Let's go to practice (dashboard).
  fetchLessonDetail(id)
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
  activeMobileTab.value = 'practice'
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
  <div class="fixed inset-0 flex flex-col bg-[var(--app-bg)] text-slate-900 transition-colors duration-200 dark:text-slate-50 xl:hidden overflow-hidden">
    <!-- Mobile Header -->
    <!-- Mobile Header -->
    <header class="sticky top-0 z-30 flex shrink-0 items-center justify-between border-b border-[var(--app-border)] bg-[var(--app-surface)]/80 px-4 py-3 backdrop-blur-md dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 min-h-[60px]">
      <div class="flex items-center gap-3 flex-1">
        <button
          v-if="activeMobileTab !== 'lessons' || workspaceId"
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--app-surface-elevated)] text-[var(--app-text-muted)] transition border border-[var(--app-border)] active:scale-95"
          @click="goBackToWorkspaces"
        >
          <Icon icon="solar:arrow-left-linear" class="h-5 w-5" />
        </button>
        <div v-else class="h-9 w-9"></div> <!-- Spacer -->
      </div>

      <h1 class="text-base font-bold text-[var(--app-text)] line-clamp-1 max-w-[200px] text-center flex-2 px-2">
        {{ selectedLesson?.title || 'Lessons' }}
      </h1>
      
      <div class="flex items-center justify-end gap-2 flex-1">
         <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-full transition border active:scale-95 relative"
            :class="auth.user?.name ? 'bg-[var(--app-accent)] border-transparent text-white' : 'bg-[var(--app-surface-elevated)] border-[var(--app-border)] text-[var(--app-text)]'"
            @click="isMenuOpen = !isMenuOpen"
         >
           <!-- Avatar or Menu Icon -->
           <span v-if="auth.user?.name" class="text-xs font-bold">{{ auth.user?.name?.[0]?.toUpperCase() }}</span>
           <Icon v-else icon="solar:hamburger-menu-linear" class="h-5 w-5" />
        </button>
      </div>
    </header>

    <!-- Menu Drawer/Dropdown -->
    <transition name="fade">
      <div v-if="isMenuOpen" class="fixed inset-0 z-50 flex flex-col" @click.self="isMenuOpen = false">
        <div class="absolute inset-0 bg-black/20 backdrop-blur-sm" @click="isMenuOpen = false"></div>
        <div class="absolute right-4 top-16 w-64 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 shadow-xl dark:border-[var(--app-border-dark)] dark:bg-[#1a1a1c] space-y-2 origin-top-right">
            
            <div class="mb-4 flex items-center gap-3 px-2">
               <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--app-accent)] text-white font-bold">
                 {{ auth.user?.name?.[0]?.toUpperCase() || 'U' }}
               </div>
               <div class="overflow-hidden">
                 <p class="truncate text-sm font-bold text-[var(--app-text)]">{{ auth.user?.name || 'User' }}</p>
                 <p class="truncate text-xs text-[var(--app-text-muted)]">{{ auth.user?.email }}</p>
               </div>
            </div>

            <div class="h-px bg-[var(--app-border)] my-2"></div>

            <button
               @click="toggleTheme"
               class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-sm font-medium transition hover:bg-[var(--app-surface)] text-[var(--app-text)]"
            >
              <span>Msg Theme</span>
              <Icon :icon="theme === 'light' ? 'solar:sun-bold' : 'solar:moon-bold'" class="h-5 w-5" />
            </button>

            <button
               @click="handleLogout"
               class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-red-500 transition hover:bg-red-500/10"
            >
               <Icon icon="solar:logout-2-bold" class="h-5 w-5" />
               Logout
            </button>
        </div>
      </div>
    </transition>

    <!-- Main Content Area -->
    <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 pb-28 overscroll-contain">
      
      <!-- Lessons Tab -->
      <transition name="fade" mode="out-in">
        <div v-if="activeMobileTab === 'lessons'" key="lessons" class="pb-safe space-y-4">
          <!-- Create Lesson Button (FAB-like or Banner) -->
          <button
            @click="openCreateModal"
            class="flex w-full items-center justify-center gap-2 rounded-2xl bg-[var(--app-accent)] py-3 text-sm font-bold text-white shadow-lg shadow-orange-500/20 active:scale-95 transition-transform"
          >
             <Icon icon="solar:add-circle-bold" class="h-5 w-5" />
             New Lesson
          </button>

          <Sidebar
            :lessons="lessons"
            :loading="lessonsLoading"
            :error="lessonsError"
            :selected-id="selectedLesson?.id ?? null"
            v-model:q="query.q"
            v-model:level="query.level"
            v-model:resource-type="query.resource_type"
            @select="handleLessonClick"
            class="!h-auto !shadow-none !bg-transparent !p-0 !border-0"
          />
        </div>

        <!-- Source Tab (Fixed Height, Internal Scroll) -->
        <div v-else-if="activeMobileTab === 'source'" key="source" class="h-full flex flex-col overflow-hidden pb-2">
             <template v-if="selectedLesson">
               <!-- Header Info (Fixed) -->
               <section class="shrink-0 px-2 mb-4">
                 <div class="mb-2 flex items-center gap-2">
                    <span class="rounded-full bg-[var(--app-accent-soft)] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-accent-strong)]">
                      {{ selectedLesson.resource_type || 'Text' }}
                    </span>
                    <span v-if="selectedLesson.level" class="text-xs font-medium text-[var(--app-text-muted)]">
                      {{ selectedLesson.level }}
                    </span>
                 </div>
                 
                <h1 class="text-xl font-bold leading-tight text-[var(--app-text)] line-clamp-2">
                  {{ selectedLesson.title }}
                </h1>
               </section>

               <!-- Main Text Reader (Explicit Height for Mobile Constraint) -->
               <LessonResourceText :lesson="selectedLesson" class="h-[calc(100dvh-220px)] mb-4" />

               <!-- Analysis Panel (Fixed at bottom or scrollable? Let's make it fixed compact or allow user to see it separately?) -->
               <!-- User emphasized TEext scrolling. We'll put analysis at bottom but it might be cramped. -->
               <!-- Better UX: Scrollable container for Analysis? Or keep it below? -->
               <!-- Let's try putting Analysis stacked below text first. -->
               <div class="shrink-0 max-h-[150px] overflow-y-auto custom-scrollbar border-t border-[var(--app-border)] pt-2">
                   <LessonAnalysisPanel
                      :lesson="selectedLesson!"
                      @updated="handleLessonUpdated"
                      @toast="handleAnalysisToast"
                      class="!rounded-xl !p-4 !shadow-none !border-0"
                    />
               </div>
            </template>
             <div v-else class="flex flex-col items-center justify-center py-20 text-center">
              <div class="mb-4 rounded-full bg-[var(--app-surface-elevated)] p-4 text-[var(--app-border-strong)]">
                 <Icon icon="solar:document-add-bold-duotone" class="h-8 w-8" />
              </div>
              <p class="text-[var(--app-text-muted)]">Select a lesson to view its content.</p>
              <button @click="activeMobileTab = 'lessons'" class="mt-4 text-xs font-semibold text-[var(--app-accent)] uppercase tracking-wider">
                Browse Lessons
              </button>
            </div>
        </div>
        
        <!-- Practice Tab -->
        <div v-else-if="activeMobileTab === 'practice'" key="practice" class="pb-safe h-full flex flex-col">
             <template v-if="selectedLesson">
               <!-- Unified Lesson Tabs (Studio Layout) -->
               <LessonTabs :lesson="selectedLesson" class="h-full" />
             </template>
             
             <div v-else class="flex flex-col items-center justify-center py-20 text-center">
              <div class="mb-4 rounded-full bg-[var(--app-surface-elevated)] p-4 text-[var(--app-border-strong)]">
                 <Icon icon="solar:dumbbell-large-minimalistic-bold-duotone" class="h-8 w-8" />
              </div>
              <p class="text-[var(--app-text-muted)]">Select a lesson to start practicing.</p>
               <button @click="activeMobileTab = 'lessons'" class="mt-4 text-xs font-semibold text-[var(--app-accent)] uppercase tracking-wider">
                Browse Lessons
              </button>
            </div>
        </div>
      </transition>
    </main>

    <!-- Bottom Navigation Bar -->
    <nav class="fixed bottom-0 left-0 right-0 z-50 border-t border-[var(--app-border)] bg-[var(--app-surface)]/90 backdrop-blur-xl dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90">
      <div class="safe-area-bottom flex items-start justify-around">
        
        <button
          v-for="tab in [
            { id: 'lessons', label: 'Lessons', icon: 'solar:library-bold-duotone' },
            { id: 'source', label: 'Source', icon: 'solar:document-text-bold-duotone' },
            { id: 'practice', label: 'Practice', icon: 'solar:dumbbell-small-bold-duotone' },
          ]"
          :key="tab.id"
          @click="activeMobileTab = tab.id as any"
          class="group flex w-16 flex-col items-center gap-1 transition-colors"
          :class="activeMobileTab === tab.id ? 'text-[var(--app-accent)]' : 'text-[var(--app-text-muted)]'"
        >
          <div class="relative flex items-center justify-center rounded-xl p-1 transition-all group-active:scale-90"
              :class="activeMobileTab === tab.id ? 'bg-[var(--app-accent-soft)]' : 'bg-transparent'"
          >
            <Icon :icon="tab.icon" class="h-6 w-6" />
          </div>
          <span class="text-[10px] font-medium">{{ tab.label }}</span>
        </button>
      </div>
    </nav>
    
    <transition name="fade">
        <div v-if="analysisToastMessage" class="fixed bottom-20 left-1/2 z-50 -translate-x-1/2 px-4">
             <div class="flex items-center gap-2 rounded-full border border-white/10 bg-[var(--app-surface-dark-elevated)]/90 px-4 py-2.5 text-xs font-medium text-white shadow-xl backdrop-blur-md">
                <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
                {{ analysisToastMessage }}
             </div>
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
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.fade-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.fade-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

/* iOS Safe Area handling */
.safe-area-bottom {
  padding-bottom: calc(env(safe-area-inset-bottom, 20px) + 12px);
  padding-top: 12px;
  height: auto;
  min-height: calc(4rem + env(safe-area-inset-bottom, 20px));
}

.pb-safe {
  padding-bottom: env(safe-area-inset-bottom, 20px);
}
</style>

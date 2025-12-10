<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchLesson, fetchLessons, type LessonQuery } from '@/api/lessonApi'
import type { Lesson, LessonDetail } from '@/types/lesson'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import LessonTabs from '@/components/lessons/LessonTabs.vue'
import LessonCreateModal from '@/components/lessons/LessonCreateModal.vue'
import LessonAnalysisPanel from '@/components/lessons/LessonAnalysisPanel.vue'
import LessonTabFlashcards from '@/components/lessons/LessonTabFlashcards.vue'
import LessonTabShadowing from '@/components/lessons/LessonTabShadowing.vue'
import LessonGrammarTab from '@/components/lessons/LessonGrammarTab.vue'
import LessonTabExercises from '@/components/lessons/LessonTabExercises.vue'
import LessonTabNotes from '@/components/lessons/LessonTabNotes.vue'
import { Icon } from '@iconify/vue'

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

const activeMobileTab = ref<'lessons' | 'source' | 'practice'>('practice')

type PracticeTabId = 'flashcards' | 'shadowing' | 'grammar' | 'exercises' | 'notes'

const activePracticeTab = ref<PracticeTabId>('flashcards')
const isFlashcardsFullScreen = ref(false)

const practiceTabs: { id: PracticeTabId; label: string; icon: string }[] = [
  { id: 'flashcards', label: 'Flashcards', icon: 'solar:cards-bold-duotone' },
  { id: 'shadowing', label: 'Shadowing', icon: 'solar:microphone-2-bold-duotone' },
  { id: 'grammar', label: 'Grammar', icon: 'solar:book-2-bold-duotone' },
  { id: 'exercises', label: 'Exercises', icon: 'solar:clipboard-list-bold-duotone' },
  { id: 'notes', label: 'Notes', icon: 'solar:pen-new-round-bold-duotone' },
]

const setPracticeTab = (id: PracticeTabId) => {
  activePracticeTab.value = id
  if (id === 'flashcards') {
    isFlashcardsFullScreen.value = true
  } else {
    isFlashcardsFullScreen.value = false
  }
}

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
  activeMobileTab.value = 'practice'
}

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value
  if (sidebarOpen.value) {
    activeMobileTab.value = 'lessons'
  }
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
  <div
    class="min-h-screen bg-[var(--app-surface)] text-slate-900 transition-colors duration-200 dark:bg-[var(--app-surface-dark)] dark:text-slate-50 xl:hidden"
  >
    <div class="mx-auto w-full max-w-[960px] px-4 py-4 sm:px-4">
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

      <div class="mt-4">
        <div
          class="flex rounded-xl border border-[var(--app-border)]/60 bg-[var(--app-surface-elevated)]/80 p-1 text-xs font-medium text-slate-500 dark:border-[var(--app-border-dark)]/60 dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-slate-300"
        >
          <button
            type="button"
            class="flex-1 rounded-xl px-3 py-2 text-center transition"
            :class="activeMobileTab === 'lessons'
              ? 'bg-[var(--app-surface)] text-[var(--app-accent-strong)] font-semibold shadow-sm'
              : 'text-slate-500 dark:text-slate-400'"
            @click="activeMobileTab = 'lessons'"
          >
            Lessons
          </button>
          <button
            type="button"
            class="flex-1 rounded-xl px-3 py-2 text-center transition"
            :class="activeMobileTab === 'source'
              ? 'bg-[var(--app-surface)] text-[var(--app-accent-strong)] font-semibold shadow-sm'
              : 'text-slate-500 dark:text-slate-400'"
            @click="activeMobileTab = 'source'"
          >
            Source
          </button>
          <button
            type="button"
            class="flex-1 rounded-xl px-3 py-2 text-center transition"
            :class="activeMobileTab === 'practice'
              ? 'bg-[var(--app-surface)] text-[var(--app-accent-strong)] font-semibold shadow-sm'
              : 'text-slate-500 dark:text-slate-400'"
            @click="activeMobileTab = 'practice'"
          >
            Practice
          </button>
        </div>
      </div>

      <div class="mt-4">
        <div
          v-if="activeMobileTab === 'lessons'"
          class="h-[calc(100vh-180px)] overflow-y-auto pb-4"
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

        <div
          v-else-if="activeMobileTab === 'source'"
          class="h-[calc(100vh-180px)] overflow-y-auto pb-4"
        >
          <template v-if="selectedLesson">
            <div class="space-y-4">
              <!-- Source card -->
              <section
                class="rounded-xl border border-[var(--app-border)]/60 bg-[var(--app-surface-elevated)]/85 p-4 text-[var(--app-text)] transition dark:border-[var(--app-border-dark)]/60 dark:bg-[var(--app-surface-dark-elevated)]/85 dark:text-white"
              >
                <p
                  class="text-[11px] font-semibold uppercase tracking-[0.35em] text-[var(--app-text-muted)] dark:text-white/60"
                >
                  Source text
                </p>
                <h1 class="mt-1 text-lg font-semibold leading-snug">
                  {{ selectedLesson.title }}
                </h1>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-[var(--app-text-muted)]">
                  <span
                    v-if="selectedLesson.level"
                    class="inline-flex items-center rounded-full bg-[var(--app-surface)]/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--app-text-muted)] dark:bg-white/5 dark:text-white/70"
                  >
                    Level {{ selectedLesson.level }}
                  </span>
                  <span
                    v-if="selectedLesson.resource_type"
                    class="inline-flex items-center rounded-full bg-[var(--app-surface)]/80 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--app-text-muted)] dark:bg-white/5 dark:text-white/70"
                  >
                    {{ selectedLesson.resource_type }}
                  </span>
                  <span
                    v-if="selectedLesson.created_at"
                    class="text-[10px] text-[var(--app-text-muted)] dark:text-white/60"
                  >
                    {{ new Date(selectedLesson.created_at).toLocaleDateString() }}
                  </span>
                </div>
                <p
                  v-if="selectedLesson.short_description"
                  class="mt-2 text-xs text-[var(--app-text-muted)] dark:text-white/70"
                >
                  {{ selectedLesson.short_description }}
                </p>

              </section>

              <!-- Analysis card -->
              <section>
                <LessonAnalysisPanel
                  :lesson="selectedLesson!"
                  @updated="handleLessonUpdated"
                  @toast="handleAnalysisToast"
                />
                <transition name="fade">
                  <div
                    v-if="analysisToastMessage"
                    class="mt-3 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/80"
                  >
                    <span class="inline-flex size-2 rounded-full bg-[var(--app-accent-secondary)]" />
                    <span>{{ analysisToastMessage }}</span>
                  </div>
                </transition>
              </section>
            </div>
          </template>
          <template v-else-if="detailLoading">
            <p class="text-sm text-[var(--app-text-muted)] dark:text-white/70">
              Loading lesson...
            </p>
          </template>
          <template v-else-if="detailError">
            <p class="text-sm text-[var(--app-accent-strong)]">
              {{ detailError }}
            </p>
          </template>
          <template v-else>
            <p class="text-sm text-[var(--app-text-muted)] dark:text-white/70">
              Select a lesson from the Lessons tab to see its source text.
            </p>
          </template>
        </div>

        <div
          v-else
          class="h-[calc(100vh-180px)] overflow-y-auto pb-4"
        >
          <template v-if="detailLoading">
            <p class="text-sm text-[var(--app-text-muted)] dark:text-white/70">
              Loading lesson...
            </p>
          </template>
          <template v-else-if="detailError">
            <p class="text-sm text-[var(--app-accent-strong)]">
              {{ detailError }}
            </p>
          </template>
          <template v-else-if="!selectedLesson">
            <p class="text-sm text-[var(--app-text-muted)] dark:text-white/70">
              Select a lesson from the Lessons tab to start practicing.
            </p>
          </template>
          <template v-else>
            <!-- Full-screen flashcards experience -->
            <section
              v-if="activePracticeTab === 'flashcards' && isFlashcardsFullScreen"
              class="flex h-full flex-col"
            >
              <div class="mb-3 flex items-center gap-2">
                <button
                  type="button"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/80 text-[var(--app-text)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
                  @click="isFlashcardsFullScreen = false"
                >
                  <svg
                    class="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                  </svg>
                </button>
                <div class="flex flex-col">
                  <span class="text-sm font-semibold">Flashcards</span>
                  <span class="text-[11px] text-[var(--app-text-muted)]">Focused practice</span>
                </div>
              </div>

              <div
                class="flex-1 rounded-xl border border-[var(--app-border)]/60 bg-[var(--app-surface-elevated)]/80 p-3 text-[var(--app-text)] transition dark:border-[var(--app-border-dark)]/60 dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white sm:p-4"
              >
                <LessonTabFlashcards :lesson-id="selectedLesson.id" />
              </div>
            </section>

            <!-- Normal grid of practice modes -->
            <section
              v-else
              class="space-y-4"
            >
              <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <button
                  v-for="tab in practiceTabs"
                  :key="tab.id"
                  type="button"
                  class="flex items-center justify-between rounded-xl px-3 py-3 text-left text-xs font-medium transition"
                  :class="activePracticeTab === tab.id
                    ? 'bg-[var(--app-accent-soft)] text-[var(--app-accent-strong)]'
                    : 'bg-[var(--app-surface-elevated)]/80 text-[var(--app-text-muted)] hover:bg-[var(--app-surface-elevated)] dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white/70 dark:hover:bg-[var(--app-surface-dark-elevated)]'"
                  @click="setPracticeTab(tab.id)"
                >
                  <span class="flex items-center gap-2">
                    <Icon
                      :icon="tab.icon"
                      class="h-4 w-4"
                    />
                    <span class="text-[11px]">{{ tab.label }}</span>
                  </span>
                  <span
                    v-if="activePracticeTab === tab.id"
                    class="h-5 w-5 rounded-full bg-[var(--app-accent)]/90 text-[10px] font-semibold text-white flex items-center justify-center"
                  >
                    ✓
                  </span>
                </button>
              </div>

              <div
                class="rounded-xl border border-[var(--app-border)]/60 bg-[var(--app-surface-elevated)]/80 p-3 text-[var(--app-text)] transition dark:border-[var(--app-border-dark)]/60 dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-white sm:p-4"
              >
                <LessonTabShadowing
                  v-if="activePracticeTab === 'shadowing'"
                  :lesson="selectedLesson"
                />
                <LessonGrammarTab
                  v-else-if="activePracticeTab === 'grammar'"
                  :lesson-id="selectedLesson.id"
                />
                <LessonTabExercises
                  v-else-if="activePracticeTab === 'exercises'"
                  :lesson-id="selectedLesson.id"
                />
                <LessonTabNotes v-else />
              </div>
            </section>
          </template>
        </div>
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

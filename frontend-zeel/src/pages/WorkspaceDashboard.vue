<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import TopBar from '@/components/layout/TopBar.vue'
import { fetchWorkspaces } from '@/api/workspaceApi'
import type { Workspace } from '@/types/workspace'
import WorkspaceCreateModal from '@/components/workspaces/WorkspaceCreateModal.vue'

const router = useRouter()
const auth = useAuthStore()
const { theme, toggleTheme } = useTheme()

const workspaces = ref<Workspace[]>([])
const loadingWorkspaces = ref(false)
const workspacesError = ref('')

const loggingOut = ref(false)
const createModalOpen = ref(false)
const sortMode = ref<'recent' | 'name'>('recent')
const viewMode = ref<'grid' | 'list'>('grid')

const loadWorkspaces = async () => {
  loadingWorkspaces.value = true
  workspacesError.value = ''
  try {
    const { data } = await fetchWorkspaces()
    workspaces.value = data ?? []
  } catch (error) {
    workspacesError.value = 'Unable to load workspaces'
    console.error(error)
  } finally {
    loadingWorkspaces.value = false
  }
}

const sortedWorkspaces = computed(() => {
  const items = [...workspaces.value]
  if (sortMode.value === 'name') {
    return items.sort((a, b) => a.name.localeCompare(b.name))
  }
  return items.sort((a, b) => {
    const aDate = a.created_at ? new Date(a.created_at).getTime() : 0
    const bDate = b.created_at ? new Date(b.created_at).getTime() : 0
    return bDate - aDate
  })
})

const openWorkspace = (workspace: Workspace) => {
  router.push({
    name: 'workspace',
    params: { id: workspace.id },
    query: {
      target: workspace.target_language,
      support: workspace.support_language,
    },
  })
}

const handleLogout = async () => {
  if (loggingOut.value) return
  loggingOut.value = true
  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

const handleWorkspaceCreated = (workspace: Workspace) => {
  createModalOpen.value = false
  workspaces.value.unshift(workspace)
  openWorkspace(workspace)
}

const formatDate = (value?: string | null) => {
  if (!value) return ''
  const date = new Date(value)
  return date.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })
}

onMounted(() => {
  loadWorkspaces()
})
</script>

<template>
  <div class="min-h-screen text-slate-900 dark:text-slate-50">
    <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-4 py-6 sm:px-6 lg:px-8">
      <TopBar
        :theme="theme"
        @toggle-theme="toggleTheme"
        @toggle-sidebar="() => {}"
        @logout="handleLogout"
      />

      <main class="mt-8 flex flex-1 flex-col">
        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="hidden text-[11px] font-semibold uppercase tracking-[0.35em] text-slate-400 dark:text-slate-500 sm:block">
              SHADOWING STUDIO
            </p>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50 sm:mt-1 sm:text-2xl">
              My workspaces
            </h1>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <!-- Grid / List toggle -->
            <div
              class="flex items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-0.5 text-[11px] text-slate-500 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-300"
            >
              <button
                type="button"
                :class="[
                  'rounded-full px-3 py-1.5 transition',
                  viewMode === 'grid'
                    ? 'bg-[var(--app-surface)] text-slate-900 shadow-sm dark:bg-[var(--app-surface-dark)] dark:text-slate-50'
                    : 'text-slate-400 dark:text-slate-500',
                ]"
                @click="viewMode = 'grid'"
              >
                <span class="sr-only">Grid</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-grid"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
              </button>
              <button
                type="button"
                :class="[
                  'rounded-full px-3 py-1.5 transition',
                  viewMode === 'list'
                    ? 'bg-[var(--app-surface)] text-slate-900 shadow-sm dark:bg-[var(--app-surface-dark)] dark:text-slate-50'
                    : 'text-slate-400 dark:text-slate-500',
                ]"
                @click="viewMode = 'list'"
              >
                <span class="sr-only">List</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
              </button>
            </div>

            <!-- Sort select -->
            <div class="relative text-xs">
              <select
                v-model="sortMode"
                class="appearance-none rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 pr-8 text-[11px] font-medium text-slate-600 outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-200"
              >
                <option value="recent">Recent</option>
                <option value="name">Name</option>
              </select>
              <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center">
                <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                </svg>
              </span>
            </div>

            <!-- Create button -->
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[var(--app-accent)] text-white shadow-sm shadow-[var(--app-accent)]/30 transition hover:bg-[var(--app-accent-strong)] sm:w-auto sm:px-4 sm:py-2"
              @click="createModalOpen = true"
            >
              <span class="text-xl leading-none sm:mr-2 sm:text-base">+</span>
              <span class="hidden sm:inline">Create</span>
            </button>
          </div>
        </header>

        <section class="flex-1">
          <!-- Tabs My / Featured -->
          <div
            class="inline-flex items-center gap-2 rounded-full bg-[var(--app-surface-elevated)]/80 px-2 py-1 text-[11px] font-medium text-slate-500 dark:bg-[var(--app-surface-dark-elevated)]/80 dark:text-slate-300"
          >
            <button
              type="button"
              class="rounded-full bg-slate-900/5 px-3 py-1 text-slate-900 dark:bg-slate-50/10 dark:text-slate-100"
            >
              My workspaces
            </button>
            <span class="px-3 py-1 text-slate-400 dark:text-slate-500">
              Featured
            </span>
          </div>

          <div class="mt-5">
            <div v-if="loadingWorkspaces" class="text-xs text-slate-500 dark:text-slate-400">
              Loading your workspaces…
            </div>

            <div v-if="workspacesError" class="mb-4 text-xs text-rose-500">
              {{ workspacesError }}
            </div>

            <!-- GRID VIEW -->
            <div
              v-if="!loadingWorkspaces && viewMode === 'grid'"
              class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
              <!-- Create card -->
              <button
                type="button"
                class="flex h-40 flex-col justify-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-5 py-6 text-left shadow-sm shadow-slate-900/5 transition hover:-translate-y-0.5 hover:border-[var(--app-accent)] hover:bg-[var(--app-accent-soft)]/80 hover:shadow-[0_18px_45px_rgba(0,0,0,0.15)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95 dark:shadow-[0_20px_55px_rgba(0,0,0,0.7)]"
                @click="createModalOpen = true"
              >
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--app-accent-soft)] text-xl font-semibold text-[var(--app-accent-strong)]"
                  >
                    +
                  </span>
                  <div>
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-50">
                      Create new workspace
                    </p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                      Choose your languages and start building lessons.
                    </p>
                  </div>
                </div>
              </button>

              <!-- Workspace cards -->
              <button
                v-for="ws in sortedWorkspaces"
                :key="ws.id"
                type="button"
                class="group flex h-40 flex-col rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-5 py-5 text-left shadow-sm shadow-slate-900/5 transition hover:-translate-y-0.5 hover:border-[var(--app-accent)] hover:shadow-[0_18px_45px_rgba(0,0,0,0.18)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95 dark:shadow-[0_20px_55px_rgba(0,0,0,0.7)]"
                @click="openWorkspace(ws)"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="flex items-center gap-3">
                    <div
                      class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--app-accent-soft)] text-xs font-semibold uppercase text-[var(--app-accent-strong)] dark:bg-slate-800 dark:text-slate-100"
                    >
                      {{ ws.target_language?.toUpperCase() || 'LANG' }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-50">
                        {{ ws.name || 'Untitled workspace' }}
                      </p>
                      <p class="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">
                        {{ ws.target_language }} → {{ ws.support_language }}
                      </p>
                    </div>
                  </div>
                  <span
                    class="inline-flex items-center rounded-full bg-slate-900/5 px-2 py-0.5 text-[10px] font-medium text-slate-500 group-hover:bg-[var(--app-accent-soft)] group-hover:text-[var(--app-accent-strong)] dark:bg-slate-50/5 dark:text-slate-400"
                  >
                    Open
                  </span>
                </div>

                <div class="mt-auto flex items-center justify-between pt-4 text-[11px] text-slate-500 dark:text-slate-400">
                  <span>
                    {{ formatDate(ws.created_at) }}
                  </span>
                  <span class="flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-[var(--app-accent-secondary)]"></span>
                    Active
                  </span>
                </div>
              </button>
            </div>

            <!-- LIST VIEW -->
            <div
              v-if="!loadingWorkspaces && viewMode === 'list'"
              class="space-y-3"
            >
              <button
                type="button"
                class="flex w-full items-center justify-between rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-4 py-4 text-left shadow-sm shadow-slate-900/5 transition hover:border-[var(--app-accent)] hover:bg-[var(--app-accent-soft)]/80 hover:shadow-[0_16px_40px_rgba(0,0,0,0.15)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95"
                @click="createModalOpen = true"
              >
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--app-accent-soft)] text-lg font-semibold text-[var(--app-accent-strong)]"
                  >
                    +
                  </span>
                  <div>
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-50">
                      Create new workspace
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                      Choose your languages and start building lessons.
                    </p>
                  </div>
                </div>
                <span
                  class="text-[11px] font-medium text-[var(--app-accent-strong)]"
                >
                  New
                </span>
              </button>

              <button
                v-for="ws in sortedWorkspaces"
                :key="ws.id"
                type="button"
                class="group flex w-full items-center justify-between rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-4 py-4 text-left shadow-sm shadow-slate-900/5 transition hover:border-[var(--app-accent)] hover:bg-[var(--app-accent-soft)]/80 hover:shadow-[0_16px_40px_rgba(0,0,0,0.18)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95"
                @click="openWorkspace(ws)"
              >
                <div class="flex items-center gap-3">
                  <div
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--app-accent-soft)] text-[11px] font-semibold uppercase text-[var(--app-accent-strong)] dark:bg-slate-800 dark:text-slate-100"
                  >
                    {{ ws.target_language?.toUpperCase() || 'LANG' }}
                  </div>
                  <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-50">
                      {{ ws.name || 'Untitled workspace' }}
                    </p>
                    <p class="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">
                      {{ ws.target_language }} → {{ ws.support_language }}
                    </p>
                  </div>
                </div>

                <div class="flex flex-col items-end gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                  <span>{{ formatDate(ws.created_at) }}</span>
                  <span class="flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-[var(--app-accent-secondary)]"></span>
                    Active
                  </span>
                </div>
              </button>
            </div>

            <div
              v-if="!loadingWorkspaces && !sortedWorkspaces.length && !workspacesError"
              class="mt-6 text-xs text-slate-500 dark:text-slate-400"
            >
              You do not have any workspaces yet. Use “Create new” to start.
            </div>
          </div>
        </section>
      </main>

      <WorkspaceCreateModal
        :open="createModalOpen"
        @close="createModalOpen = false"
        @created="handleWorkspaceCreated"
      />
    </div>
  </div>
</template>



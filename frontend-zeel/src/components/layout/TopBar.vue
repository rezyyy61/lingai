<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, computed } from 'vue'
import type { ThemeMode } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  theme: ThemeMode
}>()

const emit = defineEmits<{ 'toggle-theme': []; 'toggle-sidebar': []; logout: [] }>()

const authStore = useAuthStore()

const userMenuOpen = ref(false)

const user = computed(() => authStore.user)

const userName = computed(() => user.value?.name || 'Guest')
const userEmail = computed(() => user.value?.email || 'guest@example.com')
const userInitials = computed(() => {
  if (!user.value?.name) return 'SS'
  return (
    user.value.name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() || '')
      .join('') || 'SS'
  )
})

const closeMenu = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  if (!target.closest('[data-user-menu]')) {
    userMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', closeMenu)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closeMenu)
})
</script>

<template>
  <header
    class="relative overflow-visible rounded-[28px] border border-[var(--app-border)] bg-[linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0))] px-4 py-3 shadow-[0_20px_60px_rgba(0,0,0,0.14)] backdrop-blur-xl dark:border-[var(--app-border-dark)] dark:bg-[linear-gradient(180deg,rgba(255,255,255,0.03),rgba(255,255,255,0.01))] dark:shadow-[0_28px_70px_rgba(0,0,0,0.55)] sm:px-5"
  >
    <div
      class="pointer-events-none absolute inset-x-0 top-0 h-24 opacity-70"
      :style="{ background: 'radial-gradient(45% 90% at 12% 0%, rgba(249,115,22,0.18) 0%, rgba(249,115,22,0.05) 42%, transparent 72%)' }"
    />

    <div class="relative z-10 flex w-full items-center justify-between gap-4">
      <div class="flex min-w-0 items-center gap-3">
        <button
          class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/80 text-slate-600 transition hover:border-[var(--app-accent)] hover:text-[var(--app-accent-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 dark:text-slate-200 lg:hidden"
          aria-label="Toggle sidebar"
          @click="emit('toggle-sidebar')"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10" />
          </svg>
        </button>

        <div class="relative flex min-w-0 items-center gap-3">
          <span
            class="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[18px] border border-white/10 bg-[linear-gradient(135deg,#ff8a1f_0%,#f97316_42%,#fb7185_100%)] text-white shadow-[0_14px_34px_rgba(249,115,22,0.34)]"
            aria-hidden="true"
          >
            <span class="absolute inset-0 bg-[radial-gradient(circle_at_28%_20%,rgba(255,255,255,0.28),transparent_45%)]"></span>
            <svg viewBox="0 0 48 48" class="relative h-7 w-7">
              <circle cx="16" cy="24" r="3.2" fill="currentColor" fill-opacity="0.96" />
              <path
                d="M23 16.5c3.8 1.6 6.2 4.3 7.2 7.5-1 3.2-3.4 5.9-7.2 7.5"
                fill="none"
                stroke="currentColor"
                stroke-width="3.2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
              <path
                d="M29 11c5.6 2.5 9.2 7 10.8 13-1.6 6-5.2 10.5-10.8 13"
                fill="none"
                stroke="currentColor"
                stroke-opacity="0.88"
                stroke-width="3"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </span>
          <div class="hidden min-w-0 leading-tight sm:block">
            <div class="flex items-center gap-2">
              <p class="text-[15px] font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                lingAi
              </p>
              <span class="rounded-full border border-[var(--app-border)] bg-[var(--app-panel)] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--app-text-muted)] dark:border-white/10 dark:bg-white/5 dark:text-white/60">
                Studio
              </span>
            </div>
            <p class="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">
              AI language practice workspace
            </p>
          </div>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        <div class="hidden items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/70 px-3 py-2 text-[11px] font-medium text-[var(--app-text-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80 dark:text-white/60 xl:flex">
          <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
          <span>Workspace ready</span>
        </div>

        <button
          class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/90 text-slate-600 transition hover:border-[var(--app-accent)] hover:text-[var(--app-accent-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90 dark:text-slate-200"
          :aria-label="props.theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode'"
          @click="emit('toggle-theme')"
        >
          <svg
            v-if="props.theme === 'light'"
            class="h-4 w-4"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 3v1.5m0 15V21m9-9h-1.5M4.5 12H3m15.364-6.364-1.06 1.06M7.696 16.304l-1.06 1.06m0-11.314 1.06 1.06m8.548 8.548 1.06 1.06M12 7.5A4.5 4.5 0 1 0 16.5 12 4.5 4.5 0 0 0 12 7.5Z"
            />
          </svg>
          <svg
            v-else
            class="h-4 w-4"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21 12.79A9 9 0 1 1 11.21 3 7.5 7.5 0 0 0 21 12.79Z"
            />
          </svg>
        </button>

        <div class="relative" data-user-menu>
          <button
            class="flex items-center gap-3 rounded-[22px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-2.5 py-1.5 text-sm text-slate-700 shadow-sm transition hover:border-[var(--app-accent)] hover:bg-[var(--app-surface)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/95 dark:text-slate-100 dark:hover:border-[var(--app-accent-strong)] dark:hover:bg-[var(--app-surface-dark-elevated)]"
            @click.stop="userMenuOpen = !userMenuOpen"
          >
            <span
              class="flex h-10 w-10 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,var(--app-accent),var(--app-accent-strong))] text-xs font-semibold text-white shadow-[0_10px_24px_rgba(249,115,22,0.28)]"
            >
              {{ userInitials }}
            </span>
            <div class="hidden min-w-0 text-left text-xs text-slate-500 dark:text-slate-400 sm:block">
              <p class="max-w-[170px] truncate text-sm font-semibold text-slate-800 dark:text-slate-50">
                {{ userName }}
              </p>
              <p class="max-w-[170px] truncate">{{ userEmail }}</p>
            </div>
            <svg class="hidden h-4 w-4 text-slate-400 dark:text-slate-500 sm:block" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
          </button>

          <div
            v-if="userMenuOpen"
            class="absolute right-0 z-50 mt-3 w-64 overflow-hidden rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/98 p-2 text-sm shadow-[0_24px_70px_rgba(0,0,0,0.18)] ring-1 ring-black/5 backdrop-blur-xl dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/98"
          >
            <div class="rounded-[18px] border border-[var(--app-border)] bg-[var(--app-panel)] p-3 dark:border-white/10 dark:bg-white/5">
              <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,var(--app-accent),var(--app-accent-strong))] text-sm font-semibold text-white">
                  {{ userInitials }}
                </div>
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-50">{{ userName }}</p>
                  <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ userEmail }}</p>
                </div>
              </div>
            </div>

            <div class="mt-2 rounded-[18px] border border-[var(--app-border)] bg-[var(--app-surface)]/70 px-3 py-2.5 text-xs text-[var(--app-text-muted)] dark:border-white/10 dark:bg-black/10 dark:text-white/60">
              Signed in to lingAi workspace
            </div>

            <button
              class="mt-2 flex w-full items-center gap-2 rounded-[18px] px-3 py-3 text-left text-rose-600 transition hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-500/10"
              @click="
                () => {
                  userMenuOpen = false
                  emit('logout')
                }
              "
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m-7.5-3h10.5m0 0-3-3m3 3-3 3" />
              </svg>
              Logout
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>

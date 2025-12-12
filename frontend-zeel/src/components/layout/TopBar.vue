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
    class="flex items-center justify-between rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-4 py-3 shadow-sm backdrop-blur-sm dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95 dark:shadow-[0_18px_45px_rgba(0,0,0,0.7)]"
  >
    <div class="flex items-center gap-3">
      <!-- Sidebar Toggle (Desktop only in this context, unless specified) -->
      <button
        class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-transparent text-slate-600 transition hover:border-[var(--app-border)] hover:bg-[var(--app-surface)] dark:text-slate-200 dark:hover:border-[var(--app-border-dark)] dark:hover:bg-[var(--app-surface-dark)] lg:hidden"
        aria-label="Toggle sidebar"
        @click="emit('toggle-sidebar')"
      >
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10" />
        </svg>
      </button>

      <!-- Brand / Logo Area -->
      <div class="flex items-center gap-2">
        <span
          class="flex h-9 w-9 items-center justify-center rounded-xl bg-[var(--app-accent)] text-xs font-semibold uppercase tracking-wide text-white"
        >
          SS
        </span>
        <div class="hidden leading-tight sm:block">
          <p class="text-sm font-semibold text-slate-900 dark:text-slate-50">
            Shadowing Studio
          </p>
          <p class="text-[11px] text-slate-500 dark:text-slate-400">
            Language practice workspace
          </p>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-3">
      <button
        class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/90 text-slate-600 transition hover:border-[var(--app-accent)] hover:text-[var(--app-accent-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/90 dark:text-slate-200"
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

      <!-- User Menu -->
      <div class="relative" data-user-menu>
        <button
          class="flex items-center gap-3 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 px-3 py-1.5 text-sm text-slate-700 shadow-sm transition hover:border-[var(--app-accent)] hover:bg-[var(--app-surface)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/95 dark:text-slate-100 dark:hover:border-[var(--app-accent-strong)] dark:hover:bg-[var(--app-surface-dark-elevated)]"
          @click.stop="userMenuOpen = !userMenuOpen"
        >
          <span
            class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--app-accent)] text-xs font-semibold text-white"
          >
            {{ userInitials }}
          </span>
          <div class="hidden text-left text-xs text-slate-500 dark:text-slate-400 sm:block">
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-50">
              {{ userName }}
            </p>
            <p>{{ userEmail }}</p>
          </div>
          <svg class="hidden h-4 w-4 text-slate-400 dark:text-slate-500 sm:block" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
          </svg>
        </button>

        <div
          v-if="userMenuOpen"
          class="absolute right-0 mt-2 w-44 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/95 p-2 text-sm shadow-lg ring-1 ring-black/5 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]/95 z-50"
        >
          <button
            class="w-full rounded-xl px-3 py-2 text-left text-slate-700 transition hover:bg-[var(--app-surface)] dark:text-slate-100 dark:hover:bg-[var(--app-surface-dark)]"
          >
            Profile
          </button>
          <button
            class="w-full rounded-xl px-3 py-2 text-left text-slate-700 transition hover:bg-[var(--app-surface)] dark:text-slate-100 dark:hover:bg-[var(--app-surface-dark)]"
          >
            Settings
          </button>
          <button
            class="w-full rounded-xl px-3 py-2 text-left text-rose-600 transition hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-500/10"
            @click="
              () => {
                userMenuOpen = false
                emit('logout')
              }
            "
          >
            Logout
          </button>
        </div>
      </div>
    </div>
  </header>
</template>

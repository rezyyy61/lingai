<script setup lang="ts">
import { RouterView, useRouter } from 'vue-router'
import { computed } from 'vue'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const displayName = computed(() => auth.user?.name ?? 'User')

const handleLogout = async () => {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-white">
    <nav class="border-b border-white/5 bg-slate-950/70 backdrop-blur">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <div class="flex items-center gap-2 text-lg font-semibold">
          <div class="flex size-9 items-center justify-center rounded-xl bg-primary/20 text-primary">
            ZL
          </div>
          <span>ZeeL</span>
        </div>
        <details class="relative">
          <summary class="flex cursor-pointer list-none items-center gap-3 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-white/90">
            <div>
              <p class="font-medium">{{ displayName }}</p>
              <p class="text-xs text-white/60">{{ auth.user?.email }}</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.939l3.71-3.71a.75.75 0 111.06 1.061l-4.24 4.243a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
            </svg>
          </summary>
          <div class="absolute right-0 mt-2 min-w-48 rounded-xl border border-white/10 bg-slate-900/90 p-2 shadow-xl">
            <button class="w-full rounded-lg px-4 py-2 text-left text-sm text-white/80 hover:bg-white/5" @click="handleLogout">
              Log out
            </button>
          </div>
        </details>
      </div>
    </nav>
    <main class="mx-auto w-full max-w-5xl px-4 py-10">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
summary::-webkit-details-marker {
  display: none;
}
</style>

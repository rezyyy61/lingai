<script setup lang="ts">
import { useRouter } from 'vue-router'

import ZButton from '@/components/ui/ZButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const handleLogout = async () => {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="rounded-3xl border border-white/5 bg-white/5 p-8 text-white shadow-2xl">
    <h1 class="text-3xl font-semibold">Welcome back, {{ auth.user?.name }} 👋</h1>
    <p class="mt-2 text-white/70">You're logged in as {{ auth.user?.email }}.</p>

    <div class="mt-8 space-y-3 text-sm text-white/70">
      <p>
        Use this starter dashboard to build the ZeeL admin experience. Wire up your modules, plug in
        analytics, and keep your team in sync.
      </p>
      <p>The authentication scaffolding is ready for you.</p>
    </div>

  </div>
</template>

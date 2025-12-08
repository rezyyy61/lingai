<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { AxiosError } from 'axios'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import ZButton from '@/components/ui/ZButton.vue'
import ZInput from '@/components/ui/ZInput.vue'
import ZAlert from '@/components/ui/ZAlert.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = ref({
  email: '',
  password: '',
  remember: false,
})

const formError = ref('')
const statusMessage = ref('')

auth.resetErrors()

watch(
  () => route.query.message,
  (value) => {
    statusMessage.value = typeof value === 'string' ? value : ''
  },
  { immediate: true },
)

const redirectTo = computed(() =>
  typeof route.query.redirect === 'string' ? route.query.redirect : '/',
)

const handleSubmit = async () => {
  formError.value = ''
  try {
    await auth.login({
      email: form.value.email,
      password: form.value.password,
    })
    router.push(redirectTo.value)
  } catch (error) {
    const axiosError = error as AxiosError<{ message?: string }>
    formError.value = axiosError.response?.data?.message ?? 'Unable to log in. Please try again.'
  }
}

onBeforeUnmount(() => auth.resetErrors())
</script>

<template>
  <main class="min-h-screen bg-slate-950 text-white flex items-center justify-center">
    <div class="w-full max-w-md px-6">
      <div class="space-y-8">
        <header class="space-y-3">
          <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-zeel-muted/90">
            Return to campus
          </p>
          <h2 class="text-3xl font-semibold text-white">Sign in to your ZeeL academy</h2>
          <p class="text-sm text-zeel-muted">
            Continue your curated curriculum with an academic-grade AI mentor focused on fluency and context.
          </p>
        </header>

        <ZAlert v-if="statusMessage" variant="success">{{ statusMessage }}</ZAlert>
        <ZAlert v-if="formError" variant="error">{{ formError }}</ZAlert>

        <form class="space-y-6" @submit.prevent="handleSubmit">
          <div class="space-y-4">
            <ZInput
              label="Email address"
              type="email"
              autocomplete="email"
              name="email"
              v-model="form.email"
              :error="auth.errors?.email"
            />
            <ZInput
              label="Password"
              type="password"
              autocomplete="current-password"
              name="password"
              v-model="form.password"
              :error="auth.errors?.password"
            />
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 text-xs font-medium text-zeel-muted">
            <label class="flex items-center gap-2 text-zeel-muted">
              <input
                type="checkbox"
                v-model="form.remember"
                class="size-4 rounded border border-white/20 bg-transparent text-zeel-primary focus:ring-zeel-primary"
              />
              Remember me
            </label>
            <RouterLink
              class="text-zeel-primary transition hover:text-cyan-200"
              :to="{ name: 'forgot-password' }"
            >
              Forgot password?
            </RouterLink>
          </div>

          <ZButton type="submit" :loading="auth.loading">Enter academy</ZButton>
        </form>

        <p class="text-center text-sm text-zeel-muted">
          New to ZeeL?
          <RouterLink class="text-zeel-primary hover:text-cyan-200" :to="{ name: 'register' }">
            Create an account
          </RouterLink>
        </p>
      </div>
    </div>
  </main>
</template>


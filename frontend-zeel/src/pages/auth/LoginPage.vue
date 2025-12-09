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
  <main class="min-h-screen bg-[var(--app-bg)] text-[var(--app-text)] flex items-center justify-center">
    <div class="w-full max-w-md px-6">
      <div class="space-y-8 rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-[var(--app-card-shadow)]">
        <header class="space-y-3 text-center">
          <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-[var(--app-text-muted)]">
            Welcome back
          </p>
          <h2 class="text-2xl font-semibold text-[var(--app-text)]">
            Sign in to your <span class="text-[var(--app-accent-strong)]">lingAi</span> studio
          </h2>
          <p class="text-sm text-[var(--app-text-muted)]">
            Continue your focused practice with an AI language mentor tuned to your workspace.
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

          <div class="flex flex-wrap items-center justify-between gap-3 text-xs font-medium text-[var(--app-text-muted)]">
            <label class="flex items-center gap-2">
              <input
                type="checkbox"
                v-model="form.remember"
                class="size-4 rounded border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-[var(--app-accent-strong)] focus:ring-[var(--app-accent)]"
              />
              Remember me
            </label>
            <RouterLink
              class="text-[var(--app-accent-strong)] transition hover:text-[var(--app-accent)]"
              :to="{ name: 'forgot-password' }"
            >
              Forgot password?
            </RouterLink>
          </div>

          <ZButton type="submit" :loading="auth.loading">Enter lingAi</ZButton>
        </form>

        <p class="text-center text-sm text-[var(--app-text-muted)]">
          New to <span class="font-semibold text-[var(--app-accent-strong)]">lingAi</span>?
          <RouterLink class="text-[var(--app-accent-strong)] hover:text-[var(--app-accent)]" :to="{ name: 'register' }">
            Create an account
          </RouterLink>
        </p>
      </div>
    </div>
  </main>
</template>

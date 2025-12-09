<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import type { AxiosError } from 'axios'
import { RouterLink, useRouter } from 'vue-router'

import ZButton from '@/components/ui/ZButton.vue'
import ZInput from '@/components/ui/ZInput.vue'
import ZAlert from '@/components/ui/ZAlert.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const formError = ref('')

auth.resetErrors()

const handleSubmit = async () => {
  formError.value = ''
  try {
    await auth.register({ ...form.value })
    router.push({ name: 'dashboard' })
  } catch (error) {
    const axiosError = error as AxiosError<{ message?: string }>
    formError.value = axiosError.response?.data?.message ?? 'Unable to register. Please try again.'
  }
}

onBeforeUnmount(() => auth.resetErrors())
</script>

<template>
  <main class="min-h-screen bg-[var(--app-bg)] text-[var(--app-text)] flex items-center justify-center">
    <div class="w-full max-w-md px-6">
      <div
        class="space-y-8 rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-[var(--app-card-shadow)]"
      >
        <header class="space-y-3 text-center">
          <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-[var(--app-text-muted)]">Get started</p>
          <h2 class="text-2xl font-semibold text-[var(--app-text)]">
            Create your <span class="text-[var(--app-accent-strong)]">lingAi</span> account
          </h2>
          <p class="text-sm text-[var(--app-text-muted)]">
            Set up a focused language workspace and keep all your practice in one place.
          </p>
        </header>

        <ZAlert v-if="formError" variant="error">{{ formError }}</ZAlert>

        <form class="space-y-5" @submit.prevent="handleSubmit">
          <ZInput label="Full name" name="name" autocomplete="name" v-model="form.name" :error="auth.errors?.name" />
          <ZInput
            label="Email address"
            name="email"
            type="email"
            autocomplete="email"
            v-model="form.email"
            :error="auth.errors?.email"
          />
          <ZInput
            label="Password"
            type="password"
            name="password"
            autocomplete="new-password"
            v-model="form.password"
            :error="auth.errors?.password"
          />
          <ZInput
            label="Confirm password"
            type="password"
            name="password_confirmation"
            autocomplete="new-password"
            v-model="form.password_confirmation"
            :error="auth.errors?.password_confirmation"
          />
          <ZButton type="submit" :loading="auth.loading">Start learning</ZButton>
        </form>

        <p class="text-center text-sm text-[var(--app-text-muted)]">
          Already using <span class="font-semibold text-[var(--app-accent-strong)]">lingAi</span>?
          <RouterLink class="text-[var(--app-accent-strong)] hover:text-[var(--app-accent)]" :to="{ name: 'login' }">
            Sign in
          </RouterLink>
        </p>
      </div>
    </div>
  </main>
</template>

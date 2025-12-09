<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import type { AxiosError } from 'axios'
import { RouterLink } from 'vue-router'

import ZAlert from '@/components/ui/ZAlert.vue'
import ZButton from '@/components/ui/ZButton.vue'
import ZInput from '@/components/ui/ZInput.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const form = ref({ email: '' })
const successMessage = ref('')
const formError = ref('')

auth.resetErrors()

const handleSubmit = async () => {
  formError.value = ''
  successMessage.value = ''
  try {
    const response = await auth.forgotPassword({ ...form.value })
    successMessage.value = response?.status ?? 'Password reset link sent if the email exists.'
  } catch (error) {
    const axiosError = error as AxiosError<{ message?: string }>
    formError.value = axiosError.response?.data?.message ?? 'Unable to send reset email.'
  }
}

onBeforeUnmount(() => auth.resetErrors())
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-2xl font-semibold text-[var(--app-text)]">Forgot password?</h2>
      <p class="text-sm text-[var(--app-text-muted)]">
        Enter your email and we’ll send a reset link for your lingAi account.
      </p>
    </div>

    <ZAlert v-if="successMessage" variant="success">{{ successMessage }}</ZAlert>
    <ZAlert v-if="formError" variant="error">{{ formError }}</ZAlert>

    <form class="space-y-5" @submit.prevent="handleSubmit">
      <ZInput
        label="Email address"
        type="email"
        name="email"
        autocomplete="email"
        v-model="form.email"
        :error="auth.errors?.email"
      />
      <ZButton type="submit" :loading="auth.loading">Send reset link</ZButton>
    </form>

    <p class="text-center text-sm text-[var(--app-text-muted)]">
      Back to
      <RouterLink class="font-semibold text-[var(--app-accent-strong)]" :to="{ name: 'login' }">login</RouterLink>
    </p>
  </div>
</template>

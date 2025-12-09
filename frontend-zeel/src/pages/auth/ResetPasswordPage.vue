<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import type { AxiosError } from 'axios'
import { useRoute, useRouter } from 'vue-router'

import ZAlert from '@/components/ui/ZAlert.vue'
import ZButton from '@/components/ui/ZButton.vue'
import ZInput from '@/components/ui/ZInput.vue'
import { useAuthStore } from '@/stores/auth'

const props = withDefaults(
  defineProps<{
    token?: string
    email?: string
  }>(),
  {
    token: '',
    email: '',
  },
)

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

auth.resetErrors()

const form = ref({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

watch(
  () => route.query,
  (query) => {
    form.value.token = (query?.token as string) ?? form.value.token
    form.value.email = (query?.email as string) ?? form.value.email
  },
)

const formError = ref('')

const handleSubmit = async () => {
  formError.value = ''
  try {
    const response = await auth.resetPassword({ ...form.value })
    const message = response?.status ?? 'Password updated successfully. Please sign in.'
    router.push({ name: 'login', query: { message } })
  } catch (error) {
    const axiosError = error as AxiosError<{ message?: string }>
    formError.value = axiosError.response?.data?.message ?? 'Unable to reset password.'
  }
}

onBeforeUnmount(() => auth.resetErrors())
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-2xl font-semibold text-[var(--app-text)]">Reset your password</h2>
      <p class="text-sm text-[var(--app-text-muted)]">
        Choose a strong password to secure your lingAi workspace.
      </p>
    </div>

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
      <ZInput
        label="New password"
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
      <ZButton type="submit" :loading="auth.loading">Update password</ZButton>
    </form>
  </div>
</template>

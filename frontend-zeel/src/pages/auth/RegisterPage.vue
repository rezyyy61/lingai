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
    if (auth.user?.email_verified_at) {
      router.push({ name: 'dashboard' })
    } else {
      router.push({ name: 'email-verify' })
    }
  } catch (error) {
    const axiosError = error as AxiosError<{ message?: string }>
    formError.value = axiosError.response?.data?.message ?? 'Unable to register. Please try again.'
  }
}

onBeforeUnmount(() => auth.resetErrors())
</script>

<template>
  <div class="space-y-8">
    <header class="space-y-3">
      <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-zeel-muted/90">Enroll now</p>
      <h2 class="text-3xl font-semibold text-white">Create your ZeeL learning profile</h2>
      <p class="text-sm text-zeel-muted">
        Personalise your curriculum, earn progress transcripts, and co-study with our bilingual AI faculty.
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

    <p class="text-center text-sm text-zeel-muted">
      Already using ZeeL?
      <RouterLink class="text-zeel-primary hover:text-cyan-200" :to="{ name: 'login' }">Sign in</RouterLink>
    </p>
  </div>
</template>

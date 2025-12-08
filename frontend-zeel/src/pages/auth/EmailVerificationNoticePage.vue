<script setup lang="ts">
import { ref } from 'vue'

import ZAlert from '@/components/ui/ZAlert.vue'
import ZButton from '@/components/ui/ZButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const successMessage = ref('')
const errorMessage = ref('')

const resend = async () => {
  successMessage.value = ''
  errorMessage.value = ''
  try {
    const response = await auth.resendVerificationEmail()
    successMessage.value = response?.status ?? 'Verification email has been sent.'
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Unable to resend verification email.'
  }
}
</script>

<template>
  <div class="space-y-6 rounded-3xl border border-white/5 bg-white/5 p-8 shadow-2xl">
    <div>
      <h2 class="text-2xl font-semibold text-white">Verify your inbox</h2>
      <p class="mt-2 text-sm text-white/70">
        We just sent a verification link to <strong>{{ auth.user?.email }}</strong>. Please confirm your
        email to access every feature of ZeeL.
      </p>
    </div>

    <ZAlert v-if="successMessage" variant="success">{{ successMessage }}</ZAlert>
    <ZAlert v-if="errorMessage" variant="error">{{ errorMessage }}</ZAlert>

    <div class="flex flex-col gap-3 sm:flex-row">
      <ZButton type="button" :loading="auth.loading" @click="resend">Resend verification email</ZButton>
      <ZButton type="button" variant="ghost" :block="false" @click="auth.fetchUser()">
        Refresh status
      </ZButton>
    </div>
  </div>
</template>

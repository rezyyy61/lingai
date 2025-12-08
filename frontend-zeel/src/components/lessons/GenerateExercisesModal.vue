<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { generateLessonExercises } from '@/api/lessonExercises'

const props = defineProps<{
  open: boolean
  lessonId: number
}>()

const emit = defineEmits<{
  close: []
  queued: []
}>()

const form = reactive({
  customPrompt: '',
  replaceExisting: true,
})

const isSubmitting = ref(false)
const errorMessage = ref('')

const resetForm = () => {
  form.customPrompt = ''
  form.replaceExisting = true
  errorMessage.value = ''
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetForm()
    }
  },
)

const handleClose = () => {
  if (isSubmitting.value) return
  emit('close')
}

const handleSubmit = async () => {
  if (isSubmitting.value) return
  if (!props.lessonId) {
    errorMessage.value = 'Missing lesson.'
    return
  }
  isSubmitting.value = true
  errorMessage.value = ''
  try {
    await generateLessonExercises(props.lessonId, {
      custom_prompt: form.customPrompt || undefined,
      replace_existing: form.replaceExisting,
    })
    emit('queued')
    resetForm()
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Failed to queue exercise generation'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-[var(--app-overlay)] backdrop-blur-sm px-4 py-6"
    >
      <div
        class="w-full max-w-lg rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-7 text-[var(--app-text)] shadow-[var(--app-card-shadow-strong)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)]"
      >
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold">Generate exercises</h2>
            <p class="text-sm text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Create practice items for this lesson.</p>
          </div>
          <button
            class="rounded-full border border-[var(--app-border)] p-2 text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-text)] dark:hover:bg-[color:rgba(255,255,255,0.08)] dark:hover:text-[var(--app-text)]"
            aria-label="Close"
            @click="handleClose"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="handleSubmit">
          <label class="block text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
            <span class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Custom prompt</span>
            <textarea
              v-model="form.customPrompt"
              rows="5"
              class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
              placeholder="Describe the skills or topics you want covered."
            ></textarea>
          </label>

          <div class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-4 text-sm text-[var(--app-text)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/70 dark:text-[var(--app-text)]">
            <label class="flex items-start gap-3">
              <input
                v-model="form.replaceExisting"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-[var(--app-border)] bg-transparent text-[var(--app-accent)] focus:ring-[var(--app-accent)] dark:border-[var(--app-border-dark)]"
              />
              <span>Replace existing exercises</span>
            </label>
          </div>

          <p v-if="errorMessage" class="text-sm text-[var(--app-accent)]">
            {{ errorMessage }}
          </p>

          <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:justify-end">
            <button
              type="button"
              class="rounded-full border border-[var(--app-border)] px-5 py-2 text-sm font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-text)] dark:hover:bg-[color:rgba(255,255,255,0.08)] dark:hover:text-[var(--app-text)]"
              @click="handleClose"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="rounded-full bg-[var(--app-accent)] px-6 py-2 text-sm font-semibold text-white shadow-[0_15px_35px_rgba(249,115,22,0.35)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="isSubmitting"
            >
              {{ isSubmitting ? 'Generating…' : 'Generate exercises' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </transition>
</template>

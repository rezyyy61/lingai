<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { generateLessonShadowingSentences } from '@/api/lessonShadowing'

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
    await generateLessonShadowingSentences(props.lessonId, {
      custom_prompt: form.customPrompt || undefined,
      replace_existing: form.replaceExisting,
    })
    emit('queued')
    resetForm()
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Failed to queue shadowing sentences'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end bg-[var(--app-overlay)]/80 backdrop-blur-sm sm:items-center"
    >
      <div
        class="w-full max-h-[90vh] rounded-t-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 pb-4 pt-3 text-[var(--app-text)] shadow-lg sm:mx-auto sm:max-w-md sm:rounded-2xl sm:px-5 sm:pb-5 sm:pt-4 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
      >
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold sm:text-base">Generate shadowing sentences</h2>
            <p class="mt-0.5 text-[11px] text-[var(--app-text-muted)] sm:text-xs">
              Ask the AI to build new sentences for this lesson.
            </p>
          </div>
          <button
            class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]/80 text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
            aria-label="Close"
            @click="handleClose"
          >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form
          class="mt-3 flex max-h-[70vh] flex-col"
          @submit.prevent="handleSubmit"
        >
          <div class="flex-1 space-y-4 overflow-y-auto pr-1">
            <label class="block text-xs text-[var(--app-text)]">
              <span class="mb-1 block text-[10px] uppercase tracking-[0.25em] text-[var(--app-text-muted)]">
                Custom prompt
              </span>
              <textarea
                v-model="form.customPrompt"
                rows="4"
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-1 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
                placeholder="Tone, topic, difficulty…"
              ></textarea>
            </label>

            <div
              class="space-y-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-panel-muted)]/70 p-3 text-xs text-[var(--app-text)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/70"
            >
              <label class="flex items-start gap-2">
                <input
                  v-model="form.replaceExisting"
                  type="checkbox"
                  class="mt-0.5 h-4 w-4 rounded border-[var(--app-border)] bg-transparent text-[var(--app-accent)] focus:ring-[var(--app-accent)] dark:border-[var(--app-border-dark)]"
                />
                <span>Replace existing sentences</span>
              </label>
            </div>

            <p
              v-if="errorMessage"
              class="text-xs text-[var(--app-accent)]"
            >
              {{ errorMessage }}
            </p>
          </div>

          <div class="mt-3 flex flex-col gap-2 border-t border-[var(--app-border)] pt-3 text-xs sm:flex-row sm:justify-end">
            <button
              type="button"
              class="h-9 rounded-full border border-[var(--app-border)] px-4 font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:hover:bg-[color:rgba(255,255,255,0.08)]"
              @click="handleClose"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="h-9 rounded-full bg-[var(--app-accent)] px-5 font-semibold text-white shadow-[0_10px_25px_rgba(249,115,22,0.3)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="isSubmitting"
            >
              {{ isSubmitting ? 'Generating…' : 'Generate sentences' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </transition>
</template>


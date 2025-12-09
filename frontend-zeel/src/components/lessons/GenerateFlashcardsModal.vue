<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { generateLessonFlashcards } from '@/api/lessonFlashcards'

const props = defineProps<{
  open: boolean
  lessonId: number
}>()

const emit = defineEmits<{
  close: []
  queued: []
}>()

const form = reactive({
  level: '',
  domain: '',
  minItems: null as number | null,
  maxItems: null as number | null,
  notes: '',
  inlinePrompt: '',
  savePreset: false,
  replaceExisting: true,
})

const isSubmitting = ref(false)
const errorMessage = ref('')

const resetForm = () => {
  form.level = ''
  form.domain = ''
  form.minItems = null
  form.maxItems = null
  form.notes = ''
  form.inlinePrompt = ''
  form.savePreset = false
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

const normalizeNumber = (value: number | null) => {
  if (typeof value === 'number' && !Number.isNaN(value)) {
    return value
  }
  return undefined
}

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
    await generateLessonFlashcards(props.lessonId, {
      level: form.level || undefined,
      domain: form.domain || undefined,
      min_items: normalizeNumber(form.minItems),
      max_items: normalizeNumber(form.maxItems),
      notes: form.notes || undefined,
      inline_prompt: form.inlinePrompt || undefined,
      save_preset: form.savePreset,
      replace_existing: form.replaceExisting,
    })
    emit('queued')
    resetForm()
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Failed to queue flashcard generation'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end justify-center bg-[var(--app-overlay)] backdrop-blur-sm px-0 py-0 sm:items-center sm:px-4 sm:py-6"
    >
      <div
        class="w-full max-w-md sm:max-w-xl flex max-h-[100vh] flex-col rounded-t-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 pb-5 pt-4 text-[var(--app-text)] shadow-[0_24px_80px_rgba(15,23,42,0.55)] sm:rounded-[32px] sm:px-6 sm:pb-7 sm:pt-6 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)]"
      >
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold sm:text-lg">Generate flashcards</h2>
            <p class="text-xs text-[var(--app-text-muted)] sm:text-sm dark:text-[var(--app-text-muted)]">
              Configure the AI prompt for this lesson.
            </p>
          </div>
          <button
            class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]/80 text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:hover:bg-[color:rgba(255,255,255,0.08)]"
            aria-label="Close"
            @click="handleClose"
          >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form class="mt-4 flex flex-1 flex-col" @submit.prevent="handleSubmit">
          <div class="space-y-5 overflow-y-auto pr-1">
            <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
              <span class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Level</span>
              <input
                v-model="form.level"
                type="text"
                class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
                placeholder="e.g. B2"
              />
            </label>
            <label class="text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
              <span class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Domain / topic</span>
              <input
                v-model="form.domain"
                type="text"
                class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
                placeholder="Business, travel, ..."
              />
            </label>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
              <span class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Min items</span>
              <input
                v-model.number="form.minItems"
                type="number"
                min="1"
                class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
                placeholder="e.g. 8"
              />
            </label>
            <label class="text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
              <span class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">Max items</span>
              <input
                v-model.number="form.maxItems"
                type="number"
                min="1"
                class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
                placeholder="e.g. 15"
              />
            </label>
          </div>

          <label class="block text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
            <span
              class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]"
              >Notes / instructions</span
            >
            <textarea
              v-model="form.notes"
              rows="3"
              class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
              placeholder="Any context you'd like the AI to consider."
            ></textarea>
          </label>

          <label class="block text-sm text-[var(--app-text)] dark:text-[var(--app-text)]">
            <span
              class="mb-1 block text-xs uppercase tracking-[0.3em] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]"
              >Inline prompt</span
            >
            <textarea
              v-model="form.inlinePrompt"
              rows="3"
              class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)]"
              placeholder="Custom AI instructions"
            ></textarea>
          </label>

          <div
            class="space-y-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-4 text-sm text-[var(--app-text)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/70 dark:text-[var(--app-text)]"
          >
            <label class="flex items-start gap-3">
              <input
                v-model="form.savePreset"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-[var(--app-border)] bg-transparent text-[var(--app-accent)] focus:ring-[var(--app-accent)] dark:border-[var(--app-border-dark)]"
              />
              <span>Save as default for this lesson</span>
            </label>
            <label class="flex items-start gap-3">
              <input
                v-model="form.replaceExisting"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-[var(--app-border)] bg-transparent text-[var(--app-accent)] focus:ring-[var(--app-accent)] dark:border-[var(--app-border-dark)]"
              />
              <span>Replace existing flashcards</span>
            </label>
          </div>

          <p v-if="errorMessage" class="text-sm text-[var(--app-accent)]">
            {{ errorMessage }}
          </p>
          </div>

          <div class="mt-4 flex flex-col gap-3 border-t border-[var(--app-border)] pt-3 sm:flex-row sm:justify-end">
            <button
              type="button"
              class="h-10 rounded-full border border-[var(--app-border)] px-5 text-sm font-semibold text-[var(--app-text)] transition hover:bg-[var(--app-panel-muted)] dark:border-[var(--app-border-dark)] dark:text-[var(--app-text)] dark:hover:bg-[color:rgba(255,255,255,0.08)] dark:hover:text-[var(--app-text)]"
              @click="handleClose"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="h-10 rounded-full bg-[var(--app-accent)] px-6 text-sm font-semibold text-white shadow-[0_15px_35px_rgba(249,115,22,0.35)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="isSubmitting"
            >
              {{ isSubmitting ? 'Generating…' : 'Generate flashcards' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </transition>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { importLessonWords } from '@/api/lessonFlashcards'
import type { LessonWordInput } from '@/types/lesson'

const props = defineProps<{
  open: boolean
  lessonId: number
}>()

const emit = defineEmits<{
  close: []
  imported: [count: number]
}>()

const jsonInput = ref(`[
  {
    "term": "in a hurry",
    "meaning": "moving quickly because there is not much time",
    "example_sentence": "I left home in a hurry.",
    "translation": "با عجله"
  }
]`)
const replaceExisting = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref('')

watch(
  () => props.open,
  (open) => {
    if (!open) {
      errorMessage.value = ''
      isSubmitting.value = false
    }
  },
)

const parsedPreview = computed(() => {
  try {
    const raw = JSON.parse(jsonInput.value)
    const words = Array.isArray(raw) ? raw : Array.isArray(raw?.words) ? raw.words : null
    return Array.isArray(words) ? words.length : 0
  } catch {
    return 0
  }
})

function closeModal() {
  if (isSubmitting.value) return
  emit('close')
}

function parseWords(): LessonWordInput[] {
  const raw = JSON.parse(jsonInput.value)
  const words = Array.isArray(raw) ? raw : raw?.words

  if (!Array.isArray(words) || words.length === 0) {
    throw new Error('JSON must be an array or an object with a words array.')
  }

  return words.map((word, index) => {
    if (!word || typeof word !== 'object') {
      throw new Error(`Item ${index + 1} must be an object.`)
    }

    const term = String((word as Record<string, unknown>).term ?? '').trim()
    if (!term) {
      throw new Error(`Item ${index + 1} is missing term.`)
    }

    return {
      term,
      lemma: normalizeNullable((word as Record<string, unknown>).lemma),
      phonetic: normalizeNullable((word as Record<string, unknown>).phonetic),
      part_of_speech: normalizeNullable((word as Record<string, unknown>).part_of_speech),
      meaning: normalizeNullable((word as Record<string, unknown>).meaning),
      example_sentence: normalizeNullable((word as Record<string, unknown>).example_sentence),
      translation: normalizeNullable((word as Record<string, unknown>).translation),
      meta: isPlainObject((word as Record<string, unknown>).meta) ? ((word as Record<string, unknown>).meta as Record<string, unknown>) : null,
    }
  })
}

function normalizeNullable(value: unknown): string | null {
  const normalized = String(value ?? '').trim()
  return normalized !== '' ? normalized : null
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value)
}

async function handleSubmit() {
  if (isSubmitting.value) return

  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const words = parseWords()
    await importLessonWords(props.lessonId, {
      words,
      replace_existing: replaceExisting.value,
    })
    emit('imported', words.length)
    emit('close')
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Could not import flashcards.'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="modal-fade">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal" />

      <div class="relative w-full max-w-2xl overflow-hidden rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-2xl">
        <div class="flex items-center justify-between px-6 py-5">
          <div>
            <h2 class="text-lg font-display font-bold text-[var(--app-text)]">Import Flashcards JSON</h2>
            <p class="text-xs text-[var(--app-text-muted)]">Paste an array of words or an object with a <code>words</code> array.</p>
          </div>
          <button class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)]" @click="closeModal">
            <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
          </button>
        </div>

        <div class="space-y-4 px-6 pb-6">
          <div v-if="errorMessage" class="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-300">
            {{ errorMessage }}
          </div>

          <textarea
            v-model="jsonInput"
            rows="14"
            spellcheck="false"
            class="zee-input min-h-[320px] w-full resize-y font-mono text-xs leading-6"
          />

          <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] px-4 py-3">
            <label class="flex items-center gap-2 text-sm text-[var(--app-text)]">
              <input v-model="replaceExisting" type="checkbox" class="h-4 w-4 rounded border-[var(--app-border)] bg-transparent" />
              Replace existing flashcards
            </label>
            <span class="text-xs text-[var(--app-text-muted)]">
              {{ parsedPreview ? `${parsedPreview} item${parsedPreview > 1 ? 's' : ''} detected` : 'Preview unavailable until JSON is valid' }}
            </span>
          </div>

          <button
            type="button"
            class="zee-btn w-full py-3"
            :disabled="isSubmitting"
            @click="handleSubmit"
          >
            <span v-if="isSubmitting">Importing…</span>
            <span v-else>Import flashcards</span>
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
  transform: scale(0.98);
}
</style>

<script setup lang="ts">
import { reactive, watch, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { updateLessonWord } from '@/api/lessonFlashcards'
import type { LessonFlashcard } from '@/types/lesson'

const props = defineProps<{
  open: boolean
  lessonId: number
  card: LessonFlashcard | null
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const form = reactive({
  term: '',
  phonetic: '',
  part_of_speech: '',
  meaning: '',
  translation: '',
  example_sentence: '',
})

const isSubmitting = ref(false)
const errorMessage = ref('')

watch(
  () => [props.open, props.card],
  () => {
    form.term = props.card?.term ?? ''
    form.phonetic = props.card?.phonetic ?? ''
    form.part_of_speech = props.card?.partOfSpeech ?? ''
    form.meaning = props.card?.meaning ?? ''
    form.translation = props.card?.translation ?? ''
    form.example_sentence = props.card?.exampleSentence ?? ''
    errorMessage.value = ''
    isSubmitting.value = false
  },
  { immediate: true },
)

function normalizeNullable(value: string): string | null {
  const normalized = value.trim()
  return normalized !== '' ? normalized : null
}

function closeModal() {
  if (isSubmitting.value) return
  emit('close')
}

async function handleSubmit() {
  if (!props.card || isSubmitting.value) return
  if (form.term.trim() === '') {
    errorMessage.value = 'Term is required.'
    return
  }

  isSubmitting.value = true
  errorMessage.value = ''

  try {
    await updateLessonWord(props.lessonId, props.card.id, {
      term: form.term.trim(),
      phonetic: normalizeNullable(form.phonetic),
      part_of_speech: normalizeNullable(form.part_of_speech),
      meaning: normalizeNullable(form.meaning),
      translation: normalizeNullable(form.translation),
      example_sentence: normalizeNullable(form.example_sentence),
    })
    emit('saved')
    emit('close')
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Could not save flashcard changes.'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="modal-fade">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal" />

      <div class="relative w-full max-w-xl overflow-hidden rounded-[28px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-2xl">
        <div class="flex items-center justify-between px-6 py-5">
          <div>
            <h2 class="text-lg font-display font-bold text-[var(--app-text)]">Edit Flashcard</h2>
            <p class="text-xs text-[var(--app-text-muted)]">Update the current word and save it back to the lesson.</p>
          </div>
          <button class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)]" @click="closeModal">
            <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
          </button>
        </div>

        <div class="space-y-4 px-6 pb-6">
          <div v-if="errorMessage" class="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-300">
            {{ errorMessage }}
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <input v-model="form.term" type="text" placeholder="Term" class="zee-input sm:col-span-2" />
            <input v-model="form.phonetic" type="text" placeholder="Phonetic" class="zee-input" />
            <input v-model="form.part_of_speech" type="text" placeholder="Part of speech" class="zee-input" />
          </div>

          <textarea v-model="form.meaning" rows="3" placeholder="Meaning" class="zee-input w-full resize-y" />
          <textarea v-model="form.translation" rows="3" placeholder="Translation" class="zee-input w-full resize-y" />
          <textarea v-model="form.example_sentence" rows="4" placeholder="Example sentence" class="zee-input w-full resize-y" />

          <button
            type="button"
            class="zee-btn w-full py-3"
            :disabled="isSubmitting || !card"
            @click="handleSubmit"
          >
            <span v-if="isSubmitting">Saving…</span>
            <span v-else>Save changes</span>
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

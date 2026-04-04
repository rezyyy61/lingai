<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { updateLessonSentence } from '@/api/lessonShadowing'
import type { LessonShadowSentence } from '@/types/lesson'

const props = defineProps<{
  open: boolean
  lessonId: number
  sentence: LessonShadowSentence | null
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const form = reactive({
  text: '',
  translation: '',
  source: 'generated' as 'original' | 'generated',
})

const isSubmitting = ref(false)
const errorMessage = ref('')

watch(
  () => [props.open, props.sentence],
  () => {
    form.text = props.sentence?.text ?? ''
    form.translation = props.sentence?.translation ?? ''
    form.source = props.sentence?.source ?? 'generated'
    errorMessage.value = ''
    isSubmitting.value = false
  },
  { immediate: true },
)

function closeModal() {
  if (isSubmitting.value) return
  emit('close')
}

function normalizeNullable(value: string): string | null {
  const normalized = value.trim()
  return normalized !== '' ? normalized : null
}

async function handleSubmit() {
  if (!props.sentence || isSubmitting.value) return
  if (form.text.trim() === '') {
    errorMessage.value = 'Sentence text is required.'
    return
  }

  isSubmitting.value = true
  errorMessage.value = ''

  try {
    await updateLessonSentence(props.lessonId, props.sentence.id, {
      text: form.text.trim(),
      translation: normalizeNullable(form.translation),
      source: form.source,
    })
    emit('saved')
    emit('close')
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Could not save sentence changes.'
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
            <h2 class="text-lg font-display font-bold text-[var(--app-text)]">Edit Sentence</h2>
            <p class="text-xs text-[var(--app-text-muted)]">Update the current shadowing sentence.</p>
          </div>
          <button class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)]" @click="closeModal">
            <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
          </button>
        </div>

        <div class="space-y-4 px-6 pb-6">
          <div v-if="errorMessage" class="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-300">
            {{ errorMessage }}
          </div>

          <textarea v-model="form.text" rows="6" placeholder="Sentence text" class="zee-input w-full resize-y" />
          <textarea v-model="form.translation" rows="4" placeholder="Translation" class="zee-input w-full resize-y" />

          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              class="rounded-2xl border px-4 py-3 text-sm font-semibold transition"
              :class="form.source === 'generated' ? 'border-[var(--app-accent)] bg-[var(--app-accent-soft)] text-[var(--app-accent)]' : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text-muted)]'"
              @click="form.source = 'generated'"
            >
              Generated
            </button>
            <button
              type="button"
              class="rounded-2xl border px-4 py-3 text-sm font-semibold transition"
              :class="form.source === 'original' ? 'border-[var(--app-accent)] bg-[var(--app-accent-soft)] text-[var(--app-accent)]' : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text-muted)]'"
              @click="form.source = 'original'"
            >
              Original
            </button>
          </div>

          <button
            type="button"
            class="zee-btn w-full py-3"
            :disabled="isSubmitting || !sentence"
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

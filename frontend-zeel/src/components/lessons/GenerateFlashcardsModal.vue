<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { generateLessonFlashcards } from '@/api/lessonFlashcards'
import { Icon } from '@iconify/vue'

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
    handleClose() // Auto close on success
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Failed to queue flashcard generation'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <transition name="modal-fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <!-- Backdrop -->
      <div 
        class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" 
        @click="handleClose"
      />

      <!-- Modal -->
      <div
        class="relative w-full max-w-lg overflow-hidden rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-2xl transition-all"
      >
        <!-- Header -->
        <div class="px-6 pt-6 pb-2 flex items-center justify-between">
            <div class="flex items-center gap-3">
               <div class="h-10 w-10 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 dark:bg-orange-900/20 dark:text-orange-400">
                  <Icon icon="solar:card-2-bold-duotone" class="h-6 w-6" />
               </div>
               <div>
                  <h2 class="text-lg font-display font-bold text-[var(--app-text)]">Generate Flashcards</h2>
                  <p class="text-xs text-[var(--app-text-muted)]">AI will extract vocabulary from your lesson</p>
               </div>
            </div>
           
           <button 
             @click="handleClose"
             class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)] hover:text-[var(--app-text)] transition"
           >
              <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
           </button>
        </div>

        <form @submit.prevent="handleSubmit" class="p-6 space-y-6">
           <!-- Error -->
           <div v-if="errorMessage" class="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-medium text-red-600 border border-red-100 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-400">
               <Icon icon="solar:danger-triangle-bold" class="h-4 w-4 shrink-0" />
               {{ errorMessage }}
           </div>

           <div class="space-y-4">
              <!-- Grid Row 1 -->
              <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-1.5">
                     <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1">Difficulty Level</label>
                     <input
                       v-model="form.level"
                       type="text"
                       placeholder="e.g. B2"
                       class="zee-input"
                     />
                  </div>
                  <div class="space-y-1.5">
                     <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1">Domain / Topic</label>
                     <input
                       v-model="form.domain"
                       type="text"
                       placeholder="e.g. Travel"
                       class="zee-input"
                     />
                  </div>
              </div>

               <!-- Grid Row 2 -->
              <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-1.5">
                     <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1">Min Items</label>
                     <input
                       v-model.number="form.minItems"
                       type="number"
                       min="1"
                       placeholder="8"
                       class="zee-input"
                     />
                  </div>
                   <div class="space-y-1.5">
                     <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1">Max Items</label>
                     <input
                       v-model.number="form.maxItems"
                       type="number"
                       min="1"
                       placeholder="15"
                       class="zee-input"
                     />
                  </div>
              </div>

              <!-- Textareas -->
               <div class="space-y-1.5">
                  <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1 flex items-center gap-1">
                     Instructions
                     <Icon icon="solar:info-circle-linear" class="h-3 w-3" />
                  </label>
                  <textarea
                    v-model="form.notes"
                    rows="2"
                    placeholder="Focus on specific vocabulary..."
                    class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] outline-none focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] transition-all resize-none"
                  ></textarea>
               </div>
           </div>

           <!-- Toggles -->
           <div class="flex flex-col gap-2 rounded-2xl bg-[var(--app-panel-muted)] p-4 border border-[var(--app-border)]">
              <label class="flex items-center justify-between cursor-pointer group">
                 <span class="text-sm font-medium text-[var(--app-text)]">Replace existing cards</span>
                 <div class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="form.replaceExisting" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--app-accent)]"></div>
                 </div>
              </label>
              
              <div class="h-px bg-[var(--app-border)]/50" />

              <label class="flex items-center justify-between cursor-pointer group">
                 <span class="text-sm font-medium text-[var(--app-text)]">Save settings as default</span>
                 <div class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="form.savePreset" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--app-accent)]"></div>
                 </div>
              </label>
           </div>

           <!-- Actions -->
           <button
             type="submit"
             class="zee-btn w-full py-3.5 flex items-center justify-center gap-2"
             :disabled="isSubmitting"
           >
              <Icon v-if="isSubmitting" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
              <div v-else class="flex items-center gap-2">
                 <Icon icon="solar:magic-stick-3-bold-duotone" class="h-5 w-5" />
                 <span>Generate Flashcards</span>
              </div>
           </button>
        </form>
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

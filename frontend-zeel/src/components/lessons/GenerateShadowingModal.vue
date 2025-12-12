<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { generateLessonShadowingSentences } from '@/api/lessonShadowing'
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
    handleClose() // Auto close on success
  } catch (error) {
    console.error(error)
    errorMessage.value = 'Failed to queue shadowing sentences'
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
               <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400">
                  <Icon icon="solar:microphone-2-bold-duotone" class="h-6 w-6" />
               </div>
               <div>
                  <h2 class="text-lg font-display font-bold text-[var(--app-text)]">Shadowing Practice</h2>
                  <p class="text-xs text-[var(--app-text-muted)]">Generate sentences for speaking practice</p>
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
              <!-- Textarea -->
               <div class="space-y-1.5">
                  <label class="text-xs font-semibold text-[var(--app-text-muted)] ml-1 flex items-center gap-1">
                     Custom Prompt
                     <Icon icon="solar:magic-stick-3-linear" class="h-3 w-3" />
                  </label>
                  <textarea
                    v-model="form.customPrompt"
                    rows="4"
                    placeholder="E.g. Focus on business negotiation phrases, B2 level..."
                    class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] outline-none focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] transition-all resize-none"
                  ></textarea>
               </div>
           </div>

           <!-- Toggles -->
           <div class="flex flex-col gap-2 rounded-2xl bg-[var(--app-panel-muted)] p-4 border border-[var(--app-border)]">
              <label class="flex items-center justify-between cursor-pointer group">
                 <span class="text-sm font-medium text-[var(--app-text)]">Replace existing sentences</span>
                 <div class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="form.replaceExisting" class="sr-only peer">
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
                 <Icon icon="solar:stars-minimalistic-bold-duotone" class="h-5 w-5" />
                 <span>Generate Sentences</span>
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


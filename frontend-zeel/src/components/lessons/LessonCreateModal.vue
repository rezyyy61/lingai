<script setup lang="ts">
import { reactive, ref, watch, computed } from 'vue'
import { createLesson, createLessonFromAudio, createLessonFromYoutube } from '@/api/lessonApi'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  open: boolean
  workspaceId: number
}>()

const emit = defineEmits<{
  close: []
  created: [lessonId: number]
}>()

const activeTab = ref<'text' | 'youtube' | 'audio'>('text')

const textForm = reactive({
  title: '',
  level: '',
  tags: '',
  original_text: '',
})

const youtubeForm = reactive({
  youtube_url: '',
  title: '',
  level: '',
  tags: '',
})

const audioForm = reactive({
  title: '',
  level: '',
  tags: '',
  file: null as File | null,
})

const textLoading = ref(false)
const youtubeLoading = ref(false)
const audioLoading = ref(false)
const errorMessage = ref('')

const isLoading = computed(() => textLoading.value || youtubeLoading.value || audioLoading.value)

const resetForms = () => {
  textForm.title = ''
  textForm.level = ''
  textForm.tags = ''
  textForm.original_text = ''

  youtubeForm.youtube_url = ''
  youtubeForm.title = ''
  youtubeForm.level = ''
  youtubeForm.tags = ''

  audioForm.title = ''
  audioForm.level = ''
  audioForm.tags = ''
  audioForm.file = null

  errorMessage.value = ''
  activeTab.value = 'text'
}

const parseTags = (value: string) =>
  value
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)

const handleClose = () => {
  if (isLoading.value) return
  resetForms()
  emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetForms()
    }
  },
)

const handleTextSubmit = async () => {
  if (textLoading.value) return
  if (!props.workspaceId) {
    errorMessage.value = 'Workspace is missing.'
    return
  }

  errorMessage.value = ''
  textLoading.value = true
  try {
    const lesson = await createLesson(props.workspaceId, {
      title: textForm.title || 'Untitled Lesson',
      original_text: textForm.original_text,
      level: textForm.level || undefined,
      tags: parseTags(textForm.tags),
    })
    resetForms()
    emit('created', lesson.id)
  } catch (error) {
    errorMessage.value = 'Failed to create text lesson'
    console.error(error)
  } finally {
    textLoading.value = false
  }
}

const handleYoutubeSubmit = async () => {
  if (youtubeLoading.value) return
  if (!props.workspaceId) {
    errorMessage.value = 'Workspace is missing.'
    return
  }
  if (!youtubeForm.youtube_url) {
    errorMessage.value = 'Please enter a YouTube URL'
    return
  }

  errorMessage.value = ''
  youtubeLoading.value = true
  try {
    const lesson = await createLessonFromYoutube(props.workspaceId, {
      youtube_url: youtubeForm.youtube_url,
      title: youtubeForm.title || undefined,
      level: youtubeForm.level || undefined,
      tags: parseTags(youtubeForm.tags),
      language: 'en',
    })
    resetForms()
    emit('created', lesson.id)
  } catch (error) {
    errorMessage.value = 'Failed to create lesson from YouTube'
    console.error(error)
  } finally {
    youtubeLoading.value = false
  }
}

const fileInput = ref<HTMLInputElement | null>(null)

const triggerFileInput = () => {
  fileInput.value?.click()
}

const handleAudioSubmit = async () => {
  if (audioLoading.value) return
  if (!props.workspaceId) {
    errorMessage.value = 'Workspace is missing.'
    return
  }
  if (!audioForm.file) {
    errorMessage.value = 'Please choose an audio file'
    return
  }

  errorMessage.value = ''
  audioLoading.value = true
  try {
    const lesson = await createLessonFromAudio(props.workspaceId, {
      file: audioForm.file,
      title: audioForm.title || undefined,
      level: audioForm.level || undefined,
      tags: parseTags(audioForm.tags),
      language: 'en',
    })
    resetForms()
    emit('created', lesson.id)

    // Reset file input
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  } catch (error) {
    errorMessage.value = 'Failed to create audio lesson'
    console.error(error)
  } finally {
    audioLoading.value = false
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
           <h2 class="text-xl font-display font-bold text-[var(--app-text)]">Create Lesson</h2>
           <button 
             @click="handleClose"
             class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--app-text-muted)] hover:bg-[var(--app-panel-muted)] hover:text-[var(--app-text)] transition"
           >
              <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
           </button>
        </div>

        <!-- Tabs -->
        <div class="px-6 pb-4">
           <div class="flex p-1 gap-1 rounded-2xl bg-[var(--app-panel-muted)] border border-[var(--app-border)]">
              <button
                 v-for="tab in ['text', 'youtube', 'audio']"
                 :key="tab"
                 @click="activeTab = tab as any"
                 class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-semibold transition-all relative overflow-hidden"
                 :class="activeTab === tab 
                    ? 'text-[var(--app-text)] bg-[var(--app-surface-elevated)] shadow-sm ring-1 ring-black/5 dark:ring-white/5' 
                    : 'text-[var(--app-text-muted)] hover:text-[var(--app-text)] hover:bg-[var(--app-surface)]/50'"
              >
                  <Icon v-if="tab === 'text'" icon="solar:document-text-bold-duotone" class="h-4 w-4 opacity-80" />
                  <Icon v-else-if="tab === 'youtube'" icon="solar:play-circle-bold-duotone" class="h-4 w-4 opacity-80" />
                  <Icon v-else icon="solar:file-audio-bold-duotone" class="h-4 w-4 opacity-80" />
                  <span class="capitalize">{{ tab }}</span>
              </button>
           </div>
        </div>

        <!-- Content -->
        <div class="px-6 pb-6 min-h-[300px]">
           <!-- Error -->
           <div v-if="errorMessage" class="mb-4 flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-medium text-red-600 border border-red-100 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-400">
               <Icon icon="solar:danger-triangle-bold" class="h-4 w-4 shrink-0" />
               {{ errorMessage }}
           </div>

           <!-- Text Form -->
           <transition name="slide-fade" mode="out-in">
              <form v-if="activeTab === 'text'" key="text" @submit.prevent="handleTextSubmit" class="space-y-4">
                 
                 <div class="space-y-4">
                    <input
                      v-model="textForm.title"
                      type="text"
                      placeholder="Lesson title (optional)"
                      class="zee-input font-medium"
                    />
                    
                    <textarea
                      v-model="textForm.original_text"
                      rows="6"
                      required
                      placeholder="Paste your text here..."
                      class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] outline-none focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] transition-all resize-none leading-relaxed"
                    ></textarea>

                    <div class="flex gap-3">
                       <div class="w-1/3">
                          <select v-model="textForm.level" class="zee-input cursor-pointer appearance-none">
                             <option value="" disabled selected>Level</option>
                             <option value="">Any</option>
                             <option value="A2">A2</option>
                             <option value="B1">B1</option>
                             <option value="B2">B2</option>
                             <option value="C1">C1</option>
                          </select>
                       </div>
                       <div class="flex-1">
                          <input
                            v-model="textForm.tags"
                            type="text"
                            placeholder="Tags (comma separated)"
                            class="zee-input"
                          />
                       </div>
                    </div>
                 </div>

                 <button
                   type="submit"
                   class="zee-btn w-full py-3.5 flex items-center justify-center gap-2 mt-2"
                   :disabled="textLoading || !textForm.original_text"
                 >
                    <Icon v-if="textLoading" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
                    <span v-else>Create Lesson</span>
                 </button>
              </form>

              <!-- Youtube Form -->
              <form v-else-if="activeTab === 'youtube'" key="youtube" @submit.prevent="handleYoutubeSubmit" class="space-y-6 pt-2">
                 <div class="rounded-2xl bg-[var(--app-panel-muted)] border border-[var(--app-border)] p-6 text-center space-y-2">
                     <div class="mx-auto h-12 w-12 rounded-full bg-red-100 flex items-center justify-center text-red-600 dark:bg-red-900/20 dark:text-red-400">
                        <Icon icon="solar:play-circle-bold" class="h-6 w-6" />
                     </div>
                     <p class="text-sm font-medium text-[var(--app-text)]">YouTube Import</p>
                     <p class="text-xs text-[var(--app-text-muted)]">We'll fetch the transcript and generate exercises.</p>
                 </div>

                 <div class="space-y-4">
                     <input
                       v-model="youtubeForm.youtube_url"
                       type="url"
                       required
                       placeholder="Paste YouTube URL"
                       class="zee-input"
                     />
                     
                     <div class="flex gap-3">
                       <div class="w-1/3">
                          <select v-model="youtubeForm.level" class="zee-input cursor-pointer appearance-none">
                             <option value="" disabled selected>Level</option>
                             <option value="">Any</option>
                             <option value="A2">A2</option>
                             <option value="B1">B1</option>
                             <option value="B2">B2</option>
                             <option value="C1">C1</option>
                          </select>
                       </div>
                       <div class="flex-1">
                          <input
                            v-model="youtubeForm.tags"
                            type="text"
                            placeholder="Tags (optional)"
                            class="zee-input"
                          />
                       </div>
                    </div>
                 </div>

                 <button
                   type="submit"
                   class="zee-btn w-full py-3.5 flex items-center justify-center gap-2"
                   :disabled="youtubeLoading || !youtubeForm.youtube_url"
                 >
                    <Icon v-if="youtubeLoading" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
                    <span v-else>Import Video</span>
                 </button>
              </form>

              <!-- Audio Form -->
              <form v-else key="audio" @submit.prevent="handleAudioSubmit" class="space-y-6 pt-2">
                 <div 
                   @click="triggerFileInput"
                   class="group cursor-pointer rounded-2xl border-2 border-dashed border-[var(--app-border)] bg-[var(--app-panel-muted)] p-8 text-center transition-all hover:border-[var(--app-accent)] hover:bg-[var(--app-accent-soft)]"
                   :class="audioForm.file ? 'border-[var(--app-accent)] bg-[var(--app-accent-soft)]' : ''"
                 >
                     <div class="mx-auto h-12 w-12 rounded-full bg-[var(--app-surface-elevated)] flex items-center justify-center text-[var(--app-accent)] shadow-sm mb-3 group-hover:scale-110 transition-transform">
                        <Icon v-if="audioForm.file" icon="solar:music-note-bold" class="h-6 w-6" />
                        <Icon v-else icon="solar:upload-track-bold-duotone" class="h-6 w-6" />
                     </div>
                     
                     <div v-if="audioForm.file">
                        <p class="text-sm font-semibold text-[var(--app-text)] truncate max-w-[200px] mx-auto">{{ audioForm.file.name }}</p>
                        <p class="text-xs text-[var(--app-text-muted)] mt-1">Click to change file</p>
                     </div>
                     <div v-else>
                        <p class="text-sm font-medium text-[var(--app-text)]">Click to upload audio</p>
                        <p class="text-xs text-[var(--app-text-muted)] mt-1">MP3, WAV, M4A supported</p>
                     </div>
                     
                     <input
                       ref="fileInput"
                       type="file"
                       accept="audio/*"
                       class="hidden"
                       @change="(e) => (audioForm.file = (e.target as HTMLInputElement).files?.[0] ?? null)"
                     />
                 </div>

                 <div class="flex gap-3">
                    <div class="w-1/3">
                       <select v-model="audioForm.level" class="zee-input cursor-pointer appearance-none">
                          <option value="" disabled selected>Level</option>
                          <option value="">Any</option>
                          <option value="A2">A2</option>
                          <option value="B1">B1</option>
                          <option value="B2">B2</option>
                          <option value="C1">C1</option>
                       </select>
                    </div>
                    <div class="flex-1">
                       <input
                         v-model="audioForm.tags"
                         type="text"
                         placeholder="Tags (optional)"
                         class="zee-input"
                       />
                    </div>
                 </div>

                 <button
                   type="submit"
                   class="zee-btn w-full py-3.5 flex items-center justify-center gap-2"
                   :disabled="audioLoading || !audioForm.file"
                 >
                    <Icon v-if="audioLoading" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
                    <span v-else>Transcribe & Create</span>
                 </button>
              </form>
           </transition>
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

.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: all 0.2s ease-out;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
  transform: translateY(10px);
}
</style>

<script setup lang="ts">
import { reactive, ref, watch, computed, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'
import { createLesson, createLessonFromYoutube } from '@/api/lessonApi'
import CreateLessonAiTab from '@/components/lessons/modals/CreateLessonAiTab.vue'

const props = defineProps<{
  open: boolean
  workspaceId: number
}>()

const emit = defineEmits<{
  close: []
  created: [lessonId: number]
}>()

const activeTab = ref<'text' | 'youtube' | 'ai'>('text')

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

const textLoading = ref(false)
const youtubeLoading = ref(false)
const aiLoading = ref(false)

const resetToken = ref(0)
const errorMessage = ref('')

const isLoading = computed(() => textLoading.value || youtubeLoading.value || aiLoading.value)

const parseTags = (value: string) =>
  value
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)

const resetForms = () => {
  textForm.title = ''
  textForm.level = ''
  textForm.tags = ''
  textForm.original_text = ''

  youtubeForm.youtube_url = ''
  youtubeForm.title = ''
  youtubeForm.level = ''
  youtubeForm.tags = ''

  errorMessage.value = ''
  activeTab.value = 'text'
  resetToken.value++
}

const handleClose = () => {
  if (isLoading.value) return
  resetForms()
  emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetForms()
  },
)

const handleTextSubmit = async () => {
  if (textLoading.value) return
  if (!props.workspaceId) {
    errorMessage.value = 'Workspace is missing.'
    return
  }
  if (!textForm.original_text.trim()) {
    errorMessage.value = 'Please paste some text.'
    return
  }

  errorMessage.value = ''
  textLoading.value = true

  try {
    const lesson = await createLesson(props.workspaceId, {
      title: textForm.title.trim() || 'Untitled Lesson',
      original_text: textForm.original_text.trim(),
      level: textForm.level || undefined,
      tags: parseTags(textForm.tags),
    })
    resetForms()
    emit('created', lesson.id)
  } catch (e) {
    errorMessage.value = 'Failed to create text lesson'
    console.error(e)
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
  if (!youtubeForm.youtube_url.trim()) {
    errorMessage.value = 'Please enter a YouTube URL'
    return
  }

  errorMessage.value = ''
  youtubeLoading.value = true

  try {
    const lesson = await createLessonFromYoutube(props.workspaceId, {
      youtube_url: youtubeForm.youtube_url.trim(),
      title: youtubeForm.title.trim() || undefined,
      level: youtubeForm.level || undefined,
      tags: parseTags(youtubeForm.tags),
    })
    resetForms()
    emit('created', lesson.id)
  } catch (e) {
    errorMessage.value = 'Failed to create lesson from YouTube'
    console.error(e)
  } finally {
    youtubeLoading.value = false
  }
}

const tabMeta: Record<'text' | 'youtube' | 'ai', { label: string; icon: string }> = {
  text: { label: 'Text', icon: 'solar:document-text-bold-duotone' },
  youtube: { label: 'YouTube', icon: 'solar:play-circle-bold-duotone' },
  ai: { label: 'AI', icon: 'solar:magic-stick-3-bold-duotone' },
}

const canSubmitText = computed(() => !textLoading.value && !!textForm.original_text.trim())
const canSubmitYoutube = computed(() => !youtubeLoading.value && !!youtubeForm.youtube_url.trim())

const onKeydown = (e: KeyboardEvent) => {
  if (!props.open) return
  if (e.key === 'Escape') handleClose()
}

watch(
  () => props.open,
  (v) => {
    if (v) window.addEventListener('keydown', onKeydown)
    else window.removeEventListener('keydown', onKeydown)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <transition name="modal-fade">
    <div v-if="open" class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/45 backdrop-blur-sm" @click="handleClose" />

      <div class="absolute inset-0 flex items-end justify-center p-3 sm:items-center sm:p-6">
        <div
          class="relative w-full max-w-xl overflow-hidden rounded-3xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] shadow-2xl"
          :class="isLoading ? 'pointer-events-auto' : ''"
        >
          <div class="flex items-center justify-between gap-3 px-5 pb-3 pt-5 sm:px-6 sm:pb-4 sm:pt-6">
            <div class="min-w-0">
              <div class="text-[11px] font-semibold tracking-wide text-[var(--app-text-muted)]">Workspace</div>
              <h2 class="mt-1 truncate text-xl font-display font-bold text-[var(--app-text)] sm:text-2xl">
                Create lesson
              </h2>
            </div>

            <button
              @click="handleClose"
              class="flex h-9 w-9 items-center justify-center rounded-full text-[var(--app-text-muted)] transition hover:bg-[var(--app-panel-muted)] hover:text-[var(--app-text)]"
              :disabled="isLoading"
            >
              <Icon icon="solar:close-circle-bold" class="h-6 w-6" />
            </button>
          </div>

          <div class="px-5 pb-4 sm:px-6">
            <div class="grid grid-cols-3 gap-1 rounded-2xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-1">
              <button
                v-for="tab in (['text', 'youtube', 'ai'] as const)"
                :key="tab"
                type="button"
                @click="activeTab = tab"
                class="flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold transition"
                :class="activeTab === tab
                  ? 'bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm ring-1 ring-black/5 dark:ring-white/5'
                  : 'text-[var(--app-text-muted)] hover:bg-[var(--app-surface)]/45 hover:text-[var(--app-text)]'"
                :disabled="isLoading"
              >
                <Icon :icon="tabMeta[tab].icon" class="h-4 w-4 opacity-85" />
                <span>{{ tabMeta[tab].label }}</span>
              </button>
            </div>
          </div>

          <div class="max-h-[72vh] overflow-y-auto px-5 pb-5 sm:max-h-[78vh] sm:px-6 sm:pb-6">
            <div
              v-if="errorMessage"
              class="mb-4 flex items-start gap-2 rounded-2xl border border-red-100 bg-red-50 p-3 text-xs font-medium text-red-700 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-300"
            >
              <Icon icon="solar:danger-triangle-bold" class="mt-0.5 h-4 w-4 shrink-0" />
              <div class="leading-relaxed">{{ errorMessage }}</div>
            </div>

            <transition name="slide-fade" mode="out-in">
              <form
                v-if="activeTab === 'text'"
                key="text"
                @submit.prevent="handleTextSubmit"
                class="space-y-4"
              >
                <div class="space-y-3">
                  <input
                    v-model="textForm.title"
                    type="text"
                    placeholder="Title (optional)"
                    class="zee-input font-medium"
                  />

                  <textarea
                    v-model="textForm.original_text"
                    rows="8"
                    required
                    placeholder="Paste your text here…"
                    class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] p-4 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] outline-none transition-all focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] resize-none leading-relaxed"
                  />

                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <select v-model="textForm.level" class="zee-input cursor-pointer appearance-none sm:col-span-1">
                      <option value="">Any level</option>
                      <option value="A1">A1</option>
                      <option value="A2">A2</option>
                      <option value="B1">B1</option>
                      <option value="B2">B2</option>
                      <option value="C1">C1</option>
                      <option value="C2">C2</option>
                    </select>

                    <input
                      v-model="textForm.tags"
                      type="text"
                      placeholder="Tags (comma separated)"
                      class="zee-input sm:col-span-2"
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  class="zee-btn w-full py-3.5 flex items-center justify-center gap-2"
                  :disabled="!canSubmitText"
                >
                  <Icon v-if="textLoading" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
                  <template v-else>
                    <Icon icon="solar:add-square-bold-duotone" class="h-5 w-5" />
                    <span>Create lesson</span>
                  </template>
                </button>
              </form>

              <form
                v-else-if="activeTab === 'youtube'"
                key="youtube"
                @submit.prevent="handleYoutubeSubmit"
                class="space-y-4"
              >
                <div class="rounded-3xl border border-[var(--app-border)] bg-[var(--app-panel-muted)] p-4">
                  <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--app-surface)] text-[var(--app-accent)]">
                      <Icon icon="solar:play-circle-bold-duotone" class="h-6 w-6" />
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-semibold text-[var(--app-text)]">Import from YouTube</div>
                      <div class="mt-1 text-xs leading-relaxed text-[var(--app-text-muted)]">
                        Best results when the video has captions/subtitles (CC).
                      </div>
                    </div>
                  </div>
                </div>

                <div class="space-y-3">
                  <input
                    v-model="youtubeForm.youtube_url"
                    type="url"
                    required
                    placeholder="YouTube URL"
                    class="zee-input"
                  />

                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <select v-model="youtubeForm.level" class="zee-input cursor-pointer appearance-none sm:col-span-1">
                      <option value="">Any level</option>
                      <option value="A1">A1</option>
                      <option value="A2">A2</option>
                      <option value="B1">B1</option>
                      <option value="B2">B2</option>
                      <option value="C1">C1</option>
                      <option value="C2">C2</option>
                    </select>

                    <input
                      v-model="youtubeForm.tags"
                      type="text"
                      placeholder="Tags (optional)"
                      class="zee-input sm:col-span-2"
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  class="zee-btn w-full py-3.5 flex items-center justify-center gap-2"
                  :disabled="!canSubmitYoutube"
                >
                  <Icon v-if="youtubeLoading" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
                  <template v-else>
                    <Icon icon="solar:download-bold-duotone" class="h-5 w-5" />
                    <span>Import video</span>
                  </template>
                </button>
              </form>

              <CreateLessonAiTab
                v-else
                key="ai"
                :workspace-id="workspaceId"
                :disabled="isLoading"
                :reset-token="resetToken"
                @loading="(v) => (aiLoading = v)"
                @error="(m) => (errorMessage = m)"
                @created="(id) => { resetForms(); emit('created', id) }"
              />
            </transition>

            <div class="mt-5 flex items-center justify-between text-[11px] text-[var(--app-text-muted)]">
              <div class="truncate">
                Tip: Press <span class="font-semibold text-[var(--app-text)]">Esc</span> to close
              </div>
              <div v-if="isLoading" class="flex items-center gap-2">
                <Icon icon="svg-spinners:3-dots-bounce" class="h-4 w-4" />
                <span>Working…</span>
              </div>
            </div>
          </div>

          <div class="h-3 bg-gradient-to-b from-transparent to-black/[0.04] dark:to-white/[0.03]" />
        </div>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.18s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
  transform: translateY(10px);
}
</style>

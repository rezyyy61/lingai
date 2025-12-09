<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { createLesson, createLessonFromAudio, createLessonFromYoutube } from '@/api/lessonApi'

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
      title: textForm.title,
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
  } catch (error) {
    errorMessage.value = 'Failed to create audio lesson'
    console.error(error)
  } finally {
    audioLoading.value = false
  }
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end justify-center bg-[var(--app-overlay)] px-0 py-0 backdrop-blur-sm sm:items-center sm:px-4 sm:py-6"
    >
      <div
        class="w-full max-w-md sm:max-w-2xl flex max-h-[100vh] flex-col rounded-t-3xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 pb-5 pt-4 shadow-[0_28px_90px_rgba(15,23,42,0.55)] sm:rounded-3xl sm:px-6 sm:pb-6 sm:pt-6 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2
              class="text-lg font-semibold text-[var(--app-text)] dark:text-[var(--app-text)]"
            >
              New lesson
            </h2>
            <p class="mt-1 text-xs text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
              Turn any text, YouTube video or audio into an interactive lesson inside this workspace.
            </p>
          </div>
          <button
            class="rounded-full p-1.5 text-[var(--app-text-muted)] transition-colors hover:bg-[var(--app-panel-muted)] hover:text-[var(--app-text)] dark:text-[var(--app-text-muted)] dark:hover:bg-[color:rgba(255,255,255,0.08)] dark:hover:text-[var(--app-text)]"
            aria-label="Close"
            @click="handleClose"
          >
            <svg
              class="h-5 w-5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="mt-5 flex-1 space-y-4 overflow-y-auto pr-1">
          <div
            class="flex rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-1 text-[11px] font-semibold uppercase tracking-[0.16em] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)]"
          >
            <button
              v-for="tab in ['text', 'youtube', 'audio']"
              :key="tab"
              type="button"
              :class="[
                'flex-1 rounded-2xl px-3 py-2 transition',
                activeTab === tab
                  ? 'bg-[var(--app-surface-elevated)] text-[var(--app-text)] shadow-sm dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)]'
                  : 'text-[var(--app-text-muted)] hover:text-[var(--app-text)] dark:text-[var(--app-text-muted)] dark:hover:text-[var(--app-text)]',
              ]"
              @click="activeTab = tab as 'text' | 'youtube' | 'audio'"
            >
              {{
                tab === 'text'
                  ? 'From text'
                  : tab === 'youtube'
                    ? 'From YouTube'
                    : 'From audio'
              }}
            </button>
          </div>

          <p
            class="text-xs text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]"
          >
            {{
              activeTab === 'text'
                ? 'Paste any short text or article. We will turn it into sentences, flashcards and exercises.'
                : activeTab === 'youtube'
                  ? 'Use a YouTube link. We fetch the transcript and build a lesson around it.'
                  : 'Upload an audio file. We transcribe it and turn it into a lesson.'
            }}
          </p>

          <p
            v-if="errorMessage"
            class="rounded-xl border border-[var(--app-accent-strong)] bg-[var(--app-accent-soft)] px-4 py-2 text-xs text-[var(--app-accent-strong)] dark:border-[var(--app-accent-strong)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-accent-soft)]"
          >
            {{ errorMessage }}
          </p>

          <form
            v-if="activeTab === 'text'"
            class="mt-2 space-y-4"
            @submit.prevent="handleTextSubmit"
          >
            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Title
              </label>
              <input
                v-model="textForm.title"
                type="text"
                required
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                placeholder="Short title for this lesson"
              />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Level
                </label>
                <select
                  v-model="textForm.level"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                >
                  <option value="">Level (optional)</option>
                  <option value="A2">A2</option>
                  <option value="B1">B1</option>
                  <option value="B2">B2</option>
                  <option value="C1">C1</option>
                </select>
              </div>
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Tags
                </label>
                <input
                  v-model="textForm.tags"
                  type="text"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                  placeholder="e.g. travel, daily life"
                />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Original text
              </label>
              <textarea
                v-model="textForm.original_text"
                rows="7"
                required
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                placeholder="Paste the text you want to turn into a lesson"
              ></textarea>
            </div>

            <button
              type="submit"
              class="mt-2 w-full rounded-2xl bg-[var(--app-accent)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-[0_12px_30px_rgba(194,65,12,0.25)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="textLoading"
            >
              {{ textLoading ? 'Creating...' : 'Create text lesson' }}
            </button>
          </form>

          <form
            v-else-if="activeTab === 'youtube'"
            class="mt-2 space-y-4"
            @submit.prevent="handleYoutubeSubmit"
          >
            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                YouTube URL
              </label>
              <input
                v-model="youtubeForm.youtube_url"
                type="url"
                required
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                placeholder="https://www.youtube.com/watch?v=..."
              />
            </div>

            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Title
              </label>
              <input
                v-model="youtubeForm.title"
                type="text"
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                placeholder="Optional custom title"
              />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Level
                </label>
                <select
                  v-model="youtubeForm.level"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                >
                  <option value="">Level (optional)</option>
                  <option value="A2">A2</option>
                  <option value="B1">B1</option>
                  <option value="B2">B2</option>
                  <option value="C1">C1</option>
                </select>
              </div>
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Tags
                </label>
                <input
                  v-model="youtubeForm.tags"
                  type="text"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                  placeholder="e.g. news, vlog, interview"
                />
              </div>
            </div>

            <button
              type="submit"
              class="mt-2 w-full rounded-2xl bg-[var(--app-accent)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-[0_12px_30px_rgba(194,65,12,0.25)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="youtubeLoading || !youtubeForm.youtube_url"
            >
              {{ youtubeLoading ? 'Creating...' : 'Create from YouTube' }}
            </button>
          </form>

          <form
            v-else
            class="mt-2 space-y-4"
            @submit.prevent="handleAudioSubmit"
          >
            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Title
              </label>
              <input
                v-model="audioForm.title"
                type="text"
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                placeholder="Optional custom title"
              />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Level
                </label>
                <select
                  v-model="audioForm.level"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                >
                  <option value="">Level (optional)</option>
                  <option value="A2">A2</option>
                  <option value="B1">B1</option>
                  <option value="B2">B2</option>
                  <option value="C1">C1</option>
                </select>
              </div>
              <div class="flex-1 space-y-1.5">
                <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                  Tags
                </label>
                <input
                  v-model="audioForm.tags"
                  type="text"
                  class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2.5 text-sm text-[var(--app-text)] outline-none ring-0 placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:placeholder:text-[var(--app-text-muted)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                  placeholder="e.g. podcast, call, story"
                />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="block text-xs font-medium text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Audio file
              </label>
              <input
                type="file"
                accept="audio/*"
                required
                class="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-3 py-2 text-sm text-[var(--app-text)] outline-none ring-0 file:mr-4 file:rounded-xl file:border-0 file:bg-[var(--app-accent)] file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-[var(--app-text)] dark:focus:border-[var(--app-accent)] dark:focus:ring-[color:var(--app-accent-strong)]"
                @change="(e) => (audioForm.file = (e.target as HTMLInputElement).files?.[0] ?? null)"
              />
              <p class="text-[11px] text-[var(--app-text-muted)] dark:text-[var(--app-text-muted)]">
                Supported formats: mp3, mp4, m4a, wav, webm…
              </p>
            </div>

            <button
              type="submit"
              class="mt-2 w-full rounded-2xl bg-[var(--app-accent)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-[0_12px_30px_rgba(194,65,12,0.25)] transition hover:bg-[var(--app-accent-strong)] disabled:opacity-60"
              :disabled="audioLoading || !audioForm.file"
            >
              {{ audioLoading ? 'Uploading...' : 'Create from audio' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

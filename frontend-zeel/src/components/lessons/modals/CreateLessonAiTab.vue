<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { createLessonFromAi } from '@/api/lessonApi'

const props = defineProps<{
  workspaceId: number
  disabled?: boolean
  resetToken?: number
}>()

const emit = defineEmits<{
  created: [lessonId: number]
  loading: [value: boolean]
  error: [message: string]
}>()

const form = reactive({
  topic: '',
  goal: '',
  level: '',
  length: 'medium' as 'short' | 'medium' | 'long',
  keywords: '',
  title_hint: '',
  include_dialogue: true,
  include_key_phrases: true,
  include_quick_questions: true,
})

const isBusy = ref(false)

const reset = () => {
  form.topic = ''
  form.goal = ''
  form.level = ''
  form.length = 'medium'
  form.keywords = ''
  form.title_hint = ''
  form.include_dialogue = true
  form.include_key_phrases = true
  form.include_quick_questions = true
  emit('error', '')
}

watch(
  () => props.resetToken,
  () => reset(),
)

const parseCommaList = (value: string, max = 12) =>
  value
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean)
    .slice(0, max)

const canSubmit = computed(() => {
  if (props.disabled) return false
  if (isBusy.value) return false
  return form.topic.trim().length >= 3
})

const toggle = (key: 'include_dialogue' | 'include_key_phrases' | 'include_quick_questions') => {
  form[key] = !form[key]
}

const submit = async () => {
  if (!canSubmit.value) return
  if (!props.workspaceId) {
    emit('error', 'Workspace is missing.')
    return
  }

  emit('error', '')
  isBusy.value = true
  emit('loading', true)

  try {
    const lesson = await createLessonFromAi(props.workspaceId, {
      topic: form.topic.trim(),
      goal: form.goal.trim() || undefined,
      level: form.level || undefined,
      length: form.length,
      keywords: parseCommaList(form.keywords, 12),
      title_hint: form.title_hint.trim() || undefined,
      include_dialogue: form.include_dialogue,
      include_key_phrases: form.include_key_phrases,
      include_quick_questions: form.include_quick_questions,
    })

    emit('created', lesson.id)
  } catch (e) {
    emit('error', 'Failed to generate AI lesson')
    console.error(e)
  } finally {
    isBusy.value = false
    emit('loading', false)
  }
}
</script>

<template>
  <form @submit.prevent="submit" class="space-y-3">
    <input v-model="form.topic" type="text" required placeholder="Topic" class="zee-input font-medium" />

    <div class="grid grid-cols-2 gap-2">
      <select v-model="form.length" class="zee-input cursor-pointer appearance-none">
        <option value="short">Short</option>
        <option value="medium">Medium</option>
        <option value="long">Long</option>
      </select>

      <select v-model="form.level" class="zee-input cursor-pointer appearance-none">
        <option value="">Any level</option>
        <option value="A1">A1</option>
        <option value="A2">A2</option>
        <option value="B1">B1</option>
        <option value="B2">B2</option>
        <option value="C1">C1</option>
        <option value="C2">C2</option>
      </select>
    </div>

    <input v-model="form.title_hint" type="text" placeholder="Title (optional)" class="zee-input" />
    <input v-model="form.keywords" type="text" placeholder="Keywords (comma)" class="zee-input" />
    <input v-model="form.goal" type="text" placeholder="Goal (optional)" class="zee-input" />

    <div class="flex flex-wrap gap-2 pt-1">
      <button
        type="button"
        class="rounded-2xl border border-[var(--app-border)] px-3 py-2 text-xs font-semibold transition"
        :class="form.include_dialogue
          ? 'bg-[var(--app-surface-elevated)] text-[var(--app-text)] ring-1 ring-black/5 dark:ring-white/5'
          : 'bg-[var(--app-panel-muted)] text-[var(--app-text-muted)] hover:text-[var(--app-text)]'"
        @click="toggle('include_dialogue')"
        :disabled="isBusy"
      >
        Dialogue
      </button>

      <button
        type="button"
        class="rounded-2xl border border-[var(--app-border)] px-3 py-2 text-xs font-semibold transition"
        :class="form.include_key_phrases
          ? 'bg-[var(--app-surface-elevated)] text-[var(--app-text)] ring-1 ring-black/5 dark:ring-white/5'
          : 'bg-[var(--app-panel-muted)] text-[var(--app-text-muted)] hover:text-[var(--app-text)]'"
        @click="toggle('include_key_phrases')"
        :disabled="isBusy"
      >
        Key phrases
      </button>

      <button
        type="button"
        class="rounded-2xl border border-[var(--app-border)] px-3 py-2 text-xs font-semibold transition"
        :class="form.include_quick_questions
          ? 'bg-[var(--app-surface-elevated)] text-[var(--app-text)] ring-1 ring-black/5 dark:ring-white/5'
          : 'bg-[var(--app-panel-muted)] text-[var(--app-text-muted)] hover:text-[var(--app-text)]'"
        @click="toggle('include_quick_questions')"
        :disabled="isBusy"
      >
        Questions
      </button>
    </div>

    <button type="submit" class="zee-btn w-full py-3.5 flex items-center justify-center gap-2" :disabled="!canSubmit">
      <Icon v-if="isBusy" icon="svg-spinners:90-ring-with-bg" class="h-5 w-5" />
      <template v-else>
        <Icon icon="solar:magic-stick-3-bold-duotone" class="h-5 w-5" />
        <span>Generate</span>
      </template>
    </button>
  </form>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Workspace } from '@/types/workspace'
import { useWorkspaceLanguageSetup } from '@/composables/useWorkspaceLanguageSetup'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
  created: [workspace: Workspace]
}>()

const {
  languages,
  loadingLanguages,
  languagesError,
  targetLanguageCode,
  supportLanguageCode,
  targetLanguage,
  supportLanguage,
  swapLanguages,
  createWorkspaceWithLanguages,
} = useWorkspaceLanguageSetup()

const workspaceName = ref('')
const workspaceDescription = ref('')
const creating = ref(false)

const canSubmit = computed(
  () =>
    !!targetLanguageCode.value &&
    !!supportLanguageCode.value &&
    !creating.value &&
    !loadingLanguages.value,
)

const close = () => {
  if (creating.value) return
  emit('close')
}

const submit = async () => {
  if (!canSubmit.value) return

  if (!workspaceName.value.trim()) {
    workspaceName.value = 'My workspace'
  }

  creating.value = true
  try {
    const workspace = await createWorkspaceWithLanguages({
      name: workspaceName.value.trim(),
      description: workspaceDescription.value.trim() || null,
    })

    workspaceName.value = ''
    workspaceDescription.value = ''
    emit('created', workspace)
  } catch (error) {
    console.error(error)
  } finally {
    creating.value = false
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen && languages.value.length && !targetLanguageCode.value && !supportLanguageCode.value) {
      const fallback = languages.value.find((l) => l.code === 'en') ?? languages.value[0]
      targetLanguageCode.value = fallback.code
      supportLanguageCode.value = fallback.code
    }
  },
)
</script>

<template>
  <transition name="fade">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 px-4 py-8">
      <div class="relative w-full max-w-4xl overflow-hidden rounded-[32px] border border-[var(--app-border)] bg-[var(--app-surface)]/95 shadow-[0_40px_120px_rgba(15,23,42,0.55)] backdrop-blur-2xl transition dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/95">
        <button
          type="button"
          class="absolute right-5 top-5 inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] text-slate-500 transition hover:scale-95 hover:text-slate-900 dark:border-[var(--app-border-dark)] dark:text-slate-300 dark:hover:text-white"
          @click="close"
        >
          <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="grid gap-0 md:grid-cols-[0.85fr_1.15fr]">
          <div class="hidden flex-col justify-between bg-gradient-to-br from-[var(--app-accent)] via-orange-500 to-rose-500 px-8 py-10 text-white md:flex">
            <div class="space-y-5">
              <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.4em]">Studio</span>
              <h2 class="text-3xl font-semibold leading-tight">Launch a focused language studio</h2>
              <p class="text-sm text-white/80">
                Curate your target language and the supporting language. Every workspace stays minimal, distraction-free, and ready for AI-powered coaching.
              </p>
            </div>
            <div class="space-y-4 rounded-[28px] border border-white/30 bg-white/10 p-5">
              <p class="text-sm font-semibold uppercase tracking-[0.4em] text-white/70">Current pairing</p>
              <div class="space-y-1 text-lg font-semibold">
                <p>{{ targetLanguage?.native ?? 'Target' }} → {{ supportLanguage?.native ?? 'Support' }}</p>
                <p class="text-sm text-white/70">
                  {{ targetLanguage?.label ?? 'Select language' }} with guidance in
                  {{ supportLanguage?.label ?? 'support' }}.
                </p>
              </div>
              <div class="flex gap-2 text-[11px] uppercase tracking-[0.3em] text-white/70">
                <span class="rounded-full border border-white/30 px-3 py-0.5">Focus</span>
                <span class="rounded-full border border-white/30 px-3 py-0.5">Minimal</span>
              </div>
            </div>
          </div>

          <div class="space-y-8 bg-[var(--app-surface-elevated)]/95 px-6 py-8 text-slate-900 dark:bg-[var(--app-surface-dark-elevated)]/95 dark:text-slate-50 md:px-10">
            <div class="space-y-2">
              <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-slate-400 dark:text-slate-500">Workspace</p>
              <h3 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">Create your flow</h3>
              <p class="text-sm text-slate-500 dark:text-slate-400">
                Pick the languages you learn and the language you want explanations in. Everything else adapts automatically.
              </p>
            </div>

            <div class="space-y-6">
              <div class="rounded-[24px] border border-[var(--app-border)] bg-white/90 p-5 shadow-sm shadow-slate-900/5 dark:border-[var(--app-border-dark)] dark:bg-white/5">
                <div class="flex items-center justify-between text-xs font-medium text-slate-500 dark:text-slate-300">
                  <span>Language pair</span>
                  <span v-if="targetLanguage && supportLanguage" class="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-400">
                    {{ targetLanguage.code }} · {{ supportLanguage.code }}
                  </span>
                </div>
                <div class="mt-4 space-y-4">
                  <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-200">Learning</label>
                    <select
                      v-model="targetLanguageCode"
                      class="mt-1 w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-slate-50 dark:placeholder:text-slate-500 dark:focus:border-[var(--app-accent-secondary)] dark:focus:ring-[var(--app-accent-secondary)]/20"
                      :disabled="loadingLanguages"
                    >
                      <option v-for="lang in languages" :key="lang.code" :value="lang.code">
                        {{ lang.native }} · {{ lang.label }}
                      </option>
                    </select>
                  </div>
                  <div class="flex items-center justify-center">
                    <button
                      type="button"
                      class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)] text-slate-500 transition hover:border-[var(--app-accent)] hover:text-[var(--app-accent)] disabled:opacity-40 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-100"
                      :disabled="loadingLanguages"
                      @click="swapLanguages"
                    >
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h13M15 7l2-2m-2 2 2 2M20 17H7m2-2-2 2m2-2-2-2" />
                      </svg>
                    </button>
                  </div>
                  <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-200">Explanations in</label>
                    <select
                      v-model="supportLanguageCode"
                      class="mt-1 w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[var(--app-accent-secondary)] focus:ring-2 focus:ring-[var(--app-accent-secondary-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-slate-50 dark:placeholder:text-slate-500 dark:focus:border-[var(--app-accent-secondary)] dark:focus:ring-[var(--app-accent-secondary)]/20"
                      :disabled="loadingLanguages"
                    >
                      <option v-for="lang in languages" :key="lang.code" :value="lang.code">
                        {{ lang.native }} · {{ lang.label }}
                      </option>
                    </select>
                  </div>
                </div>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                  Training in
                  <span class="font-semibold text-slate-900 dark:text-slate-100">{{ targetLanguage?.native ?? 'target language' }}</span>
                  with guidance in
                  <span class="font-semibold text-slate-900 dark:text-slate-100">{{ supportLanguage?.native ?? 'support language' }}</span>.
                </p>
              </div>

              <div class="rounded-[24px] border border-[var(--app-border)] bg-white/90 p-5 shadow-sm shadow-slate-900/5 dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)]/80">
                <div>
                  <label class="text-xs font-semibold text-slate-500 dark:text-slate-300">Workspace name</label>
                  <input
                    v-model="workspaceName"
                    type="text"
                    class="mt-1 w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[var(--app-accent)] focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-50 dark:placeholder:text-slate-500 dark:focus:border-[var(--app-accent-secondary)] dark:focus:ring-[var(--app-accent-secondary)]/20"
                    placeholder="English studio with Farsi support"
                  />
                </div>
                <div class="mt-4">
                  <label class="text-xs font-semibold text-slate-500 dark:text-slate-300">Short description</label>
                  <textarea
                    v-model="workspaceDescription"
                    rows="3"
                    class="mt-1 w-full resize-none rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[var(--app-accent-secondary)] focus:ring-2 focus:ring-[var(--app-accent-secondary-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark-elevated)] dark:text-slate-50 dark:placeholder:text-slate-500 dark:focus:border-[var(--app-accent)] dark:focus:ring-[var(--app-accent)]/20"
                    placeholder="Conversational drills, 15 min a day."
                  ></textarea>
                </div>
                <div class="mt-4 rounded-2xl bg-[var(--app-surface)]/60 px-4 py-3 text-xs text-slate-500 dark:bg-[var(--app-surface-dark)]/70 dark:text-slate-300">
                  Pairing can be changed anytime from workspace settings. Your prompts will adjust instantly.
                </div>
              </div>
            </div>

            <p v-if="languagesError" class="text-sm text-rose-500">
              {{ languagesError }}
            </p>

            <div class="flex flex-col gap-3 border-t border-[var(--app-border)] pt-4 text-sm text-slate-500 dark:border-[var(--app-border-dark)] dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
              <span>Minimal workspace. Instant AI calibration.</span>
              <div class="flex flex-col gap-2 sm:flex-row">
                <button
                  type="button"
                  class="inline-flex w-full items-center justify-center rounded-full border border-[var(--app-border)] px-6 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-[var(--app-surface)] hover:text-slate-900 dark:border-[var(--app-border-dark)] dark:text-slate-200 dark:hover:bg-[var(--app-surface-dark)]"
                  @click="close"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="inline-flex w-full items-center justify-center rounded-full bg-[var(--app-accent)] px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[var(--app-accent)]/35 transition hover:bg-[var(--app-accent-strong)] disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="!canSubmit"
                  @click="submit"
                >
                  <span v-if="!creating">Create workspace</span>
                  <span v-else>Creating…</span>
                </button>
              </div>
            </div>
          </div>
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

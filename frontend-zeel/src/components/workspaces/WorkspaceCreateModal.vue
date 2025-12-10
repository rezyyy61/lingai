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

// Auto-select a sensible default when modal is open and languages are loaded
watch(
  [() => props.open, () => languages.value],
  ([isOpen, langs]) => {
    if (!isOpen || !langs.length) return
    if (!targetLanguageCode.value && !supportLanguageCode.value) {
      const fallback = langs.find((l) => l.code === 'en') ?? langs[0]
      if (!fallback) return
      targetLanguageCode.value = fallback.code
      supportLanguageCode.value = fallback.code
    }
  },
  { immediate: true },
)
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 px-3 py-4 sm:px-4 sm:py-6"
    >
      <!-- Backdrop click area -->
      <div class="absolute inset-0" @click="close" />

      <!-- Panel -->
      <div
        class="relative z-10 flex w-full max-w-md flex-col overflow-hidden rounded-3xl border border-[color:var(--app-border)] bg-[color:var(--app-surface-elevated)] text-slate-900 shadow-[0_22px_70px_rgba(15,23,42,0.60)] dark:border-[color:var(--app-border-dark)] dark:bg-[color:var(--app-surface-dark-elevated)] dark:text-slate-50 sm:max-w-lg"
      >

      <!-- Close button -->
        <button
          type="button"
          class="absolute right-3 top-3 inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100/70 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10"
          aria-label="Close"
          @click.stop="close"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>

        <!-- Content -->
        <div class="max-h-[calc(100vh-3.5rem)] overflow-y-auto px-4 pb-4 pt-5 sm:px-6 sm:pb-6 sm:pt-6">
          <!-- Header -->
          <header class="flex items-start gap-3">
            <div
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-[color:var(--app-accent-soft)] text-[color:var(--app-accent-strong)]"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M9 4.5h9.75M9 8.25h9.75M9 12h9.75M4.5 15.75h14.25M4.5 19.5H12"
                />
              </svg>
            </div>

            <div class="min-w-0 space-y-1">
              <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">
                <span>Workspace</span>
                <span
                  v-if="targetLanguage && supportLanguage"
                  class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-[2px] text-[9px] font-semibold tracking-[0.18em] text-slate-500 dark:bg-white/5 dark:text-slate-300"
                >
                  {{ targetLanguage.code }} · {{ supportLanguage.code }}
                </span>
              </div>

              <h3 class="truncate text-base font-semibold tracking-tight sm:text-lg">
                Create a new workspace
              </h3>

              <p class="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
                Choose your learning language and the language you want explanations in. You can adjust this later.
              </p>
            </div>
          </header>

          <!-- Language pair -->
          <section class="mt-5 space-y-3 sm:mt-6">
            <div
              class="flex items-center justify-between gap-3 rounded-2xl bg-slate-50 px-3 py-2.5 text-[11px] text-slate-600 dark:bg-white/5 dark:text-slate-200"
            >
              <div class="flex flex-col">
                <span class="text-[11px] font-semibold">Language pair</span>
                <span
                  v-if="targetLanguage && supportLanguage"
                  class="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-300"
                >
                  {{ targetLanguage.native }} → {{ supportLanguage.native }}
                </span>
                <span v-else class="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">
                  Select both languages to continue.
                </span>
              </div>
              <span
                v-if="loadingLanguages"
                class="text-[10px] font-medium text-slate-400 dark:text-slate-500"
              >
                Loading…
              </span>
            </div>

            <div class="space-y-3 text-sm">
              <!-- Learning language -->
              <div>
                <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-200 sm:text-xs">
                  Learning language
                </label>
                <select
                  v-model="targetLanguageCode"
                  class="mt-1 w-full rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-[color:var(--app-accent)] focus:ring-1 focus:ring-[color:var(--app-accent-soft)] dark:border-[color:var(--app-border-dark)] dark:bg-[color:var(--app-surface-dark)] dark:text-slate-50"
                  :disabled="loadingLanguages"
                >
                  <option v-for="lang in languages" :key="lang.code" :value="lang.code">
                    {{ lang.native }} · {{ lang.label }}
                  </option>
                </select>
              </div>

              <!-- Swap button -->
              <div class="flex items-center justify-center">
                <button
                  type="button"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[color:var(--app-border)] text-slate-500 transition hover:border-[color:var(--app-accent)] hover:text-[color:var(--app-accent)] disabled:opacity-40 dark:border-[color:var(--app-border-dark)] dark:text-slate-100"
                  :disabled="loadingLanguages"
                  @click="swapLanguages"
                >
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h12M14 7l2-2m-2 2 2 2M20 17H8m2-2-2 2m2-2-2-2" />
                  </svg>
                </button>
              </div>

              <!-- Support language -->
              <div>
                <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-200 sm:text-xs">
                  Explanations in
                </label>
                <select
                  v-model="supportLanguageCode"
                  class="mt-1 w-full rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-[color:var(--app-accent-secondary)] focus:ring-1 focus:ring-[color:var(--app-accent-secondary-soft)] dark:border-[color:var(--app-border-dark)] dark:bg-[color:var(--app-surface-dark)] dark:text-slate-50"
                  :disabled="loadingLanguages"
                >
                  <option v-for="lang in languages" :key="lang.code" :value="lang.code">
                    {{ lang.native }} · {{ lang.label }}
                  </option>
                </select>
              </div>
            </div>
          </section>

          <!-- Workspace info -->
          <section class="mt-6 space-y-3 text-sm">
            <div class="rounded-2xl bg-slate-50 px-3.5 py-3 dark:bg-white/5">
              <div>
                <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-200 sm:text-xs">
                  Workspace name
                </label>
                <input
                  v-model="workspaceName"
                  type="text"
                  class="mt-1 w-full rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[color:var(--app-accent)] focus:ring-1 focus:ring-[color:var(--app-accent-soft)] dark:border-[color:var(--app-border-dark)] dark:bg-[color:var(--app-surface-dark)] dark:text-slate-50 dark:placeholder:text-slate-500"
                  placeholder="English studio with Farsi support"
                />
              </div>

              <div class="mt-3">
                <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-200 sm:text-xs">
                  Short description
                </label>
                <textarea
                  v-model="workspaceDescription"
                  rows="3"
                  class="mt-1 w-full resize-none rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[color:var(--app-accent-secondary)] focus:ring-1 focus:ring-[color:var(--app-accent-secondary-soft)] dark:border-[color:var(--app-border-dark)] dark:bg-[color:var(--app-surface-dark)] dark:text-slate-50 dark:placeholder:text-slate-500"
                  placeholder="Optional"
                ></textarea>
              </div>
            </div>
          </section>

          <p v-if="languagesError" class="mt-3 text-xs text-rose-500 sm:text-sm">
            {{ languagesError }}
          </p>

          <!-- Footer -->
          <footer
            class="mt-5 flex flex-col gap-2 border-t border-[color:var(--app-border)] pt-4 text-xs text-slate-500 dark:border-[color:var(--app-border-dark)] dark:text-slate-400 sm:mt-6 sm:flex-row sm:items-center sm:justify-between sm:text-[13px]"
          >
            <span class="hidden sm:inline">You can always edit this in workspace settings.</span>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
              <button
                type="button"
                class="inline-flex w-full items-center justify-center rounded-full border border-[color:var(--app-border)] px-5 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-[color:var(--app-surface)] hover:text-slate-900 dark:border-[color:var(--app-border-dark)] dark:text-slate-200 dark:hover:bg-[color:var(--app-surface-dark)]"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                class="inline-flex w-full items-center justify-center rounded-full bg-[color:var(--app-accent)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[color:var(--app-accent-strong)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!canSubmit"
                @click="submit"
              >
                <span v-if="!creating">Create workspace</span>
                <span v-else>Creating…</span>
              </button>
            </div>
          </footer>
        </div>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease-out;
}
.fade-leave-active {
  transition: opacity 0.14s ease-in;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

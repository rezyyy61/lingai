// src/composables/useWorkspaceLanguageSetup.ts
import { computed, onMounted, ref } from 'vue'
import { fetchLanguages } from '@/api/languageApi'
import type { Language } from '@/types/language'
import { createWorkspace } from '@/api/workspaceApi'
import type { WorkspacePayload } from '@/api/workspaceApi'

export const useWorkspaceLanguageSetup = () => {
  const languages = ref<Language[]>([])
  const loadingLanguages = ref(false)
  const languagesError = ref('')

  const targetLanguageCode = ref<string | null>(null)
  const supportLanguageCode = ref<string | null>(null)

  const loadLanguages = async () => {
    loadingLanguages.value = true
    languagesError.value = ''
    try {
      const { data } = await fetchLanguages()
      const list = data ?? []
      languages.value = list

      if (list.length) {
        const defaultTarget =
          list.find((l) => l.code === 'en')?.code ??
          list[0]?.code ??
          'en'

        targetLanguageCode.value = defaultTarget
        supportLanguageCode.value = defaultTarget
      }
    } catch (error) {
      languagesError.value = 'Unable to load languages'
      console.error(error)
    } finally {
      loadingLanguages.value = false
    }
  }

  const targetLanguage = computed(() =>
    languages.value.find((l) => l.code === targetLanguageCode.value) || null,
  )

  const supportLanguage = computed(() =>
    languages.value.find((l) => l.code === supportLanguageCode.value) || null,
  )

  const swapLanguages = () => {
    const temp = targetLanguageCode.value
    targetLanguageCode.value = supportLanguageCode.value
    supportLanguageCode.value = temp
  }

  const createWorkspaceWithLanguages = async (
    payload: Omit<WorkspacePayload, 'target_language' | 'support_language'>,
  ) => {
    const target = targetLanguageCode.value
    const support = supportLanguageCode.value

    if (!target || !support) {
      throw new Error('Languages not selected')
    }

    const { data } = await createWorkspace({
      ...payload,
      target_language: target,
      support_language: support,
    })

    return data
  }

  onMounted(() => {
    loadLanguages()
  })

  return {
    languages,
    loadingLanguages,
    languagesError,
    targetLanguageCode,
    supportLanguageCode,
    targetLanguage,
    supportLanguage,
    swapLanguages,
    createWorkspaceWithLanguages,
  }
}

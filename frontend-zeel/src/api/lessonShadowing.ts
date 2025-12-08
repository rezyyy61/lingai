import apiClient from '@/services/http'
import type { LessonSentenceDto } from '@/types/lesson'

export async function fetchLessonSentences(
  lessonId: number,
  params?: { q?: string; source?: 'original' | 'generated' }
): Promise<LessonSentenceDto[]> {
  const response = await apiClient.get(`/lessons/${lessonId}/sentences`, {
    params,
  })
  const payload = response.data?.data ?? response.data
  return (payload ?? []) as LessonSentenceDto[]
}

export async function fetchLessonSentenceTts(sentenceId: number): Promise<string> {
  const response = await apiClient.get(`/lesson-sentences/${sentenceId}/tts`)
  return response.data.audio_url as string
}

export interface GenerateShadowingPayload {
  custom_prompt?: string
  replace_existing?: boolean
}

export async function generateLessonShadowingSentences(
  lessonId: number,
  payload: GenerateShadowingPayload,
): Promise<void> {
  await apiClient.post(`/lessons/${lessonId}/sentences/generate`, payload)
}

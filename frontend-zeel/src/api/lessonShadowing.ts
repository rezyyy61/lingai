import apiClient from '@/services/http'
import type { LessonSentenceDto, LessonSentenceInput } from '@/types/lesson'

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

export async function importLessonSentences(
  lessonId: number,
  payload: {
    replace_existing?: boolean
    sentences: LessonSentenceInput[]
  },
): Promise<LessonSentenceDto[]> {
  const response = await apiClient.post(`/lessons/${lessonId}/sentences/import`, payload)
  return (response.data?.sentences ?? []) as LessonSentenceDto[]
}

export async function updateLessonSentence(
  lessonId: number,
  sentenceId: number,
  payload: LessonSentenceInput,
): Promise<LessonSentenceDto> {
  const response = await apiClient.put(`/lessons/${lessonId}/sentences/${sentenceId}`, payload)
  return response.data as LessonSentenceDto
}

export async function deleteLessonSentence(
  lessonId: number,
  sentenceId: number,
): Promise<void> {
  await apiClient.delete(`/lessons/${lessonId}/sentences/${sentenceId}`)
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

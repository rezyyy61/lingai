import apiClient from '@/services/http'
import type { LessonWordDto } from '@/types/lesson'

export async function fetchLessonWords(lessonId: number): Promise<LessonWordDto[]> {
  const response = await apiClient.get(`/lessons/${lessonId}/words`)
  const payload = response.data?.data ?? response.data
  return (payload ?? []) as LessonWordDto[]
}

export async function fetchLessonWordTts(wordId: number): Promise<string> {
  const response = await apiClient.get(`/lesson-words/${wordId}/tts`)
  return response.data.audio_url as string
}

export interface GenerateFlashcardsPayload {
  level?: string
  domain?: string
  min_items?: number
  max_items?: number
  notes?: string
  inline_prompt?: string
  save_preset?: boolean
  replace_existing?: boolean
}

export async function generateLessonFlashcards(
  lessonId: number,
  payload: GenerateFlashcardsPayload,
): Promise<void> {
  await apiClient.post(`/lessons/${lessonId}/words/generate`, payload)
}

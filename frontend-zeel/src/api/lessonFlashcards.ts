import apiClient from '@/services/http'
import type { FlashcardReviewQueueResponse, LessonWordDto, LessonWordInput, LessonWordReviewDto } from '@/types/lesson'

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

export interface ImportLessonWordsPayload {
  replace_existing?: boolean
  words: LessonWordInput[]
}

export async function importLessonWords(
  lessonId: number,
  payload: ImportLessonWordsPayload,
): Promise<LessonWordDto[]> {
  const response = await apiClient.post(`/lessons/${lessonId}/words/import`, payload)
  return (response.data?.words ?? []) as LessonWordDto[]
}

export async function createLessonWord(
  lessonId: number,
  payload: LessonWordInput,
): Promise<LessonWordDto> {
  const response = await apiClient.post(`/lessons/${lessonId}/words`, payload)
  return response.data as LessonWordDto
}

export async function updateLessonWord(
  lessonId: number,
  wordId: number,
  payload: LessonWordInput,
): Promise<LessonWordDto> {
  const response = await apiClient.put(`/lessons/${lessonId}/words/${wordId}`, payload)
  return response.data as LessonWordDto
}

export async function deleteLessonWord(
  lessonId: number,
  wordId: number,
): Promise<void> {
  await apiClient.delete(`/lessons/${lessonId}/words/${wordId}`)
}

export async function fetchDueLessonFlashcards(
  lessonId: number,
  limit = 20,
): Promise<FlashcardReviewQueueResponse> {
  const response = await apiClient.get(`/lessons/${lessonId}/words/review`, {
    params: { limit },
  })

  return response.data as FlashcardReviewQueueResponse
}

export async function submitLessonFlashcardReview(
  lessonWordId: number,
  result: 'know' | 'dont_know',
): Promise<{
  status: string
  card: LessonWordDto
  review: LessonWordReviewDto
  remaining_due_count: number
}> {
  const response = await apiClient.post('/lesson-words/review', {
    lesson_word_id: lessonWordId,
    result,
  })

  return response.data as {
    status: string
    card: LessonWordDto
    review: LessonWordReviewDto
    remaining_due_count: number
  }
}

export async function resetLessonFlashcardReview(
  lessonId: number,
  limit = 20,
): Promise<FlashcardReviewQueueResponse> {
  const response = await apiClient.post(`/lessons/${lessonId}/words/review/reset`, {
    limit,
  })

  return response.data as FlashcardReviewQueueResponse
}

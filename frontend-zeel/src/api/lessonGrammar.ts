import apiClient from '@/services/http'

export interface GrammarExampleDto {
  sentence?: string | null
  text?: string | null
  translation?: string | null
  source?: string | null
}


export interface GrammarPracticeItemDto {
  prompt: string
  answer?: string | null
  explanation?: string | null
}

export interface LessonGrammarPointDto {
  id: number
  lesson_id: number
  key?: string | null

  title: string

  level?: string | null
  description?: string | null
  pattern?: string | null

  summary?: string | null
  explanation?: string | null
  tips?: string | null

  examples?: GrammarExampleDto[] | string | null

  practice_items?: GrammarPracticeItemDto[] | null

  meta?: unknown

  created_at?: string | null
  updated_at?: string | null
}

export interface LessonGrammarGeneratePayload {
  custom_prompt?: string | null
  replace_existing?: boolean
}

export interface LessonGrammarGenerateResponse {
  status: string
  message: string
}

export async function fetchLessonGrammarPoints(
  lessonId: number,
): Promise<LessonGrammarPointDto[]> {
  const { data } = await apiClient.get(`/lessons/${lessonId}/grammar`)
  return data
}

export async function fetchLessonGrammarPoint(
  lessonId: number,
  grammarPointId: number,
): Promise<LessonGrammarPointDto> {
  const { data } = await apiClient.get(`/lessons/${lessonId}/grammar/${grammarPointId}`)
  return data
}

export async function generateLessonGrammar(
  lessonId: number,
  payload: LessonGrammarGeneratePayload = {},
): Promise<LessonGrammarGenerateResponse> {
  const { data } = await apiClient.post(`/lessons/${lessonId}/grammar/generate`, payload)
  return data
}

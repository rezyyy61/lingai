import type { Lesson, LessonDetail, PaginatedLessons } from '@/types/lesson'
import http from '@/services/http'

export interface LessonQuery {
  q?: string
  level?: string
  resource_type?: string
  page?: number
  workspace_id?: number
}

export const fetchLessons = async (params: LessonQuery = {}) => {
  const { data } = await http.get<PaginatedLessons>('/lessons', { params })
  return data
}

export const fetchLesson = async (id: number) => {
  const { data } = await http.get<LessonDetail>(`/lessons/${id}`)
  return data
}

export const createLesson = async (
  workspaceId: number,
  payload: {
    title: string
    original_text: string
    level?: string
    tags?: string[]
  },
) => {
  const { data } = await http.post<Lesson>(`/workspaces/${workspaceId}/lessons`, payload)
  return data
}

export const createLessonFromAudio = async (
  workspaceId: number,
  payload: {
    file: File
    title?: string
    level?: string
    tags?: string[]
    language?: string
  },
) => {
  const formData = new FormData()
  formData.append('file', payload.file)
  if (payload.title) formData.append('title', payload.title)
  if (payload.level) formData.append('level', payload.level)
  if (payload.language) formData.append('language', payload.language)
  payload.tags?.forEach((tag) => formData.append('tags[]', tag))

  const { data } = await http.post<{ lesson: Lesson }>(
    `/workspaces/${workspaceId}/lessons/from-audio`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )

  return data.lesson
}

export const createLessonFromYoutube = async (
  workspaceId: number,
  payload: {
    youtube_url: string
    title?: string
    level?: string
    tags?: string[]
    language?: string
  },
) => {
  const { data } = await http.post<{ lesson: Lesson }>(
    `/workspaces/${workspaceId}/lessons/from-youtube`,
    payload,
  )
  return data.lesson
}

export const createLessonFromAi = async (
  workspaceId: number,
  payload: {
    topic: string
    goal?: string
    level?: string
    length?: 'short' | 'medium' | 'long'
    keywords?: string[]
    title_hint?: string
    include_dialogue?: boolean
    include_key_phrases?: boolean
    include_quick_questions?: boolean
  },
) => {
  const { data } = await http.post<Lesson>(`/workspaces/${workspaceId}/lessons/generate`, payload)
  return data
}

export const getLessonReadAloud = async (
  lessonId: number,
  params: { speed?: 'slow'|'normal'|'fast'; format?: 'mp3'|'wav' } = {},
) => {
  const { data } = await http.get(`/lessons/${lessonId}/read-aloud`, { params })
  return data
}

export const generateLessonReadAloud = async (
  lessonId: number,
  payload: {
    speed?: 'slow' | 'normal' | 'fast'
    format?: 'mp3' | 'wav'
    mode?: 'auto' | 'narration' | 'dialogue' | 'quote'
    voice_pair?: 'auto' | 'female_male' | 'female_female' | 'male_male'
  } = {},
) => {
  const { data } = await http.post<{
    parts: { index: number; url: string; chars: number }[]
    speed: string
    format: string
    mode: string
    voice_pair: string
    voice: string | null
    voices?: Record<string, string>
    locale: string
    base_url?: string | null
  }>(`/lessons/${lessonId}/read-aloud`, payload)

  return data
}

export const logout = async () => {
  await http.post('/auth/logout')
}

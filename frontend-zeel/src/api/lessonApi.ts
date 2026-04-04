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

export const generateLessonAnalysis = async (lessonId: number) => {
  await http.post(`/lessons/${lessonId}/analysis/generate`)
}

export const generateLessonAudioScript = async (lessonId: number) => {
  const { data } = await http.post<{ status: string; message?: string }>(`/lessons/${lessonId}/audio-script`)
  return data
}

export const generateLessonAudio = async (lessonId: number) => {
  const { data } = await http.post<{ status: string; message?: string }>(`/lessons/${lessonId}/audio`)
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
) => {
  const { data } = await http.get(`/lessons/${lessonId}/read-aloud`)
  return data
}

export const generateLessonReadAloud = async (
  lessonId: number,
) => {
  const { data } = await http.post<{
    status: string
    message?: string
  }>(`/lessons/${lessonId}/read-aloud`)

  return data
}

export const logout = async () => {
  await http.post('/auth/logout')
}

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
    {
      headers: { 'Content-Type': 'multipart/form-data' },
    },
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

export const logout = async () => {
  await http.post('/auth/logout')
}

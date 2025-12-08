import apiClient from '@/services/http'
import type { LessonExerciseDto, LessonExerciseAttemptResponse } from '@/types/lesson'

export async function fetchLessonExercises(
  lessonId: number,
  params?: { skill?: string; type?: string },
): Promise<LessonExerciseDto[]> {
  const response = await apiClient.get(`/lessons/${lessonId}/exercises`, {
    params,
  })
  const payload = response.data?.data ?? response.data
  return (payload ?? []) as LessonExerciseDto[]
}

export async function attemptLessonExercise(
  exerciseId: number,
  optionId: number,
): Promise<LessonExerciseAttemptResponse> {
  const response = await apiClient.post(`/lesson-exercises/${exerciseId}/attempt`, {
    selected_option_id: optionId,
  })
  return response.data as LessonExerciseAttemptResponse
}

export interface GenerateExercisesPayload {
  custom_prompt?: string
  replace_existing?: boolean
}

export async function generateLessonExercises(
  lessonId: number,
  payload: GenerateExercisesPayload,
): Promise<void> {
  await apiClient.post(`/lessons/${lessonId}/exercises/generate`, payload)
}

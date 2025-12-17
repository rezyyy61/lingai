import apiClient from '@/services/http'

export interface SpeakingFeedback {
  corrected: string
  notes: string[]
  score: number
  suggested_answer: string
}

export interface SpeakingSubmitResponse {
  spoken: string
  confidence: number | null
  feedback: SpeakingFeedback
  audio_url: string
}

export interface SpeakingSubmitPayload {
  audio: File
  target_language?: string
  prompt?: string
}

export async function submitSpeakingPractice(
  payload: SpeakingSubmitPayload,
): Promise<SpeakingSubmitResponse> {
  const formData = new FormData()
  formData.append('audio', payload.audio)
  if (payload.target_language) {
    formData.append('target_language', payload.target_language)
  }
  if (payload.prompt) {
    formData.append('prompt', payload.prompt)
  }

  const response = await apiClient.post('/speaking/submit', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return response.data as SpeakingSubmitResponse
}


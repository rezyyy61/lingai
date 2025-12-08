import apiClient from '@/services/http'
import type { Language } from '@/types/language'

export const fetchLanguages = () => {
  return apiClient.get<Language[]>('/languages')
}

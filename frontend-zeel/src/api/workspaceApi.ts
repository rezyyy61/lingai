import apiClient from '@/services/http'
import type { Workspace } from '@/types/workspace'

export interface WorkspacePayload {
  name: string
  description?: string | null
  target_language?: string | null
  support_language?: string | null
}

export const fetchWorkspaces = () => {
  return apiClient.get<Workspace[]>('/workspaces')
}

export const createWorkspace = (payload: WorkspacePayload) => {
  return apiClient.post<Workspace>('/workspaces', payload)
}

export const fetchWorkspace = (id: number | string) => {
  return apiClient.get<Workspace>(`/workspaces/${id}`)
}

export interface Workspace {
  id: number
  owner_id: number
  name: string
  slug: string
  target_language: string
  support_language: string
  description?: string | null
  settings?: Record<string, unknown> | null
  created_at?: string | null
  updated_at?: string | null
}

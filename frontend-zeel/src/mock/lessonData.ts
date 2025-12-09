export type LessonResourceType = 'text' | 'video' | 'audio' | 'youtube'

export interface Lesson {
  id: number
  title: string
  resourceType: LessonResourceType
  shortDescription?: string
  tags?: string[]
  level?: string | null
  createdAt: string
  originalText?: string
  videoUrl?: string
}


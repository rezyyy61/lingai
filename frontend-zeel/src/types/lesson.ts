export type LessonResourceType = 'text' | 'video' | 'audio' | 'youtube'
export type LessonStatus = 'draft' | 'processing' | 'ready'
export type LessonLevel = 'A2' | 'B1' | 'B2' | 'C1' | null


export interface Lesson {
  id: number
  title: string
  resource_type: LessonResourceType
  short_description?: string | null
  tags?: string[] | null
  level?: LessonLevel
  status?: LessonStatus
  created_at: string
  original_text?: string | null
}

export interface LessonWord {
  id: number
  term: string
  phonetic?: string | null
  part_of_speech?: string | null
  meaning?: string | null
  example_sentence?: string | null
}

export interface LessonSentence {
  id: number
  text: string
  order: number
}

export interface LessonWordDto {
  id: number
  lesson_id: number
  term: string
  meaning: string
  translation: string
  example_sentence?: string | null
  phonetic?: string | null
  part_of_speech?: string | null
  meta?: unknown
}

export interface LessonFlashcard {
  id: number
  term: string
  meaning: string
  translation: string
  exampleSentence?: string | null
  phonetic?: string | null
  partOfSpeech?: string | null
}

export interface LessonSentenceDto {
  id: number
  lesson_id: number
  order_index: number
  text: string
  translation?: string | null
  source: 'original' | 'generated'
  start_time?: number | null
  end_time?: number | null
  meta?: unknown
  created_at?: string | null
  updated_at?: string | null
}

export interface LessonShadowSentence {
  id: number
  lessonId: number
  orderIndex: number
  text: string
  translation?: string | null
  source: 'original' | 'generated'
  startTime?: number | null
  endTime?: number | null
}


export interface LessonExerciseOptionDto {
  id: number
  lesson_exercise_id: number
  text: string
  is_correct?: boolean | null
  meta?: unknown
}

export interface LessonExerciseDto {
  id: number
  lesson_id: number
  lesson_sentence_id?: number | null
  type: string
  skill?: string | null
  question_prompt: string
  instructions?: string | null
  solution_explanation?: string | null
  meta?: unknown
  options?: LessonExerciseOptionDto[]
}

export interface LessonExerciseOption {
  id: number
  text: string
  isCorrect: boolean
}

export interface LessonExercise {
  id: number
  lessonId: number
  sentenceId?: number | null
  type: string
  skill?: string | null
  questionPrompt: string
  instructions?: string | null
  solutionExplanation?: string | null
  options: LessonExerciseOption[]
}

export interface LessonExerciseAttemptResponse {
  is_correct: boolean
}

export interface LessonAnalysisMeta {
  language_direction?: 'rtl' | 'ltr' | null
  [key: string]: unknown
}

export interface LessonDetail extends Lesson {
  words: LessonWord[]
  sentences: LessonSentence[]
  exercises: LessonExercise[]
  analysis_overview?: string | null
  analysis_grammar?: string | null
  analysis_vocabulary?: string | null
  analysis_study_tips?: string | null
  analysis_meta?: LessonAnalysisMeta | null
}

export interface PaginatedLessons {
  data: Lesson[]
  meta?: {
    current_page: number
    last_page: number
    total: number
  }
}

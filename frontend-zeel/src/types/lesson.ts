export type LessonResourceType = 'text' | 'text_ai' | 'video' | 'audio' | 'youtube'
export type LessonStatus = 'draft' | 'processing' | 'ready' | 'failed' | 'generating'
export type LessonLevel = 'A1' | 'A2' | 'B1' | 'B2' | 'C1' | 'C2' | null

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
  language?: string | null
  audio_path?: string | null
  audio_url?: string | null
  analysis_meta?: LessonAnalysisMeta | null
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
  meaning?: string | null
  translation?: string | null
  example_sentence?: string | null
  phonetic?: string | null
  part_of_speech?: string | null
  meta?: unknown
  review?: LessonWordReviewDto | null
}

export interface LessonFlashcard {
  id: number
  lessonId?: number
  term: string
  meaning: string
  translation: string
  exampleSentence?: string | null
  phonetic?: string | null
  partOfSpeech?: string | null
  review?: LessonWordReviewDto | null
}

export interface LessonWordInput {
  term: string
  lemma?: string | null
  phonetic?: string | null
  part_of_speech?: string | null
  meaning?: string | null
  example_sentence?: string | null
  translation?: string | null
  meta?: Record<string, unknown> | null
}

export interface LessonWordReviewDto {
  status: 'new' | 'learning' | 'reviewing' | 'mastered'
  next_review_at?: string | null
  last_reviewed_at?: string | null
  review_count: number
  success_count: number
  failure_count: number
  streak: number
  interval_seconds: number
  ease_factor: number
}

export interface FlashcardReviewQueueResponse {
  data: LessonWordDto[]
  meta: {
    due_count: number
    lesson_id: number
    limit: number
  }
}

export interface LessonSentenceDto {
  id: number
  lesson_id: number
  order_index: number
  text: string
  tts_audio_url?: string | null
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
  ttsAudioUrl?: string | null
  translation?: string | null
  source: 'original' | 'generated'
  startTime?: number | null
  endTime?: number | null
  meta?: Record<string, unknown> | null
}

export interface LessonSentenceInput {
  text: string
  translation?: string | null
  source?: 'original' | 'generated' | null
  start_time?: number | null
  end_time?: number | null
  meta?: Record<string, unknown> | null
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
  content_generation?: {
    flashcards?: LessonContentGenerationState | null
    shadowing?: LessonContentGenerationState | null
    grammar?: LessonContentGenerationState | null
    exercises?: LessonContentGenerationState | null
  } | null
  language_direction?: 'rtl' | 'ltr' | null
  audio_script?: {
    spoken_segments?:
      | {
          type: string
          speaker: string
          style: string
          pause_ms: number
          text: string
        }[]
      | null
    spoken_script?: string | null
    source_language_code?: string | null
    [key: string]: unknown
  } | null
  audio_generation?: {
    status?: 'processing' | 'ready' | 'failed' | null
    voice?: string | null
    voice_map?: Record<string, string> | null
    format?: string | null
    generated_at?: string | null
    [key: string]: unknown
  } | null
  [key: string]: unknown
}

export interface LessonContentGenerationState {
  status?: 'processing' | 'ready' | 'failed' | null
  message?: string | null
  started_at?: string | null
  updated_at?: string | null
  completed_at?: string | null
  failed_at?: string | null
  item_count?: number | null
  [key: string]: unknown
}

export interface LessonDetail extends Lesson {
  words: LessonWord[]
  sentences: LessonSentence[]
  exercises: LessonExercise[]
  language_code?: string | null
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

export interface LessonDialogueItem {
  speaker: string
  text: string
}

export interface LessonPack {
  title: string
  lesson_text: string
  dialogue: LessonDialogueItem[]
  key_phrases: string[]
  quick_questions: string[]
  tags: string[]
  meta?: any
}

export interface LessonDetail extends Lesson {
  lesson_pack?: LessonPack | null
}

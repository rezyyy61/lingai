<script setup lang="ts">
import LessonListItem from './LessonListItem.vue'
import type { Lesson } from '@/types/lesson'

const props = defineProps<{
  lessons: Lesson[]
  selectedId: number | null
}>()

const emit = defineEmits<{ select: [id: number] }>()

const handleSelect = (id: number) => emit('select', id)
</script>

<template>
  <div class="space-y-2">
    <LessonListItem
      v-for="lesson in lessons"
      :key="lesson.id"
      :lesson="lesson"
      :selected="lesson.id === selectedId"
      @select="handleSelect"
    />
    <p
      v-if="!lessons.length"
      class="rounded-2xl border border-dashed border-white/10 bg-white/5 p-6 text-center text-sm text-white/60"
    >
      No lessons found.
    </p>
  </div>
</template>

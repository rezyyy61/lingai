<script setup lang="ts">
import LessonListItem from './LessonListItem.vue'
import type { Lesson } from '@/types/lesson'

const props = defineProps<{
  lessons: Lesson[]
  collapsed?: boolean
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
      :collapsed="Boolean(props.collapsed)"
      :lesson="lesson"
      :selected="lesson.id === selectedId"
      @select="handleSelect"
    />
    <p
      v-if="!lessons.length"
      class="rounded-2xl border border-dashed border-white/10 bg-white/5 p-6 text-center text-sm text-white/60"
      :class="props.collapsed ? 'px-2 py-4 text-[11px]' : ''"
    >
      {{ props.collapsed ? 'No items' : 'No lessons found.' }}
    </p>
  </div>
</template>

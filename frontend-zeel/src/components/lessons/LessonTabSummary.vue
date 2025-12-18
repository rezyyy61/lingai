<script setup lang="ts">
import { Icon } from '@iconify/vue'
import LessonAnalysisPanel from '@/components/lessons/LessonAnalysisPanel.vue'
import type { LessonDetail } from '@/types/lesson'

const props = defineProps<{
  lesson: LessonDetail
}>()
</script>

<template>
  <section class="flex h-full w-full flex-col text-[var(--app-text)]">
    <div class="flex items-center gap-2 px-2 pb-2">
      <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[var(--app-panel-muted)] ring-1 ring-[var(--app-border)]/60">
        <Icon icon="solar:document-text-bold-duotone" class="h-4 w-4 text-[var(--app-accent)]" />
      </div>
      <div class="min-w-0 flex flex-col">
        <span class="text-[11px] font-semibold tracking-wide truncate">Summary</span>
        <span class="text-[10px] text-[var(--app-text-muted)] truncate">AI lesson insights</span>
      </div>
    </div>

    <div class="flex-1 min-h-0 overflow-hidden">
      <div class="h-full overflow-y-auto px-3 pb-3 custom-scrollbar">
        <LessonAnalysisPanel
          :lesson="props.lesson"
          compact
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: var(--app-border);
  border-radius: 999px;
}
</style>

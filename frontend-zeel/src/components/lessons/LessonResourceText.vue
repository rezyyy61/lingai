<script setup lang="ts">
import { computed, ref } from 'vue'
import type { LessonDetail } from '@/types/lesson'
import { Icon } from '@iconify/vue'

const props = defineProps<{ lesson: LessonDetail }>()

const isSerif = ref(false)
const fontSize = ref<'normal' | 'large'>('normal')

const toggleFont = () => {
  isSerif.value = !isSerif.value
}

const toggleSize = () => {
  fontSize.value = fontSize.value === 'normal' ? 'large' : 'normal'
}

const decodeHtml = (html: string) => {
  if (typeof document === 'undefined') return html
  const txt = document.createElement('textarea')
  txt.innerHTML = html
  return txt.value
}

const formatText = (text: string) => {
  // 1. Decode HTML entities
  let clean = decodeHtml(text)
  
  // 2. Style [metadata] like [music], [laughter]
  // We use a simple regex to wrap [...] in a span
  clean = clean.replace(/\[(.*?)\]/g, '<span class="text-[0.75em] font-medium tracking-wide uppercase text-[var(--app-text-muted)] opacity-70">[$1]</span>')
  
  return clean
}

const formattedBlocks = computed(() => {
  if (!props.lesson.original_text) return []
  
  const text = props.lesson.original_text
  
  // Check if it looks like a transcript with ">>" markers
  if (text.includes('>>')) {
     // Split by '>>', filter empty
     const parts = text.split('>>').map(p => p.trim()).filter(Boolean)
     
     return parts.map(part => ({
       isDialogue: true,
       content: formatText(part)
     }))
  }
  
  // Fallback to paragraph splitting for normal text
  return text.split('\n')
    .filter(p => p.trim().length > 0)
    .map(p => ({
      isDialogue: false,
      content: formatText(p)
    }))
})

const copyText = async () => {
   try {
     await navigator.clipboard.writeText(props.lesson.original_text || '')
   } catch (e) {
     console.error(e)
   }
}
</script>

<template>
  <div class="flex flex-col w-full h-full overflow-hidden bg-[var(--app-surface-elevated)] rounded-[24px] border border-[var(--app-border)] shadow-sm relative isolate dark:bg-[var(--app-surface-dark-elevated)]/50">
     <!-- Reader Toolbar (Sticky) -->
     <header class="sticky top-0 z-20 flex items-center justify-between px-5 py-3 border-b border-[var(--app-border)] bg-[var(--app-surface)]/80 backdrop-blur-md dark:bg-[#1a1a1c]/80 rounded-t-[24px]">
        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-[var(--app-text-muted)]">
           <Icon icon="solar:document-text-bold-duotone" class="h-4 w-4" />
           <span>Source Text</span>
        </div>
        
        <div class="flex items-center gap-1">
           <button 
             @click="toggleFont"
             class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
             :title="isSerif ? 'Switch to Sans' : 'Switch to Serif'"
           >
              <span class="text-xs font-bold">{{ isSerif ? 'Aa' : 'Tt' }}</span>
           </button>
           <button 
             @click="toggleSize"
             class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)]"
             title="Toggle Font Size"
           >
             <Icon icon="solar:text-field-linear" class="h-4 w-4" />
           </button>
           <div class="w-px h-4 bg-[var(--app-border)] mx-1"></div>
           <button 
             @click="copyText"
             class="h-8 w-8 flex items-center justify-center rounded-lg hover:bg-[var(--app-border)] transition text-[var(--app-text-muted)] hover:text-[var(--app-accent)]"
             title="Copy Text"
           >
             <Icon icon="solar:copy-bold-duotone" class="h-4 w-4" />
           </button>
        </div>
     </header>

     <!-- Content Area -->
     <div class="flex-1 overflow-y-auto custom-scrollbar p-6 md:p-8 min-h-0">
        <article 
          class="max-w-2xl mx-auto space-y-6"
          :class="[
            isSerif ? 'font-serif' : 'font-sans',
            fontSize === 'large' ? 'text-lg md:text-xl' : 'text-base md:text-lg'
          ]"
        >
           <div 
             v-for="(block, index) in formattedBlocks" 
             :key="index"
             class="leading-relaxed text-[var(--app-text)] dark:text-slate-100"
             :class="{ 'pl-4 border-l-2 border-[var(--app-accent)]/30': block.isDialogue }"
           >
             <span v-html="block.content"></span>
           </div>

           <div v-if="formattedBlocks.length === 0" class="text-center py-10 text-[var(--app-text-muted)]">
              No text content available for this lesson.
           </div>
        </article>
     </div>
  </div>
</template>

<style scoped>
/* Smooth font transitions */
article {
  transition: font-size 0.2s ease, font-family 0.2s ease;
}
</style>

<script setup lang="ts">
import { ref } from 'vue'

const notes = ref('')
</script>

<template>
  <section class="flex h-full flex-col text-[var(--app-text)]">
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 px-1 mb-4">
      <div class="space-y-0.5">
        <p class="text-xs font-semibold font-display tracking-wider uppercase text-[var(--app-accent)]">
          Notes
        </p>
        <p class="text-[11px] text-[var(--app-text-muted)]">
          Capture your thoughts
        </p>
      </div>
    </div>

    <!-- Main Content -->
    <div class="flex flex-1 flex-col">
       <div class="relative flex-1 flex flex-col rounded-[24px] border border-[var(--app-border)] bg-[var(--app-surface-elevated)] overflow-hidden shadow-sm transition-all focus-within:ring-2 focus-within:ring-[var(--app-accent)]/20 focus-within:border-[var(--app-accent)]">
          <textarea
            v-model="notes"
            class="flex-1 w-full bg-transparent px-5 py-5 text-sm leading-relaxed text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:outline-none resize-none"
            placeholder="Write down vocabulary, grammar points, or questions..."
          ></textarea>
          
          <div class="px-5 py-3 border-t border-[var(--app-border)] bg-[var(--app-surface)]/50 backdrop-blur-sm">
             <p class="flex items-center gap-1.5 text-[10px] text-[var(--app-text-muted)] font-medium">
                <Icon icon="solar:info-circle-bold" class="h-3 w-3" />
                Notes are available on this device only.
             </p>
          </div>
       </div>
    </div>
  </section>
</template>

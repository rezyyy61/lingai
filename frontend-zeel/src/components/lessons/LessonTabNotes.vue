<script setup lang="ts">
import { ref } from 'vue'

const notes = ref('')
</script>

<template>
  <div class="space-y-3">
    <textarea
      v-model="notes"
      rows="6"
      class="w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-elevated)] px-4 py-3 text-sm text-[var(--app-text)] placeholder:text-[var(--app-text-muted)] focus:border-[var(--app-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--app-accent-soft)] dark:border-[var(--app-border-dark)] dark:bg-[var(--app-surface-dark)] dark:text-[var(--app-surface)] dark:placeholder:text-[var(--app-border)]"
      placeholder="Capture personal notes"
    ></textarea>
    <p class="text-xs text-[var(--app-text-muted)] dark:text-[var(--app-border)]">Notes are stored locally only.</p>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import AppShell from './AppShell.vue'
import AppShellMobile from './AppShellMobile.vue'

const isMobile = ref(false)

const updateIsMobile = () => {
  if (typeof window === 'undefined') {
    isMobile.value = false
    return
  }
  isMobile.value = window.innerWidth < 1280
}

onMounted(() => {
  updateIsMobile()
  window.addEventListener('resize', updateIsMobile)
})

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updateIsMobile)
  }
})
</script>

<template>
  <AppShellMobile v-if="isMobile" />
  <AppShell v-else />
</template>


<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    id?: string
    label?: string
    modelValue: string
    type?: string
    name?: string
    placeholder?: string
    autocomplete?: string
    error?: string | string[] | null
    disabled?: boolean
  }>(),
  {
    type: 'text',
    placeholder: '',
    autocomplete: 'off',
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const errorMessage = computed(() => {
  if (!props.error) return ''
  return Array.isArray(props.error) ? props.error[0] : props.error
})

const inputId = computed(() => props.id ?? props.name ?? undefined)
</script>

<template>
  <label v-if="label" :for="inputId" class="mb-2 block text-xs font-medium uppercase tracking-wide text-zeel-muted">
    {{ label }}
  </label>
  <input
    :id="inputId"
    class="zee-input"
    :type="type"
    :name="name"
    :value="modelValue"
    :placeholder="placeholder"
    :autocomplete="autocomplete"
    :disabled="disabled"
    @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
  <p v-if="errorMessage" class="mt-1 text-sm text-rose-400">{{ errorMessage }}</p>
</template>

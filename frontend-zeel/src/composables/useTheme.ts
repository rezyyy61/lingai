import { ref, watchEffect } from 'vue'

export type ThemeMode = 'light' | 'dark'

const STORAGE_KEY = 'shadowing-theme'
const theme = ref<ThemeMode>('light')

const getPreferredTheme = (): ThemeMode => {
  if (typeof window === 'undefined') {
    return 'light'
  }

  const stored = window.localStorage.getItem(STORAGE_KEY)
  if (stored === 'light' || stored === 'dark') {
    return stored
  }

  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    return 'dark'
  }

  return 'light'
}

theme.value = getPreferredTheme()

watchEffect(() => {
  if (typeof document !== 'undefined') {
    document.documentElement.classList.toggle('dark', theme.value === 'dark')
  }

  if (typeof window !== 'undefined') {
    window.localStorage.setItem(STORAGE_KEY, theme.value)
  }
})

const setTheme = (mode: ThemeMode) => {
  theme.value = mode
}

const toggleTheme = () => {
  theme.value = theme.value === 'light' ? 'dark' : 'light'
}

export const useTheme = () => ({ theme, setTheme, toggleTheme })

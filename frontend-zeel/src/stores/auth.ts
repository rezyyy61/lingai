import { defineStore } from 'pinia'
import type { AxiosError } from 'axios'

import http, { applyAuthToken, getStoredToken } from '@/services/http'

export interface User {
  id: number
  name: string
  email: string
  email_verified_at?: string | null
}

interface LoginPayload {
  email: string
  password: string
}

interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

interface ForgotPasswordPayload {
  email: string
}

interface ResetPasswordPayload {
  email: string
  token: string
  password: string
  password_confirmation: string
}

interface LaravelValidationError {
  errors?: Record<string, string[]>
  message?: string
}

interface AuthResponse {
  user: User
  token: string
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    token: getStoredToken(),
    loading: false,
    errors: null as Record<string, string[]> | null,
    initialized: false,
  }),
  getters: {
    isAuthenticated: (state) => Boolean(state.token && state.user),
  },
  actions: {
    resetErrors() {
      this.errors = null
    },
    setToken(token: string | null) {
      this.token = token ?? ''
      applyAuthToken(token ?? null)
      if (!token) {
        this.user = null
      }
    },
    handleRequestError(error: unknown) {
      const axiosError = error as AxiosError<LaravelValidationError>
      if (axiosError.response?.status === 422) {
        this.errors = axiosError.response.data?.errors ?? null
      } else {
        this.errors = null
      }
    },
    async initialize() {
      if (this.initialized) {
        return
      }

      if (this.token) {
        applyAuthToken(this.token)
        await this.fetchUser()
        return
      }

      this.initialized = true
    },
    async fetchUser() {
      if (!this.token) {
        this.user = null
        this.initialized = true
        return
      }

      try {
        const { data } = await http.get<User>('/auth/me')
        this.user = data
      } catch (error) {
        console.error('Unable to fetch user', error)
        this.setToken(null)
      } finally {
        this.initialized = true
      }
    },
    async login(payload: LoginPayload) {
      this.loading = true
      this.errors = null
      try {
        const { data } = await http.post<AuthResponse>('/auth/login', payload)
        this.setToken(data.token)
        this.user = data.user
      } catch (error) {
        this.handleRequestError(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async register(payload: RegisterPayload) {
      this.loading = true
      this.errors = null
      try {
        const { data } = await http.post<AuthResponse>('/auth/register', payload)
        this.setToken(data.token)
        this.user = data.user
      } catch (error) {
        this.handleRequestError(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async logout() {
      if (!this.token) {
        this.setToken(null)
        return
      }

      try {
        await http.post('/auth/logout')
      } catch (error) {
        console.error('Logout failed', error)
      } finally {
        this.setToken(null)
      }
    },
    async forgotPassword(payload: ForgotPasswordPayload) {
      this.loading = true
      this.errors = null
      try {
        const { data } = await http.post<{ status: string }>('/auth/forgot-password', payload)
        return data
      } catch (error) {
        this.handleRequestError(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async resetPassword(payload: ResetPasswordPayload) {
      this.loading = true
      this.errors = null
      try {
        const { data } = await http.post<{ status: string }>('/auth/reset-password', payload)
        return data
      } catch (error) {
        this.handleRequestError(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async resendVerificationEmail() {
      this.loading = true
      this.errors = null
      try {
        const { data } = await http.post<{ status: string }>('/auth/email/verification-notification')
        return data
      } catch (error) {
        this.handleRequestError(error)
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})

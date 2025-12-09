import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'
const TOKEN_STORAGE_KEY = 'zeel_token'

export const http = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export const getStoredToken = () => {
  if (typeof window === 'undefined') return ''
  return localStorage.getItem(TOKEN_STORAGE_KEY) ?? ''
}

export const setStoredToken = (token: string | null) => {
  if (typeof window === 'undefined') return

  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }
}

export const applyAuthToken = (token: string | null) => {
  if (token) {
    http.defaults.headers.common.Authorization = `Bearer ${token}`
  } else {
    delete http.defaults.headers.common.Authorization
  }

  setStoredToken(token)
}

const existingToken = getStoredToken()
if (existingToken) {
  applyAuthToken(existingToken)
}

http.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && typeof window !== 'undefined') {
      applyAuthToken(null)
      window.dispatchEvent(new CustomEvent('zeel:unauthorized'))
    }

    return Promise.reject(error)
  },
)

export default http

import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

import AppShellResponsive from '@/components/layout/AppShellResponsive.vue'
import WorkspaceDashboard from '@/pages/WorkspaceDashboard.vue'
import LoginPage from '@/pages/auth/LoginPage.vue'
import RegisterPage from '@/pages/auth/RegisterPage.vue'
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage.vue'
import ResetPasswordPage from '@/pages/auth/ResetPasswordPage.vue'
import EmailVerificationNoticePage from '@/pages/auth/EmailVerificationNoticePage.vue'

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: LoginPage,
    meta: { guestOnly: true },
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterPage,
    meta: { guestOnly: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: ForgotPasswordPage,
    meta: { guestOnly: true },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: ResetPasswordPage,
    meta: { guestOnly: true },
  },
  {
    path: '/email-verify',
    name: 'email-verify',
    component: EmailVerificationNoticePage,
    meta: { requiresAuth: true },
  },
  {
    path: '/',
    name: 'dashboard',
    component: WorkspaceDashboard,
    meta: { requiresAuth: true },
  },
  {
    path: '/workspace/:id',
    name: 'workspace',
    component: AppShellResponsive,
    meta: { requiresAuth: true },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()

  if (!auth.initialized) {
    await auth.initialize()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next({
      name: 'login',
      query: { redirect: to.fullPath },
    })
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return next({ name: 'dashboard' })
  }

  return next()
})

export default router

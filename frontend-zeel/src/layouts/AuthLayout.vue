<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <div class="relative min-h-screen overflow-hidden bg-[var(--app-bg)] text-[var(--app-text)]">
    <div class="pointer-events-none absolute inset-0 opacity-80">
      <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(248,250,252,0.85),_transparent_55%),radial-gradient(circle_at_bottom,_rgba(248,212,181,0.85),_transparent_55%)]"></div>
      <div class="absolute -top-40 right-[-10%] h-72 w-72 rounded-full bg-orange-400/20 blur-[120px]"></div>
      <div class="absolute bottom-[-20%] left-[-5%] h-96 w-96 rounded-full bg-emerald-400/18 blur-[150px]"></div>
    </div>
    <div class="relative z-10 flex min-h-screen items-center px-5 py-12">
      <div class="mx-auto grid w-full max-w-6xl gap-12 md:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] md:items-center">
        <section class="space-y-6 text-center md:text-left">
          <p class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-1 text-[11px] font-semibold uppercase tracking-[0.5em] text-zeel-muted">
            lingAi studio
          </p>
          <div class="space-y-4">
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">
              Advance your fluency with <span class="text-zeel-primary">lingAi</span>'s credible AI mentor.
            </h1>
            <p class="text-lg text-zeel-muted">
              A calm, focused studio that blends certified pedagogy with adaptive AI guidance to refine pronunciation,
              grammar, and cultural nuance.
            </p>
          </div>
          <div class="grid gap-4 text-sm text-zeel-muted sm:grid-cols-2">
            <div class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)]/90 p-4 shadow-sm shadow-[var(--app-card-shadow)]">
              <p class="text-xs uppercase tracking-wider text-zeel-muted/80">Personalised syllabus</p>
              <p class="mt-2 text-2xl font-semibold text-[var(--app-text)]">+120</p>
              <p class="text-sm text-zeel-muted">Adaptive modules curated per learner</p>
            </div>
            <div class="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)]/90 p-4 shadow-sm shadow-[var(--app-card-shadow)]">
              <p class="text-xs uppercase tracking-wider text-zeel-muted/80">Live pronunciation coach</p>
              <p class="mt-2 text-2xl font-semibold text-[var(--app-text)]">24/7</p>
              <p class="text-sm text-zeel-muted">On‑demand AI conversation partner</p>
            </div>
          </div>
        </section>

        <div class="relative">
          <div class="absolute -top-6 right-6 flex items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-surface-elevated)]/80 px-3 py-1 text-xs text-zeel-muted shadow-sm">
            <span class="flex size-2 rounded-full bg-emerald-400"></span>
            Accredited AI mentor
          </div>
          <div class="zee-card p-6 md:p-8">
            <RouterView />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

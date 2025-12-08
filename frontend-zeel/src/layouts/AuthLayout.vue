<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <div class="relative min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(52,73,94,0.25),_transparent_45%),linear-gradient(135deg,#010814,#030f21,#010714)] text-white">
    <div class="pointer-events-none absolute inset-0 opacity-70">
      <div class="absolute inset-0 bg-[linear-gradient(120deg,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:220px_220px]"></div>
      <div class="absolute -top-52 right-0 h-72 w-72 rounded-full bg-zeel-primary/20 blur-[140px]"></div>
      <div class="absolute bottom-0 left-[-10%] h-96 w-96 rounded-full bg-zeel-accent/15 blur-[150px]"></div>
    </div>
    <div class="relative z-10 flex min-h-screen items-center px-5 py-12">
      <div class="mx-auto grid w-full max-w-6xl gap-12 md:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] md:items-center">
        <section class="space-y-6 text-center md:text-left">
          <p class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-1 text-[11px] font-semibold uppercase tracking-[0.5em] text-zeel-muted">
            AI LANGUAGE ACADEMY
          </p>
          <div class="space-y-4">
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">
              Advance your fluency with <span class="text-zeel-primary">ZeeL</span>'s credible AI mentor.
            </h1>
            <p class="text-lg text-zeel-muted">
              A formal yet friendly learning environment that blends certified pedagogy with adaptive AI
              guidance to refine pronunciation, grammar, and cultural nuance.
            </p>
          </div>
          <div class="grid gap-4 text-sm text-zeel-muted sm:grid-cols-2">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p class="text-xs uppercase tracking-wider text-zeel-muted/80">Personalised syllabus</p>
              <p class="mt-2 text-2xl font-semibold text-white">+120</p>
              <p>adaptive modules curated per learner</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p class="text-xs uppercase tracking-wider text-zeel-muted/80">Live pronunciation coach</p>
              <p class="mt-2 text-2xl font-semibold text-white">24/7</p>
              <p>on-demand AI conversation partner</p>
            </div>
          </div>
        </section>

        <div class="relative">
          <div class="absolute -top-6 right-6 flex items-center gap-2 rounded-full border border-white/10 bg-white/10 px-3 py-1 text-xs text-zeel-muted">
            <span class="flex size-2 rounded-full bg-emerald-400"></span>
            Accredited AI mentor
          </div>
          <div class="zee-card">
            <RouterView />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

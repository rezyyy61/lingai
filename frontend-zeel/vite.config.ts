import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import mkcert from 'vite-plugin-mkcert'

const host = '192.168.1.193'
const backend = 'http://127.0.0.1:8092'

export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
    mkcert({
      hosts: ['localhost', '127.0.0.1', host],
      savePath: './certs',
    }),
  ],

  server: {
    host: true,
    port: 5173,

    proxy: {
      '/api': {
        target: backend,
        changeOrigin: true,
        secure: false,
      },
      '/storage': {
        target: backend,
        changeOrigin: true,
        secure: false,
      },
    },
  },

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})

<script setup lang="ts">
import { watch } from 'vue'
import { X } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  open: boolean
  title: string
  subtitle?: string
  width?: string
}>(), {
  width: 'max-w-lg',
})

const emit = defineEmits<{
  close: []
}>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.open) emit('close')
}

watch(
  () => props.open,
  (v) => {
    if (v) document.addEventListener('keydown', onKey)
    else document.removeEventListener('keydown', onKey)
  },
)
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @click.self="emit('close')"
      >
        <div class="absolute inset-0 bg-ink-950/70 backdrop-blur-sm" />
        <div
          class="panel-elevated relative w-full animate-fadeUp overflow-hidden"
          :class="width"
        >
          <header class="flex items-start justify-between gap-4 border-b border-ink-700/70 px-6 py-4">
            <div>
              <h3 class="font-display text-lg font-semibold text-ink-50">{{ title }}</h3>
              <p v-if="subtitle" class="mt-0.5 text-sm text-ink-300">{{ subtitle }}</p>
            </div>
            <button
              class="rounded-md p-1 text-ink-300 transition-colors hover:bg-ink-700/60 hover:text-ink-100"
              @click="emit('close')"
            >
              <X :size="18" />
            </button>
          </header>
          <div class="px-6 py-5">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="border-t border-ink-700/70 bg-ink-900/40 px-6 py-4">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.18s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>

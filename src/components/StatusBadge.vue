<script setup lang="ts">
import { computed } from 'vue'
import type { AccountStatus } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'

const props = withDefaults(defineProps<{
  status: AccountStatus
  size?: 'sm' | 'md'
  pulse?: boolean
}>(), {
  size: 'md',
  pulse: false,
})

const meta = computed(() => statusMeta(props.status))
const live = computed(() => props.pulse && (props.status === 'pending_review' || props.status === 'active'))
</script>

<template>
  <span
    class="chip"
    :class="[meta.text, meta.border, meta.bg, size === 'sm' ? 'text-[11px] py-0' : '']"
  >
    <span class="relative flex h-1.5 w-1.5">
      <span
        v-if="live"
        class="absolute inline-flex h-full w-full animate-pulseDot rounded-full"
        :style="{ backgroundColor: meta.color }"
      />
      <span
        class="relative inline-flex h-1.5 w-1.5 rounded-full"
        :style="{ backgroundColor: meta.color }"
      />
    </span>
    {{ meta.label }}
  </span>
</template>

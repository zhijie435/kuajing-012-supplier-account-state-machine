<script setup lang="ts">
import type { TransitionLog } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'

defineProps<{
  logs: TransitionLog[]
}>()

function logColor(from: string, to: string) {
  return statusMeta(to as never).color
}
</script>

<template>
  <div v-if="logs.length === 0" class="py-10 text-center text-sm text-ink-400">
    暂无操作记录
  </div>
  <ol v-else class="relative space-y-1 pl-6">
    <span class="absolute left-[7px] top-1 bottom-1 w-px bg-gradient-to-b from-ink-600 via-ink-700 to-transparent" />
    <li
      v-for="(log, idx) in logs"
      :key="log.id"
      class="relative py-3"
    >
      <span
        class="absolute -left-[22px] top-4 h-3.5 w-3.5 rounded-full border-2 border-ink-900"
        :style="{ backgroundColor: logColor(log.from_status, log.to_status) }"
      />
      <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span class="font-mono text-xs text-ink-300">{{ log.created_at_text }}</span>
        <span class="text-sm font-medium text-ink-50">{{ log.event_label }}</span>
        <span v-if="idx === logs.length - 1" class="chip border-brand/40 bg-brand/10 text-brand">最新</span>
      </div>
      <div class="mt-1 flex items-center gap-2 text-xs">
        <span
          class="chip"
          :class="[statusMeta(log.from_status as never).text, statusMeta(log.from_status as never).border, statusMeta(log.from_status as never).bg]"
        >
          {{ log.from_label }}
        </span>
        <span class="text-ink-400">→</span>
        <span
          class="chip"
          :class="[statusMeta(log.to_status as never).text, statusMeta(log.to_status as never).border, statusMeta(log.to_status as never).bg]"
        >
          {{ log.to_label }}
        </span>
      </div>
      <div class="mt-1.5 text-xs text-ink-300">
        <span class="font-mono text-ink-400">{{ log.operator }}</span>
        <span v-if="log.reason"> · {{ log.reason }}</span>
      </div>
    </li>
  </ol>
</template>

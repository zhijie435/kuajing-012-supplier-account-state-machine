<script setup lang="ts">
import { computed } from 'vue'
import type { AccountStatus, AccountEvent } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'

const props = defineProps<{
  current: AccountStatus
  available: AccountEvent[]
}>()

interface NodePos {
  key: AccountStatus
  cx: number
  cy: number
}

const W = 156
const H = 52

const NODES: NodePos[] = [
  { key: 'draft', cx: 110, cy: 92 },
  { key: 'pending_review', cx: 360, cy: 92 },
  { key: 'active', cx: 610, cy: 92 },
  { key: 'frozen', cx: 860, cy: 92 },
  { key: 'rejected', cx: 360, cy: 272 },
  { key: 'disabled', cx: 740, cy: 272 },
]

interface EdgeDef {
  event: AccountEvent
  label: string
  from: AccountStatus
  to: AccountStatus
  d: string
  labelX: number
  labelY: number
  dashed?: boolean
}

const EDGES: EdgeDef[] = [
  { event: 'submit', label: '提交审核', from: 'draft', to: 'pending_review', d: 'M 188 92 L 282 92', labelX: 235, labelY: 82 },
  { event: 'approve', label: '审核通过', from: 'pending_review', to: 'active', d: 'M 438 92 L 532 92', labelX: 485, labelY: 82 },
  { event: 'freeze', label: '冻结', from: 'active', to: 'frozen', d: 'M 688 92 L 782 92', labelX: 735, labelY: 82 },
  { event: 'unfreeze', label: '解冻', from: 'frozen', to: 'active', d: 'M 790 66 C 790 14, 670 14, 670 66', labelX: 730, labelY: 22 },
  { event: 'reject', label: '驳回', from: 'pending_review', to: 'rejected', d: 'M 360 118 L 360 246', labelX: 372, labelY: 184 },
  { event: 'resubmit', label: '重新提交', from: 'rejected', to: 'pending_review', d: 'M 392 246 C 444 246, 444 118, 392 118', labelX: 452, labelY: 184 },
  { event: 'disable', label: '停用', from: 'active', to: 'disabled', d: 'M 656 118 L 700 246', labelX: 660, labelY: 190, dashed: true },
  { event: 'disable', label: '停用', from: 'rejected', to: 'disabled', d: 'M 438 272 L 662 272', labelX: 540, labelY: 262, dashed: true },
]

function isCurrent(key: AccountStatus) {
  return props.current === key
}

function edgeState(edge: EdgeDef) {
  if (edge.from === props.current && props.available.includes(edge.event)) {
    return 'active'
  }
  if (edge.to === props.current) {
    return 'reached'
  }
  return 'idle'
}

const nodeMap = computed(() => {
  const m: Partial<Record<AccountStatus, NodePos>> = {}
  for (const n of NODES) m[n.key] = n
  return m
})
</script>

<template>
  <div class="relative w-full overflow-x-auto">
    <svg viewBox="0 0 970 340" class="w-full" style="min-width: 720px">
      <defs>
        <marker id="arrow-idle" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#3a414d" />
        </marker>
        <marker id="arrow-active" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#e6b54a" />
        </marker>
        <marker id="arrow-reached" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#828b99" />
        </marker>
      </defs>

      <!-- edges -->
      <g v-for="edge in EDGES" :key="edge.event + edge.from + edge.to">
        <path
          :d="edge.d"
          fill="none"
          :stroke="edgeState(edge) === 'active' ? '#e6b54a' : edgeState(edge) === 'reached' ? '#5b6471' : '#272c35'"
          :stroke-width="edgeState(edge) === 'active' ? 2.4 : 1.4"
          :stroke-dasharray="edge.dashed ? '5 4' : edgeState(edge) === 'active' ? '6 4' : 'none'"
          :class="edgeState(edge) === 'active' ? 'animate-pulseDot' : ''"
          :marker-end="edgeState(edge) === 'active' ? 'url(#arrow-active)' : edgeState(edge) === 'reached' ? 'url(#arrow-reached)' : 'url(#arrow-idle)'"
        />
        <text
          :x="edge.labelX"
          :y="edge.labelY"
          text-anchor="middle"
          class="font-sans"
          :fill="edgeState(edge) === 'active' ? '#f0c878' : '#5b6471'"
          :font-size="edgeState(edge) === 'active' ? '12' : '11'"
          :font-weight="edgeState(edge) === 'active' ? '600' : '400'"
        >
          {{ edge.label }}
        </text>
      </g>

      <!-- nodes -->
      <g v-for="node in NODES" :key="node.key">
        <rect
          :x="node.cx - W / 2"
          :y="node.cy - H / 2"
          :width="W"
          :height="H"
          rx="12"
          :fill="isCurrent(node.key) ? statusMeta(node.key).color + '1f' : '#14171c'"
          :stroke="isCurrent(node.key) ? statusMeta(node.key).color : '#272c35'"
          :stroke-width="isCurrent(node.key) ? '2' : '1.2'"
        />
        <circle
          v-if="isCurrent(node.key)"
          :cx="node.cx"
          :cy="node.cy - H / 2"
          r="3"
          :fill="statusMeta(node.key).color"
          class="animate-pulseDot"
        />
        <text
          :x="node.cx"
          :y="node.cy + 5"
          text-anchor="middle"
          :fill="isCurrent(node.key) ? statusMeta(node.key).color : '#aeb4bf'"
          :font-size="isCurrent(node.key) ? '15' : '14'"
          :font-weight="isCurrent(node.key) ? '600' : '500'"
          class="font-sans"
        >
          {{ statusMeta(node.key).label }}
        </text>
        <text
          v-if="isCurrent(node.key)"
          :x="node.cx"
          :y="node.cy - H / 2 - 8"
          text-anchor="middle"
          fill="#e6b54a"
          font-size="10"
          font-weight="600"
          class="font-mono"
        >
          当前状态
        </text>
      </g>
    </svg>

    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 px-1 text-xs text-ink-400">
      <span class="flex items-center gap-1.5"><span class="h-0.5 w-5 rounded bg-brand" />可执行迁移</span>
      <span class="flex items-center gap-1.5"><span class="h-0.5 w-5 rounded bg-ink-500" />已到达路径</span>
      <span class="flex items-center gap-1.5"><span class="h-0.5 w-5 rounded border-t border-dashed border-ink-500" />停用（任意非终态可触发）</span>
    </div>
  </div>
</template>

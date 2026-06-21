<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Plus, RefreshCw, Search } from 'lucide-vue-next'
import type { Account, AccountStatus } from '@/api/types'
import { STATUS_META, STATUS_ORDER } from '@/lib/statusMeta'
import { useAccounts } from '@/composables/useAccounts'
import { api } from '@/api/client'
import StatusBadge from '@/components/StatusBadge.vue'
import CreateAccountDialog from '@/components/CreateAccountDialog.vue'

const router = useRouter()
const route = useRoute()

const { accounts, loading, loadList, addAccount, markDirty } = useAccounts()

const keyword = ref('')
const statusFilter = ref<AccountStatus | 'all'>('all')
const showCreate = ref(false)
const countsLoading = ref(false)
const allAccounts = ref<Account[]>([])

async function loadCounts(force = false) {
  countsLoading.value = true
  try {
    allAccounts.value = await api.listAccounts()
  } finally {
    countsLoading.value = false
  }
}

const counts = computed(() => {
  const c: Record<string, number> = { all: allAccounts.value.length }
  for (const s of STATUS_ORDER) c[s] = 0
  for (const a of allAccounts.value) c[a.status] = (c[a.status] ?? 0) + 1
  return c
})

const filtered = computed(() => accounts.value)

let searchTimer: ReturnType<typeof setTimeout> | null = null
function scheduleLoad() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    load()
  }, 200)
}

async function load(force = false) {
  const params: { status?: string; keyword?: string } = {}
  if (statusFilter.value !== 'all') params.status = statusFilter.value
  if (keyword.value.trim()) params.keyword = keyword.value.trim()
  await loadList(params, force)
  await loadCounts(force)
}

function openDetail(id: number) {
  markDirty()
  router.push(`/accounts/${id}`)
}

function onAccountCreated(account: import('@/api/types').Account) {
  showCreate.value = false
  addAccount(account)
  allAccounts.value = [account, ...allAccounts.value]
}

onMounted(load)

watch(
  () => route.name,
  (name) => {
    if (name === 'accounts') {
      load(true)
    }
  },
)

watch(statusFilter, () => load())
watch(keyword, () => scheduleLoad())
</script>

<template>
  <div class="mx-auto max-w-7xl px-6 py-8">
    <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="font-mono text-xs uppercase tracking-[0.2em] text-brand/80">Settlement Account · FSM</p>
        <h1 class="mt-1 font-display text-3xl font-bold tracking-tight text-ink-50">
          供应商结算账户状态台
        </h1>
        <p class="mt-2 max-w-2xl text-sm text-ink-300">
          统一沉淀结算账户「审核通过 / 驳回 / 冻结 / 解冻 / 停用」全生命周期状态机，所有操作留痕可追溯。
        </p>
      </div>
      <div class="flex items-center gap-3">
        <button class="btn-ghost" :disabled="loading" @click="() => load(true)">
          <RefreshCw :size="16" :class="loading ? 'animate-spin' : ''" />
          刷新
        </button>
        <button class="btn-primary" @click="showCreate = true">
          <Plus :size="16" />
          新建账户
        </button>
      </div>
    </header>

    <!-- 统计 -->
    <section class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
      <button
        class="panel group p-4 text-left transition-colors hover:border-ink-500"
        :class="statusFilter === 'all' ? 'border-brand/50 ring-1 ring-brand/20' : ''"
        @click="statusFilter = 'all'"
      >
        <p class="font-mono text-xs text-ink-400">全部</p>
        <p class="mt-1 font-display text-2xl font-bold text-ink-50">{{ counts.all ?? 0 }}</p>
      </button>
      <button
        v-for="s in STATUS_ORDER"
        :key="s"
        class="panel group p-4 text-left transition-colors"
        :class="statusFilter === s ? 'ring-1' : 'hover:border-ink-500'"
        :style="statusFilter === s ? { boxShadow: `0 0 0 1px ${STATUS_META[s].color}55` } : {}"
        @click="statusFilter = s"
      >
        <p class="flex items-center gap-1.5 font-mono text-xs" :style="{ color: STATUS_META[s].color }">
          <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: STATUS_META[s].color }" />
          {{ STATUS_META[s].label }}
        </p>
        <p class="mt-1 font-display text-2xl font-bold text-ink-50">{{ counts[s] ?? 0 }}</p>
      </button>
    </section>

    <!-- 搜索 -->
    <div class="panel mb-4 flex items-center gap-3 px-4 py-3">
      <Search :size="16" class="text-ink-400" />
      <input
        v-model="keyword"
        class="flex-1 bg-transparent text-sm text-ink-100 outline-none placeholder-ink-400"
        placeholder="搜索供应商名称、编号或银行账号"
      />
      <span v-if="keyword" class="font-mono text-xs text-ink-400">{{ filtered.length }} 条匹配</span>
    </div>

    <!-- 列表 -->
    <div class="panel overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-ink-700/70 text-left text-xs uppercase tracking-wider text-ink-400">
            <th class="px-5 py-3 font-medium">供应商 / 编号</th>
            <th class="px-5 py-3 font-medium">结算账户</th>
            <th class="px-5 py-3 font-medium">开户行</th>
            <th class="px-5 py-3 font-medium">状态</th>
            <th class="px-5 py-3 font-medium">更新时间</th>
            <th class="px-5 py-3 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="a in filtered"
            :key="a.id"
            class="group cursor-pointer border-b border-ink-800/60 transition-colors hover:bg-ink-800/40"
            @click="openDetail(a.id)"
          >
            <td class="px-5 py-4">
              <p class="font-medium text-ink-50">{{ a.supplier_name }}</p>
              <p class="font-mono text-xs text-ink-400">{{ a.supplier_code }}</p>
            </td>
            <td class="px-5 py-4">
              <p class="font-mono text-xs text-ink-200">{{ a.account_no_masked }}</p>
              <p class="text-xs text-ink-400">{{ a.bank_name }}{{ a.bank_branch ? ' · ' + a.bank_branch : '' }}</p>
            </td>
            <td class="px-5 py-4 text-ink-300">{{ a.bank_name }}</td>
            <td class="px-5 py-4">
              <StatusBadge :status="a.status" :pulse="a.status === 'pending_review'" />
            </td>
            <td class="px-5 py-4 font-mono text-xs text-ink-400">{{ a.updated_at_text }}</td>
            <td class="px-5 py-4 text-right">
              <span class="inline-flex items-center gap-1 text-xs text-ink-300 transition-colors group-hover:text-brand">
                详情 <ChevronRight :size="14" />
              </span>
            </td>
          </tr>
          <tr v-if="filtered.length === 0 && !loading">
            <td colspan="6" class="px-5 py-12 text-center text-ink-400">没有匹配的账户</td>
          </tr>
          <tr v-if="loading && filtered.length === 0">
            <td colspan="6" class="px-5 py-12 text-center text-ink-400">加载中…</td>
          </tr>
        </tbody>
      </table>
    </div>

    <CreateAccountDialog
      :open="showCreate"
      @close="showCreate = false"
      @done="onAccountCreated"
    />
  </div>
</template>

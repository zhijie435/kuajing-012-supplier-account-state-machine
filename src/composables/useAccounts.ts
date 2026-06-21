import { ref, shallowRef } from 'vue'
import { api } from '@/api/client'
import type { Account, TransitionLog, AccountRole } from '@/api/types'

/**
 * 操作人全局配置（单例）
 * 生产环境应从登录态获取，此处提供切换入口模拟不同角色
 */
const operator = ref<string>('admin_root')

function deriveRole(op: string): AccountRole | null {
  const s = op.toLowerCase()
  if (s.startsWith('supplier_') || s.startsWith('sup_')) return 'supplier'
  if (s.startsWith('reviewer_') || s.startsWith('rev_')) return 'reviewer'
  if (s.startsWith('risk_') || s.startsWith('rsk_')) return 'risk'
  if (s.startsWith('admin_') || s.startsWith('adm_') || s === 'ops-admin') return 'admin'
  return null
}

const currentRole = ref<AccountRole | null>(deriveRole(operator.value))

/**
 * 供应商账户共享状态存储（单例）
 *
 * 解决"保存后列表和详情状态不一致"问题：
 * - 列表页和详情页共享同一份响应式数据
 * - 详情页触发状态变更后，同时更新单条记录和列表中的对应条目
 * - 列表页激活时自动刷新，确保和服务端最终态一致
 */

const accounts = shallowRef<Account[]>([])
const accountCache = new Map<number, Account>()
const historyCache = new Map<number, TransitionLog[]>()
const loading = ref(false)
const lastLoadAt = ref(0)
const lastFilterKey = ref('')

let loadPromise: Promise<Account[]> | null = null

export function useAccounts() {
  function setOperator(op: string): void {
    operator.value = op
    currentRole.value = deriveRole(op)
    clearCache()
  }

  async function loadList(
    params: { status?: string; keyword?: string } = {},
    force = false,
  ): Promise<Account[]> {
    const now = Date.now()
    const filterKey = `${operator.value}::${params.status ?? 'all'}::${params.keyword ?? ''}`
    if (!force && loadPromise) return loadPromise
    // 缓存策略：筛选条件变化 或 标记脏数据 或 超过缓存时限，才重新拉取
    if (!force && accounts.value.length > 0 && now - lastLoadAt.value < 30000 && lastFilterKey.value === filterKey) {
      return accounts.value
    }

    loading.value = true
    lastFilterKey.value = filterKey
    loadPromise = api
      .listAccounts({ ...params, operator: operator.value })
      .then((list) => {
        accounts.value = list
        lastLoadAt.value = now
        for (const a of list) accountCache.set(a.id, a)
        return list
      })
      .finally(() => {
        loading.value = false
        loadPromise = null
      })
    return loadPromise
  }

  async function loadOne(id: number, force = false): Promise<Account> {
    if (!force && accountCache.has(id)) {
      return accountCache.get(id)!
    }
    const acc = await api.getAccount(id, operator.value)
    accountCache.set(id, acc)
    syncListEntry(acc)
    return acc
  }

  async function loadHistory(id: number, force = false): Promise<TransitionLog[]> {
    if (!force && historyCache.has(id)) {
      return historyCache.get(id)!
    }
    const logs = await api.getHistory(id)
    historyCache.set(id, logs)
    return logs
  }

  /**
   * 更新账户状态并同步到所有依赖：
   * 1. 更新单条缓存
   * 2. 更新列表中的对应条目（响应式更新列表展示）
   * 3. 标记列表脏数据，下次激活时刷新
   */
  function updateAccount(account: Account): void {
    accountCache.set(account.id, account)
    syncListEntry(account)
  }

  function addAccount(account: Account): void {
    accountCache.set(account.id, account)
    accounts.value = [account, ...accounts.value]
  }

  function syncListEntry(account: Account): void {
    const list = accounts.value
    const idx = list.findIndex((a) => a.id === account.id)
    if (idx !== -1) {
      list[idx] = account
      accounts.value = [...list]
    }
  }

  function markDirty(): void {
    lastLoadAt.value = 0
  }

  function clearCache(): void {
    accountCache.clear()
    historyCache.clear()
    accounts.value = []
    lastLoadAt.value = 0
  }

  return {
    accounts,
    loading,
    operator,
    currentRole,
    setOperator,
    loadList,
    loadOne,
    loadHistory,
    updateAccount,
    addAccount,
    markDirty,
    clearCache,
  }
}

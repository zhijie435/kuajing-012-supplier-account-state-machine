import type {
  Account,
  AccountEvent,
  ApiResult,
  StateMachineDefinition,
  TransitionLog,
} from './types'

const BASE = '/api'

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
  let payload: ApiResult<T>
  try {
    payload = (await res.json()) as ApiResult<T>
  } catch {
    throw new Error('服务器返回了无法解析的内容')
  }
  if (!res.ok || payload.code !== 0) {
    throw new Error(payload?.message || `请求失败 (${res.status})`)
  }
  return payload.data
}

export const api = {
  definition: () => request<StateMachineDefinition>('/definition'),
  listAccounts: (params: { status?: string; keyword?: string } = {}) => {
    const qs = new URLSearchParams()
    if (params.status) qs.set('status', params.status)
    if (params.keyword) qs.set('keyword', params.keyword)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''
    return request<Account[]>(`/accounts${suffix}`)
  },
  getAccount: (id: number) => request<Account>(`/accounts/${id}`),
  getHistory: (id: number) => request<TransitionLog[]>(`/accounts/${id}/history`),
  createAccount: (input: Record<string, unknown>) =>
    request<Account>('/accounts', {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  trigger: (id: number, event: AccountEvent, body: { reason?: string; operator?: string }) =>
    request<Account>(`/accounts/${id}/${event}`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),
}

/**
 * Typed fetch wrapper for the Messaging API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on
 *    demand, with one silent retry on 401 (a key can be revoked before its
 *    local expiry).
 *  - Company context (cmp_id, bo_id) on every scoped call, as query parameters
 *    and — for JSON bodies — in the body too, which is what the backend's
 *    Http::param() reads.
 *  - An `Idempotency-Key` on every mutation, generated per call and held
 *    across the retry. Sending a message is the one place in this product
 *    where a retry must not reach a customer twice, and the surest way to get
 *    that right everywhere is to never leave it to the caller.
 *  - An `X-Correlation-Id` so a failure here and the cross-service log line
 *    behind it can be joined up.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

export interface CompanyScope {
  cmp_id: number
  /** 0 = all locations for this company. */
  bo_id: number
}

export interface ListMeta {
  total: number
  limit: number
  offset: number
  [key: string]: unknown
}

export interface ListResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /**
   * True when pressing the same button again could reasonably work.
   *
   * The backend says so explicitly, because the difference between "Books was
   * unreachable" and "the customer has opted out" decides whether the UI
   * offers Retry or explains that nothing more can be done.
   */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 502 || this.status === 503 || this.status === 504
  }

  /** Somebody else changed the thing you were looking at. Reload, do not retry. */
  get isVersionConflict(): boolean {
    return this.status === 409 && this.details.reason === 'version_conflict'
  }

  /**
   * True when the CUSTOMER said no, rather than the caller lacking permission.
   *
   * These read identically as "refused" and are completely different: one
   * needs an administrator, the other needs the customer to opt back in. A UI
   * that conflates them sends somebody to ask for access they already have.
   */
  get isCustomerDecision(): boolean {
    return this.details.customer_decision === true
  }

  /** The draft was edited after it was approved, so the approval no longer applies. */
  get isApprovalStale(): boolean {
    return this.code === 'approval_stale'
  }

  /** Per-gate results from a refused send, so the UI can point at the failing check. */
  get gateChecks(): Array<{ gate: string; passed: boolean; detail: string }> {
    const checks = this.details.checks
    return Array.isArray(checks) ? (checks as Array<{ gate: string; passed: boolean; detail: string }>) : []
  }

  /** Field-level validation messages, when the backend sent any. */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [key, value] of Object.entries(this.details)) {
      if (typeof value === 'string' && key !== 'reason') out[key] = value
    }
    return out
  }
}

/** The company scope, registered once by MessagingProvider and read by every call. */
let scope: CompanyScope | null = null

export function setScope(next: CompanyScope | null): void {
  scope = next
}

export function getScope(): CompanyScope | null {
  return scope
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

/**
 * A key the backend will accept: 8–200 characters of [A-Za-z0-9._:-].
 *
 * `crypto.randomUUID` where it exists, and a composed fallback where it does
 * not — an older browser must still be protected from a double-tap, and that
 * is exactly the browser most likely to be on a bad connection.
 */
function newKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}-${Math.random()
    .toString(36)
    .slice(2, 12)}`
}

/** One id per page load, so a user's session can be traced across products. */
const CORRELATION_ID = newKey().replace(/[^A-Za-z0-9._:-]/g, '').slice(0, 32)

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  signal?: AbortSignal
  /** Reuse a key across retries of the same user action. Generated when omitted. */
  idempotencyKey?: string
  /** FormData for an upload, which must not be JSON-encoded. */
  formData?: FormData
}

async function send<T>(path: string, options: RequestOptions, sesKey: string, key: string): Promise<T> {
  const scoped = options.scoped !== false
  const method = options.method ?? 'GET'

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
    'X-Correlation-Id': CORRELATION_ID,
  }

  if (method !== 'GET') {
    headers['Idempotency-Key'] = key
  }

  let body: string | FormData | undefined

  if (options.formData !== undefined) {
    // No Content-Type: the browser sets the multipart boundary, and setting it
    // by hand is the classic way to make an upload fail server-side.
    body = options.formData
    if (scoped && scope) {
      options.formData.set('cmp_id', String(scope.cmp_id))
      options.formData.set('bo_id', String(scope.bo_id))
    }
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    // Context travels in the body as well: a POST that carries it only in the
    // query string works until somebody reads the body first, and then fails
    // in a way that looks like the company was never chosen.
    const payload =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...scope, ...(options.body as Record<string, unknown>) }
        : options.body
    body = JSON.stringify(payload)
  }

  const response = await fetch(buildUrl(path, options.params, scoped), {
    method,
    headers,
    body,
    signal: options.signal,
    // Never let a browser or a proxy keep a conversation, a balance or an
    // order value. The backend sends no-store too; this is the other half.
    cache: 'no-store',
  })

  const text = await response.text()
  let parsed: unknown = null
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = null
    }
  }

  if (!response.ok) {
    const envelope = parsed as
      | { error?: { code?: string; message?: string; details?: Record<string, unknown> }; message?: string }
      | null
    throw new ApiError(
      response.status,
      envelope?.error?.code ?? 'error',
      envelope?.error?.message ?? envelope?.message ?? `Request failed (${response.status})`,
      envelope?.error?.details ?? {},
    )
  }

  return parsed as T
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const sesKey = await ensureSesKey()
  // Generated once, OUTSIDE the retry, so the retry replays rather than
  // sending a customer a second message.
  const key = options.idempotencyKey ?? newKey()

  try {
    return await send<T>(path, options, sesKey, key)
  } catch (error) {
    // Exactly one retry, and only for 401: a key can be revoked server-side
    // before it expires locally, and making the user sign in again for that is
    // a bad trade. Anything else is the caller's to handle.
    if (error instanceof ApiError && error.status === 401) {
      const freshKey = await ensureSesKey(true)
      return await send<T>(path, options, freshKey, key)
    }
    throw error
  }
}

export const api = {
  get: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', params, signal }),

  list: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ListResponse<T>>(path, { method: 'GET', params, signal }),

  post: <T>(path: string, body?: unknown, params?: QueryParams, idempotencyKey?: string) =>
    request<ItemResponse<T>>(path, { method: 'POST', body: body ?? {}, params, idempotencyKey }),

  /** For endpoints whose whole payload is the answer rather than a `{data}` envelope. */
  postRaw: <T>(path: string, body?: unknown, params?: QueryParams, idempotencyKey?: string) =>
    request<T>(path, { method: 'POST', body: body ?? {}, params, idempotencyKey }),

  put: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'PUT', body: body ?? {}, params }),

  patch: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'PATCH', body: body ?? {}, params }),

  del: <T>(path: string, params?: QueryParams) => request<ItemResponse<T>>(path, { method: 'DELETE', params }),

  upload: <T>(path: string, formData: FormData) =>
    request<ItemResponse<T>>(path, { method: 'POST', formData }),

  /**
   * Context-free: the health check, the portal relay, and the company switcher.
   *
   * Takes a signal because the switcher pages through Manage and can be
   * unmounted mid-flight; without it those pages keep arriving and setting
   * state on a component that is gone.
   */
  unscoped: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', params, scoped: false, signal }),
}

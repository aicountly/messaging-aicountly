/**
 * AICOUNTLY company lifecycle: a company whose Messaging subscription has lapsed is read-only here
 * until Manage removes its data (manage-aicountly docs/company-lifecycle/README.md).
 *
 * Two signals reach the browser, and both end up here:
 *  - a write the API refuses with HTTP 423 `{code: 'COMPANY_RESTRICTED', message, restricted_until}`
 *    (the API client calls noteCompanyRestricted for it), and
 *  - the company's lifecycle as the API last learned it from Manage's companyinfo
 *    (`lifecycle: 'restricted' | 'purge_pending' | 'purged'`, `restricted_until`), read with
 *    noteCompanyLifecycle.
 *
 * The state is kept per company in memory and announced with COMPANY_RESTRICTED_EVENT, which
 * CompanyRestrictedBanner listens to. The server stays the authority: nothing here blocks a request,
 * it only explains the 423 the server sends.
 */

export const COMPANY_RESTRICTED_EVENT = 'messaging:company-restricted'
export const COMPANY_RESTRICTED_CODE = 'COMPANY_RESTRICTED'

export interface CompanyRestriction {
  cmpId: number
  restrictedUntil: string | null
  message: string
  lifecycle: string
}

export interface CompanyRestrictionEventDetail {
  cmpId: number
  restricted: boolean
  source: 'api' | 'companyinfo'
  message?: string
}

const BLOCKING = new Set(['restricted', 'purge_pending', 'purged'])
const restrictions = new Map<number, CompanyRestriction>()

function announce(detail: CompanyRestrictionEventDetail): void {
  try {
    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent<CompanyRestrictionEventDetail>(COMPANY_RESTRICTED_EVENT, { detail }))
    }
  } catch {
    /* no window */
  }
}

/** "28 Nov 2026" for an ISO timestamp; null when absent or unreadable. */
export function formatRestrictedUntil(iso: string | null | undefined): string | null {
  if (!iso) return null
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return null
  return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kolkata' })
}

/** The sentence the server sends, rebuilt for when only the date is known. */
export function restrictionMessage(restrictedUntil: string | null | undefined, lifecycle = 'restricted'): string {
  if (lifecycle === 'purged') {
    return "This company's data in this application was deleted after its subscription ended. Renew the subscription to start again."
  }
  if (lifecycle === 'purge_pending') {
    return 'This company is read-only because its subscription ended, and its data in this application is being deleted. Export what you need now.'
  }
  const date = formatRestrictedUntil(restrictedUntil)
  return date
    ? `This company is read-only until ${date} because its subscription ended. Renew to make changes, or export your data.`
    : 'This company is read-only because its subscription ended. Renew to make changes, or export your data.'
}

/** The restriction a refused response describes, or null. */
export function restrictionFromResponse(
  status: number,
  body: unknown,
): { restrictedUntil: string | null; message: string; lifecycle: string } | null {
  const b = (body && typeof body === 'object' ? body : {}) as Record<string, unknown>
  const nested = (b.error && typeof b.error === 'object' ? b.error : {}) as Record<string, unknown>
  const details = (nested.details && typeof nested.details === 'object' ? nested.details : {}) as Record<string, unknown>
  const code = (typeof b.code === 'string' ? b.code : null) ?? (typeof nested.code === 'string' ? nested.code : null)
  if (status !== 423 && code !== COMPANY_RESTRICTED_CODE) return null
  if (status === 423 && code && code !== COMPANY_RESTRICTED_CODE) return null
  const until = [b.restricted_until, nested.restricted_until, details.restricted_until].find((v) => typeof v === 'string')
  const restrictedUntil = typeof until === 'string' ? until : null
  const lifecycle = typeof b.lifecycle === 'string' ? b.lifecycle : 'restricted'
  const message =
    (typeof b.message === 'string' && b.message) ||
    (typeof nested.message === 'string' && nested.message) ||
    restrictionMessage(restrictedUntil, lifecycle)
  return { restrictedUntil, message, lifecycle }
}

/** Record that a company is read-only and tell the page. */
export function noteCompanyRestricted(info: {
  cmpId: number | null | undefined
  restrictedUntil?: string | null
  message?: string
  lifecycle?: string
  source?: 'api' | 'companyinfo'
}): void {
  const cmpId = Number(info.cmpId ?? 0)
  if (!Number.isFinite(cmpId) || cmpId <= 0) return
  const lifecycle = info.lifecycle || 'restricted'
  const entry: CompanyRestriction = {
    cmpId,
    restrictedUntil: info.restrictedUntil ?? null,
    message: info.message || restrictionMessage(info.restrictedUntil ?? null, lifecycle),
    lifecycle,
  }
  restrictions.set(cmpId, entry)
  announce({ cmpId, restricted: true, source: info.source ?? 'api', message: entry.message })
}

/** The company is not (or no longer) restricted. */
export function clearCompanyRestriction(cmpId: number): void {
  if (!restrictions.has(cmpId)) return
  restrictions.delete(cmpId)
  announce({ cmpId, restricted: false, source: 'companyinfo' })
}

/**
 * Read `lifecycle` / `restricted_until` from what the API or Manage's companyinfo says (a row, its
 * `data`, `data.company`, or the API's `{lifecycle, restricted_until, message}` / null).
 */
export function noteCompanyLifecycle(cmpId: number, body: unknown): void {
  if (body === null || body === undefined) {
    clearCompanyRestriction(cmpId)
    return
  }
  const root = (typeof body === 'object' ? body : {}) as Record<string, unknown>
  const data = (root.data && typeof root.data === 'object' ? root.data : null) as Record<string, unknown> | null
  const company = (data?.company && typeof data.company === 'object' ? data.company : null) as Record<string, unknown> | null
  const holder = [company, data, root].find((s) => s && typeof s.lifecycle === 'string')
  if (!holder) return
  const lifecycle = String(holder.lifecycle).toLowerCase()
  if (BLOCKING.has(lifecycle)) {
    const until = typeof holder.restricted_until === 'string' ? holder.restricted_until : null
    const message = typeof holder.message === 'string' ? holder.message : undefined
    noteCompanyRestricted({ cmpId, restrictedUntil: until, lifecycle, message, source: 'companyinfo' })
  } else if (lifecycle === 'active') {
    clearCompanyRestriction(cmpId)
  }
}

export function getCompanyRestriction(cmpId: number | null | undefined): CompanyRestriction | null {
  return cmpId ? restrictions.get(Number(cmpId)) ?? null : null
}

/** Test seam. */
export function __resetCompanyRestrictionsForTests(): void {
  restrictions.clear()
}

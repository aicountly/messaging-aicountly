/**
 * The plan-limit refusal (CONTRACTS §3.6). A write the company's plan does not allow answers
 *
 *   402 {"error":{"code":"PLAN_LIMIT_REACHED","details":{"code":"messaging.max_channels","limit":2,"used":2}}}
 *
 * and the web says "Your plan allows 2 channel connections — ask the account owner to upgrade in
 * Manage.", with a link to Manage's Plans & billing (web only). Plans are changed by the account owner
 * in Manage, never here. The API client calls notePlanLimit for every such refusal; PlanLimitToast in
 * the shell listens, so a screen that shows no error of its own still explains why nothing was saved.
 * The server stays the authority: nothing here blocks a request. Pure: no imports.
 */

export const PLAN_LIMIT_CODE = 'PLAN_LIMIT_REACHED'
export const PLAN_LIMIT_EVENT = 'messaging:plan-limit'

export interface PlanLimitDetails {
  code: string
  limit: number
  used: number | null
}

/** What each of Messaging's limits counts, singular and plural. */
const NOUNS: Record<string, [string, string]> = {
  'messaging.max_channels': ['channel connection', 'channel connections'],
  'messaging.max_messages_per_month': ['outgoing message a month', 'outgoing messages a month'],
}

function wholeNumber(v: unknown): number | null {
  if (typeof v === 'number' && Number.isInteger(v) && v >= 0) return v
  if (typeof v === 'string' && /^\d{1,9}$/.test(v.trim())) return Number(v.trim())
  return null
}

/** The refusal's details ({code, limit, used}) when this answer is a plan-limit refusal, otherwise null. */
export function planLimitFromResponse(status: number, body: unknown): PlanLimitDetails | null {
  const envelope = body && typeof body === 'object' ? (body as { error?: unknown; code?: unknown; details?: unknown }) : {}
  const error = envelope.error && typeof envelope.error === 'object' ? (envelope.error as { code?: unknown; details?: unknown }) : null
  const code = typeof error?.code === 'string' ? error.code : typeof envelope.code === 'string' ? envelope.code : ''
  if (code !== PLAN_LIMIT_CODE && !(status === 402 && code === '')) return null
  const raw = error?.details ?? envelope.details
  const d = raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, unknown>) : {}
  return { code: typeof d.code === 'string' ? d.code : '', limit: wholeNumber(d.limit) ?? 0, used: wholeNumber(d.used) }
}

/** The sentence a person reads for a plan-limit refusal. */
export function planLimitMessage(details: PlanLimitDetails | null): string {
  if (!details || !details.code) return "This company's plan limit is reached — ask the account owner to upgrade in Manage."
  const nouns = NOUNS[details.code]
  const what = nouns ? ` ${details.limit === 1 ? nouns[0] : nouns[1]}` : ''
  return `Your plan allows ${details.limit}${what} — ask the account owner to upgrade in Manage.`
}

/** Manage's Plans & billing, in the environment this page runs in (sandbox hosts → Manage sandbox). */
export function managePlansUrl(hostname: string = typeof window !== 'undefined' ? window.location.hostname : ''): string {
  const sandbox = /(^|\.)gh\.aicountly\.com$/i.test(hostname) || /^gh-/i.test(hostname) || hostname === 'localhost' || hostname === '127.0.0.1'
  return `${sandbox ? 'https://manage.gh.aicountly.com' : 'https://manage.aicountly.com'}/billing`
}

/** Announce a refusal to the shell's PlanLimitToast. */
export function notePlanLimit(details: PlanLimitDetails): void {
  try {
    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent<PlanLimitDetails>(PLAN_LIMIT_EVENT, { detail: details }))
    }
  } catch {
    // Explaining a refusal must never turn into a second error.
  }
}

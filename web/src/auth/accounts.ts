/**
 * The AICOUNTLY accounts signed in on this browser.
 *
 * The portal keeps up to five sign-ins per browser (my-aicountly-com
 * `App\Libraries\AccountRoster`). Two cookies carry them, both on
 * `.aicountly.com`, so both arrive at every product host:
 *
 *   AIC_ACCOUNTS       httpOnly — the tokens themselves. Unreadable here, and
 *                      meant to be: switching is the portal's decision, never
 *                      this app's.
 *   AIC_ACCOUNTS_VIEW  readable — the same roster as labels. Name, subscriber
 *                      id, MASKED e-mail and mobile. Nothing in it authorises
 *                      anything, which is why it may be read from JavaScript at
 *                      all (the portal's API is cross-origin to every product
 *                      and does not accept credentials, so this cookie is the
 *                      only channel the menu actually has).
 *
 * Everything that CHANGES an account — switching, adding, signing one out — is
 * a navigation to the portal, which checks the httpOnly roster. Editing the
 * readable cookie changes labels on one screen and nothing else.
 *
 * This file is a deliberate TWIN of notes-aicountly `web/src/auth/accounts.ts`
 * and books-react-app `web/src/services/accounts.js`. Only the imports differ.
 * Keep the three in step — the contract they implement is
 * `AICOUNTLY_MULTI_ACCOUNT.md` in the Saas Common Handler, and a product that
 * drifts from it is a product whose menu lies about who is signed in.
 */

import { resolveLoginPortalOrigin, resolveProductKeyFromHost } from './hostnames'
import { clearSession } from './tokens'
import { clearStoredCompanyScope } from '../context/MessagingContext'

/** The portal's cap. Mirrors AccountRoster::MAX_ACCOUNTS. */
export const MAX_ACCOUNTS = 5

const VIEW_COOKIE = 'AIC_ACCOUNTS_VIEW'

export interface RosterAccount {
  /** uuid_aictly — the subscriber id, and what the portal switches on. */
  uuid: number
  /**
   * What a menu shows: the first name when the portal sent one, else the whole
   * name. May be empty when the account has no name on file.
   */
  name: string
  /** The whole name, for anything that wants more than the menu line. */
  fullName: string
  /** Already masked by the portal, e.g. `ra•••@cagupta.in`. Never the full address. */
  maskedEmail: string
  /** Already masked by the portal, e.g. `+91••••••••10`. */
  maskedMobile: string
  /** The account this browser is currently using. */
  active: boolean
}

/** base64url → JSON. Null for anything that is not our frame. */
function decodeViewCookie(raw: string): unknown {
  try {
    const padded = raw.replace(/-/g, '+').replace(/_/g, '/')
    const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4))
    // The cookie holds UTF-8 (names, masking bullets); atob yields bytes.
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
    return JSON.parse(new TextDecoder().decode(bytes))
  } catch {
    return null
  }
}

function readCookie(name: string): string {
  if (typeof document === 'undefined') return ''
  const prefix = `${name}=`
  for (const part of document.cookie.split(';')) {
    const trimmed = part.trim()
    if (trimmed.startsWith(prefix)) return decodeURIComponent(trimmed.slice(prefix.length))
  }
  return ''
}

/**
 * Every account signed in on this browser, active one first.
 *
 * An empty list is normal and not an error: a browser signed in before the
 * roster shipped has no cookie yet, and local development has no
 * `.aicountly.com` cookie at all. The menu falls back to sending the user to
 * the portal chooser, which always knows the truth.
 */
export function readAccountRoster(): RosterAccount[] {
  const raw = readCookie(VIEW_COOKIE)
  if (!raw) return []

  const data = decodeViewCookie(raw)
  if (!data || typeof data !== 'object') return []

  const entries = (data as { a?: unknown }).a
  if (!Array.isArray(entries)) return []

  const accounts: RosterAccount[] = []
  for (const entry of entries) {
    if (!entry || typeof entry !== 'object') continue
    const record = entry as Record<string, unknown>
    const uuid = Number(record.u)
    if (!Number.isInteger(uuid) || uuid <= 0) continue

    accounts.push({
      uuid,
      // The first name (`f`) is what a menu shows, so the same person reads the
      // same name in every product; `n` is the whole name, kept for anything that
      // wants it. A portal that has not shipped `f` yet falls back to `n`.
      name: typeof record.f === 'string' && record.f !== '' ? record.f : (typeof record.n === 'string' ? record.n : ''),
      fullName: typeof record.n === 'string' ? record.n : '',
      maskedEmail: typeof record.e === 'string' ? record.e : '',
      maskedMobile: typeof record.m === 'string' ? record.m : '',
      active: Number(record.a) === 1,
    })
  }

  // Active first, then the portal's own order (most recently used).
  return accounts.slice(0, MAX_ACCOUNTS).sort((a, b) => Number(b.active) - Number(a.active))
}

/** Whether another account may still be signed in on this browser. */
export function canAddAccount(roster: RosterAccount[] = readAccountRoster()): boolean {
  return roster.length < MAX_ACCOUNTS
}

function portalUrl(path: string, query: Record<string, string> = {}): string {
  const url = new URL(`${path}/${resolveProductKeyFromHost()}`, resolveLoginPortalOrigin())
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value)
  return url.toString()
}

/**
 * Switch to another account already signed in here.
 *
 * The uuid is only ever a lookup into the browser's own httpOnly roster: one
 * that is not on it switches nothing and lands on the chooser.
 */
export function buildSwitchAccountUrl(uuid: number | string): string {
  return portalUrl('/login/switch_account', { uuid: String(uuid) })
}

/** Sign in to one more account, leaving the ones already here signed in. */
export function buildAddAccountUrl(): string {
  return portalUrl('/login/add_account')
}

/** The portal's "Choose an account" screen — the fallback when no roster is readable. */
export function buildChooseAccountUrl(): string {
  return portalUrl('/login/select_account')
}

/**
 * Leave for the portal to use another account, or — with no uuid — to add one.
 *
 * The remembered company and branch (`messaging:cmp_id`, `messaging:bo_id`) are
 * dropped first through `clearStoredCompanyScope()`. They belong to the account
 * being left: the next account almost certainly cannot open that company, and
 * `MessagingProvider` reads them on its very first render, so Messaging would
 * otherwise come back and immediately ask `v1/session` for a company this user
 * has no access to — a 403 on the first screen of a successful sign-in.
 *
 * The in-memory `ses_key` goes with them through the existing `clearSession()`
 * — it is the Bearer on every API call and belongs to the account being left —
 * and the caller stops the Connect widget, exactly as at sign-out.
 *
 * The tokens are deliberately NOT cleared. The portal replaces them on the way
 * back through /auth/callback, and leaving them in place means backing out of
 * the chooser returns the person to the account they already had, signed in,
 * rather than stranding them at a login form. The worst a cancelled switch
 * costs is picking the company again.
 */
export function performAccountSwitch(uuid?: number | string): void {
  try {
    clearStoredCompanyScope()
    clearSession()
  } catch {
    // Storage blocked (private mode): the switch itself still works.
  }

  window.location.assign(
    typeof uuid === 'number' || typeof uuid === 'string'
      ? buildSwitchAccountUrl(uuid)
      : buildAddAccountUrl(),
  )
}

/**
 * The signed-in person's first name, as the portal published it; '' when the roster cannot be
 * read.
 *
 * The menu's name has to be the same in every product, and the portal is the only thing that
 * knows it. A product's own identity API knows whatever that product was provisioned with,
 * which for someone who was never provisioned as a member is nothing at all -- that is how one
 * menu came to show the literal words "Signed in". This needs no request: the portal writes the
 * label into a cookie every product on the domain can read.
 */
export function activeAccountFirstName(): string {
  try {
    return readAccountRoster().find((account) => account.active)?.name ?? ''
  } catch {
    return ''
  }
}

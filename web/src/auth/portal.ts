/**
 * AICOUNTLY portal SSO — the login half of the app.
 *
 * Flow (docs/auth/AICOUNTLY_AUTH_WORKFLOW.md):
 *
 *   1. App opens with no auth_token
 *   2. → {portal}/login/authentication_jump/messaging?returnUrl={origin}/auth/callback
 *      The portal reuses an existing *.aicountly.com session when the user came
 *      from another AICOUNTLY product; otherwise it shows its login form.
 *   3. ← {origin}/auth/callback?auth_token=…
 *   4. POST /global/seskey (own API, same-origin relay) → ses_key
 *   5. Dashboard
 *
 * The portal is the only issuer of tokens. This app never sees a password.
 */

import {
  PORTAL_AUTH_API,
  resolveLoginPortalOrigin,
  resolveProductKeyFromHost,
} from './hostnames'
import { clearAllTokens, clearSession, getAuthToken, getSesKey, saveSession } from './tokens'
import { getApiBaseUrl } from '../config'

/** Portal convention for "come back here afterwards". */
const RETURN_PARAM = 'returnUrl'

/** Path the portal redirects back to. Served by the SPA history fallback. */
export const CALLBACK_PATH = '/auth/callback'

const LOGOUT_FLAG = 'aic_logout'
const REDIRECT_GUARD_KEY = 'messaging:loginRedirectGuard'
const REDIRECT_GUARD_MS = 12_000
const REDIRECT_MAX = 3

const SESKEY_TIMEOUT_MS = 15_000

// ---------------------------------------------------------------------------
// Logout flag
// ---------------------------------------------------------------------------

/**
 * Set for the duration of a sign-out. Without it the "no token → jump to the
 * portal" rule fires while the logout redirect is still in flight and signs the
 * user straight back in, so logout appears to do nothing.
 */
export function isLogoutInProgress(): boolean {
  try {
    return sessionStorage.getItem(LOGOUT_FLAG) === '1'
  } catch {
    return false
  }
}

export function clearLogoutFlag(): void {
  try {
    sessionStorage.removeItem(LOGOUT_FLAG)
  } catch {
    /* ignore */
  }
}

function markLogoutInProgress(): void {
  try {
    sessionStorage.setItem(LOGOUT_FLAG, '1')
  } catch {
    /* ignore */
  }
}

// ---------------------------------------------------------------------------
// Callback
// ---------------------------------------------------------------------------

export interface AuthCallback {
  authToken: string | null
  /** Error code the portal reported instead of a token, if any. */
  authError: string | null
}

/**
 * Read the portal's answer out of the current URL.
 *
 * The query string is the documented shape. The hash is also checked because
 * portals that were configured for a HashRouter product can land the token
 * there, and dropping it would look like a silent login failure.
 */
export function readAuthCallback(): AuthCallback {
  const fromSearch = new URLSearchParams(window.location.search)
  const searchToken = fromSearch.get('auth_token')
  if (searchToken) {
    return { authToken: searchToken, authError: null }
  }

  const hash = window.location.hash || ''
  const queryIndex = hash.indexOf('?')
  if (queryIndex !== -1) {
    const fromHash = new URLSearchParams(hash.slice(queryIndex + 1))
    const hashToken = fromHash.get('auth_token')
    if (hashToken) {
      return { authToken: hashToken, authError: fromHash.get('auth_error') }
    }
  }

  return { authToken: null, authError: fromSearch.get('auth_error') }
}

// ---------------------------------------------------------------------------
// Where to land after the portal
// ---------------------------------------------------------------------------

/**
 * Per tab: the address (path + query + hash) to reopen once the portal hands
 * the user back.
 *
 * The portal only knows one return address — `/auth/callback` — so without
 * this every sign-in landed on "/" and a deep link lost its screen. Since the
 * shared `.aicountly.com` cookie was retired, every first visit goes through
 * the portal, so this is the common case. Same idea as Books'
 * `rememberReturnRoute` and Inventory's `web/src/auth/portal.ts`.
 *
 * sessionStorage, not localStorage: the destination belongs to the tab that
 * was sent away, and another tab signing in must not inherit it.
 */
export const RETURN_ROUTE_KEY = 'messaging:returnRoute'

/** Query parameters that only ever carry a sign-in answer; never part of a destination. */
const AUTH_ANSWER_PARAMS = ['auth_token', 'auth_error', 'sso_code', 'sso_error']

/**
 * A same-origin, in-app address or null.
 *
 * Rejects anything that is not a path on this origin ("//host", "/\\host" are
 * protocol-relative to a browser), the sign-in paths themselves, and the bare
 * root (nothing worth restoring). Sign-in answer parameters are dropped so a
 * token can never be written back into the address bar.
 */
export function normaliseReturnRoute(route: string | null | undefined): string | null {
  if (typeof route !== 'string' || route === '') return null
  if (!route.startsWith('/') || route.startsWith('//') || route.startsWith('/\\')) return null
  let url: URL
  try {
    url = new URL(route, 'https://messaging.invalid')
  } catch {
    return null
  }
  if (url.origin !== 'https://messaging.invalid') return null
  if (url.pathname === CALLBACK_PATH || url.pathname.startsWith('/auth/')) return null
  let search = url.search
  if (AUTH_ANSWER_PARAMS.some((name) => url.searchParams.has(name))) {
    // Rebuilt only when something had to go, so an ordinary destination keeps
    // its query byte for byte.
    for (const name of AUTH_ANSWER_PARAMS) url.searchParams.delete(name)
    const rest = url.searchParams.toString()
    search = rest ? `?${rest}` : ''
  }
  const kept = `${url.pathname}${search}${url.hash}`
  return kept === '/' ? null : kept
}

/**
 * Remember where this tab is before it leaves for the portal.
 *
 * Every jump overwrites the previous answer — including with "nothing" when the
 * tab is on "/" — so a destination from an abandoned attempt is never replayed
 * on a later, unrelated sign-in.
 */
export function rememberReturnRoute(): void {
  try {
    const { pathname, search, hash } = window.location
    const route = normaliseReturnRoute(`${pathname}${search}${hash}`)
    if (route) sessionStorage.setItem(RETURN_ROUTE_KEY, route)
    else sessionStorage.removeItem(RETURN_ROUTE_KEY)
  } catch {
    /* storage unavailable — the user lands on "/" instead */
  }
}

/** The address saved by rememberReturnRoute(), read once; null when there is none. */
export function takeReturnRoute(): string | null {
  try {
    const route = sessionStorage.getItem(RETURN_ROUTE_KEY)
    sessionStorage.removeItem(RETURN_ROUTE_KEY)
    return normaliseReturnRoute(route)
  } catch {
    return null
  }
}

export function forgetReturnRoute(): void {
  try {
    sessionStorage.removeItem(RETURN_ROUTE_KEY)
  } catch {
    /* ignore */
  }
}

/**
 * Drop the token from the address bar once it has been stored, and put back
 * the address the tab had before it left for the portal (see
 * rememberReturnRoute) — "/" when there was none.
 *
 * replaceState, not assign: the token must not survive in history, and a real
 * navigation here would restart the app mid-login. BrowserRouter read the
 * address before this ran, so the callback route (AfterSignIn in App.tsx) is what
 * moves the router to the restored address.
 *
 * A portal error also lands here: the destination is restored to the address
 * bar so that "Sign in" on the signed-out screen remembers it again.
 */
export function clearCallbackFromUrl(): void {
  const route = takeReturnRoute() ?? '/'
  window.history.replaceState(null, '', `${window.location.origin}${route}`)
}

function buildCallbackUrl(): string {
  return `${window.location.origin}${CALLBACK_PATH}`
}

// ---------------------------------------------------------------------------
// Redirects to the portal
// ---------------------------------------------------------------------------

interface RedirectGuard {
  count: number
  firstAt: number
}

/**
 * Stop a redirect ping-pong with the portal.
 *
 * When the portal keeps returning a token this app cannot use, both sides are
 * happy to bounce forever and the browser just flickers. Three jumps inside
 * twelve seconds is not a login, so the loop is broken and the error surfaces.
 *
 * @returns true when the redirect may proceed.
 */
function allowRedirect(): boolean {
  const now = Date.now()
  let guard: RedirectGuard = { count: 0, firstAt: now }

  try {
    const raw = sessionStorage.getItem(REDIRECT_GUARD_KEY)
    if (raw) guard = JSON.parse(raw) as RedirectGuard
  } catch {
    guard = { count: 0, firstAt: now }
  }

  if (!Number.isFinite(guard.firstAt) || now - guard.firstAt > REDIRECT_GUARD_MS) {
    guard = { count: 0, firstAt: now }
  }
  guard.count += 1

  try {
    sessionStorage.setItem(REDIRECT_GUARD_KEY, JSON.stringify(guard))
  } catch {
    /* ignore */
  }

  return guard.count <= REDIRECT_MAX
}

export function clearRedirectGuard(): void {
  try {
    sessionStorage.removeItem(REDIRECT_GUARD_KEY)
  } catch {
    /* ignore */
  }
}

/**
 * Silent SSO. Reuses the portal web session when the user arrived from another
 * AICOUNTLY product; falls through to the portal's login form when there is
 * none.
 *
 * @returns false when the loop guard refused the jump.
 */
export function redirectToPortalSso(): boolean {
  if (isLogoutInProgress()) return false
  if (!allowRedirect()) return false

  rememberReturnRoute()
  const portal = resolveLoginPortalOrigin()
  const productKey = resolveProductKeyFromHost()
  const returnUrl = encodeURIComponent(buildCallbackUrl())

  window.location.replace(
    `${portal}/login/authentication_jump/${productKey}?${RETURN_PARAM}=${returnUrl}`,
  )
  return true
}

/**
 * The portal's login form, explicitly.
 *
 * `prompt=login` is what makes this an escape hatch rather than a second lap of
 * the same loop: without it the portal sees its own live session and jumps
 * straight back with the same unusable token.
 */
export function redirectToPortalLoginForm(): void {
  if (isLogoutInProgress()) return

  rememberReturnRoute()
  const portal = resolveLoginPortalOrigin()
  const returnUrl = encodeURIComponent(buildCallbackUrl())
  window.location.replace(`${portal}/?${RETURN_PARAM}=${returnUrl}&prompt=login`)
}

// ---------------------------------------------------------------------------
// ses_key lifecycle
// ---------------------------------------------------------------------------

export class AuthError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'AuthError'
    this.status = status
  }
}

async function fetchWithTimeout(url: string, options: RequestInit): Promise<Response> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), SESKEY_TIMEOUT_MS)
  try {
    return await fetch(url, { ...options, signal: controller.signal })
  } catch (err) {
    if ((err as Error)?.name === 'AbortError') {
      throw new AuthError('Session request timed out — please retry.', 0)
    }
    throw err
  } finally {
    clearTimeout(timer)
  }
}

/**
 * Call a portal auth endpoint, preferring this product's same-origin relay.
 *
 * The relay (server-php `/api/global/*`) exists so the browser never makes a
 * cross-origin call to the portal: a new product domain is not in the portal's
 * CORS allowlist on day one, and without the relay sign-in would fail for
 * everyone with only a CORS message to show for it. The direct call is kept as
 * the fallback for when the PHP API is not deployed yet.
 */
async function fetchPortalAuth(path: string, options: RequestInit): Promise<Response> {
  const relayUrl = `${getApiBaseUrl()}/global${path}`
  const directUrl = `${PORTAL_AUTH_API}/api${path}`

  try {
    const relayed = await fetchWithTimeout(relayUrl, options)
    // 404/5xx mean the relay itself is missing or broken, not that the portal
    // rejected the token — fall through and ask the portal directly.
    if (relayed.ok || (relayed.status < 500 && relayed.status !== 404)) {
      return relayed
    }
  } catch {
    /* relay unreachable — try the portal directly */
  }

  return fetchWithTimeout(directUrl, options)
}

interface SesKeyResponse {
  ses_key?: string
  sesKey?: string
  token?: string
  access_token?: string
  expires_in?: number
  expiresIn?: number
}

async function requestSesKey(path: string): Promise<string> {
  const authToken = getAuthToken()
  if (!authToken) {
    throw new AuthError('No auth token — sign in again.', 401)
  }

  const res = await fetchPortalAuth(path, {
    method: 'POST',
    headers: { Authorization: `Bearer ${authToken}` },
  })

  if (!res.ok) {
    const body = await res.text().catch(() => '')
    throw new AuthError(body || `HTTP ${res.status}`, res.status)
  }

  const data = (await res.json()) as SesKeyResponse
  const key = data.ses_key ?? data.sesKey ?? data.token ?? data.access_token
  if (!key) {
    throw new AuthError('The auth service returned no session key.', 200)
  }

  saveSession(key, data.expires_in ?? data.expiresIn ?? 900)
  return key
}

let mintInFlight: Promise<string> | null = null

/**
 * A valid ses_key, minting one from the auth_token when needed.
 *
 * Concurrent callers share one request: a burst of API calls on a cold session
 * would otherwise mint a handful of keys and keep only the last. The inbox
 * opens five panels at once, so this matters here more than it did when the
 * app had one screen.
 *
 * `force` discards the in-memory key first. It exists for exactly one caller:
 * the API client's single retry after a 401. A key can be revoked server-side
 * before it expires locally, and without `force` that retry would present the
 * same dead key and fail identically — signing the user out for something a
 * fresh mint fixes.
 *
 * There is still no `/seskey/refresh` round trip here. The key lives in memory
 * and expires on its own, so the next call mints a fresh one from the
 * long-lived auth_token — which is what a refresh would achieve.
 */
export async function ensureSesKey(force = false): Promise<string> {
  if (force) {
    clearSession()
  } else {
    const existing = getSesKey()
    if (existing) return existing
  }

  if (!mintInFlight) {
    mintInFlight = requestSesKey('/seskey').finally(() => {
      mintInFlight = null
    })
  }
  return mintInFlight
}

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

/**
 * Sign out completely.
 *
 * Local tokens go first so nothing can be replayed if the network calls fail,
 * then the portal's own session cookie is cleared by navigating to its logout
 * page. Skipping that last step leaves the portal session alive, and the next
 * visit silently signs the user back in — which reads as "logout is broken".
 */
export function performLogout(): void {
  markLogoutInProgress()
  clearRedirectGuard()
  forgetReturnRoute()

  const authToken = getAuthToken()
  const portalLogoutUrl = `${resolveLoginPortalOrigin()}/login/logout`

  clearAllTokens()

  if (authToken) {
    // Fire-and-forget: the portal invalidates the token server-side, but the
    // redirect must not wait on it.
    fetch(`${PORTAL_AUTH_API}/api/logout`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${authToken}`,
      },
      keepalive: true,
    }).catch(() => {
      /* the local session is already gone; the redirect proceeds regardless */
    })
  }

  window.location.replace(portalLogoutUrl)
}

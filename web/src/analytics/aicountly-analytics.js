/**
 * AICOUNTLY analytics — browser client. Version 1.0.0.
 *
 * COPY, DON'T EDIT: this file lives in analytics-aicountly "sdk/js/". Products copy it unchanged.
 *
 * It posts to the PRODUCT'S OWN backend (same origin), never to analytics.aicountly.org:
 *
 *     browser  ->  POST <this product>/api/v1/analytics/collect   (same origin, session cookie)
 *              ->  the backend attaches the verified company_id / user_uuid
 *              ->  AicountlyAnalytics PHP SDK  ->  spool  ->  analytics.aicountly.org
 *
 * That is the whole security design, and it is worth stating plainly: the browser never sends an
 * identity and there is no public write key anywhere in the bundle. A tenant id cannot be spoofed
 * because the browser never supplies one, and there is no cross-origin collector to attack.
 *
 * WHAT IT NEVER SENDS: no names, e-mails, amounts, search terms, document numbers, raw URLs,
 * query strings, full referrers, user-agent strings or cookies of its own. Route paths are masked
 * before they leave the page ("/invoices/8812" -> "/invoices/:id"). The session id is a random
 * value in sessionStorage that dies with the tab and rotates after 30 minutes of inactivity; it
 * is not an identifier for a person and cannot follow one across sites.
 *
 * Usage:
 *     import { analytics } from './aicountly-analytics.js'
 *     analytics.init({ app: 'books' })
 *     analytics.page(location.hash.slice(1))     // on every route change
 *     analytics.track('invoice.created', { template: 'gst_b2b', line_count: 7 })
 *
 * Event names must be registered in the Analytics tracking plan (docs/04) or the server refuses
 * them. That is deliberate: the plan comes before the instrumentation.
 */

const SESSION_KEY = 'aic_an_sid'
const SESSION_TS = 'aic_an_sts'
const SESSION_IDLE_MS = 30 * 60 * 1000
const FLUSH_INTERVAL_MS = 15000
const MAX_QUEUE = 50

/** Keys refused locally, mirroring EventContract on the server, so mistakes surface in dev. */
const SENSITIVE_PART = [
  'email', 'mail', 'phone', 'mobile', 'address', 'password', 'passwd', 'secret', 'credential',
  'aadhaar', 'aadhar', 'gstin', 'prompt', 'salary', 'invoice_no', 'filename', 'file_name',
  'username', 'user_id', 'uuid', 'recipient', 'content', 'description', 'remark', 'comment',
]
const SENSITIVE_WORD = [
  'name', 'ip', 'pan', 'otp', 'url', 'card', 'text', 'body', 'note', 'cost', 'amount', 'price',
  'balance', 'account', 'ifsc', 'query', 'search', 'message', 'subject', 'title', 'token',
  'contact', 'gst', 'dob', 'pin',
]

export function isSensitiveKey(key) {
  const k = String(key).toLowerCase()
  if (SENSITIVE_PART.some((w) => k.includes(w))) return true
  return k.split('_').some((t) => SENSITIVE_WORD.includes(t))
}

/** "/company/16/invoices/9f2c…/edit" -> "/company/:id/invoices/:id/edit" */
export function maskPath(path) {
  const clean = String(path || '/').split(/[?#]/)[0] || '/'
  return ('/' + clean.replace(/^\/+/, ''))
    .split('/')
    .map((s) => {
      if (!s || s === ':id') return s
      if (s.includes('@')) return ':id'
      if (/^\d+$/.test(s)) return ':id'
      if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s)) return ':id'
      if (/^[0-9a-f]{16,}$/i.test(s)) return ':id'
      if (s.length >= 16 && /^[A-Za-z0-9+/=_-]+$/.test(s) && (/\d/.test(s) || (/[a-z]/.test(s) && /[A-Z]/.test(s)))) return ':id'
      return s
    })
    .join('/')
}

/** Coarse buckets only — never a user-agent string, which is a fingerprint. */
function clientHints() {
  try {
    const ua = navigator.userAgent || ''
    const w = window.innerWidth || 0
    const device = w < 768 ? 'mobile' : w < 1024 ? 'tablet' : 'desktop'
    const os = /windows/i.test(ua) ? 'windows' : /mac os/i.test(ua) ? 'macos'
      : /android/i.test(ua) ? 'android' : /iphone|ipad/i.test(ua) ? 'ios'
      : /linux/i.test(ua) ? 'linux' : 'unknown'
    const browser = /edg\//i.test(ua) ? 'edge' : /chrome|crios/i.test(ua) ? 'chrome'
      : /firefox|fxios/i.test(ua) ? 'firefox' : /safari/i.test(ua) ? 'safari' : 'unknown'
    return { device, os, browser, viewport_w: Math.round(w / 100) * 100 }
  } catch {
    return {}
  }
}

/** Host only. A full referrer can carry a search term or a token in its query string. */
function referrerHost() {
  try {
    if (!document.referrer) return null
    const h = new URL(document.referrer).hostname.toLowerCase()
    return h === location.hostname ? null : h
  } catch {
    return null
  }
}

/**
 * A session id that lives in sessionStorage, dies with the tab, and rotates after 30 minutes of
 * inactivity. Not a person; not durable; not readable by anyone else.
 */
function sessionId() {
  try {
    const now = Date.now()
    const last = Number(sessionStorage.getItem(SESSION_TS) || 0)
    let sid = sessionStorage.getItem(SESSION_KEY)
    if (!sid || !last || now - last > SESSION_IDLE_MS) {
      sid = 's' + Math.random().toString(36).slice(2) + now.toString(36)
      sessionStorage.setItem(SESSION_KEY, sid)
    }
    sessionStorage.setItem(SESSION_TS, String(now))
    return sid
  } catch {
    return null // private mode, blocked storage: the events simply have no session
  }
}

class AicountlyAnalytics {
  constructor() {
    this.app = ''
    this.endpoint = '/api/v1/analytics/collect'
    this.queue = []
    this.started = false
    this.timer = null
    this.enabled = false
    this.lastPagePath = null
    this.pageEnteredAt = 0
    this.auth = null
  }

  /**
   * @param {object} o
   * @param {string} o.app              the product's catalog id, e.g. 'books'
   * @param {string} [o.endpoint]       same-origin collect path
   * @param {boolean} [o.enabled]       default: on outside localhost
   * @param {() => boolean} [o.consent] gate for surfaces that need one (the public site).
   *                                    Signed-in product telemetry is first-party service data
   *                                    and does not pass a gate here; see docs/05.
   * @param {() => (Record<string,string>|null)} [o.auth]
   *   Request headers carrying the caller's identity, for a backend that authenticates by header
   *   rather than by cookie — most of the estate sends `Authorization: Bearer <session key>`.
   *   Called at flush time, not at init, so a token refreshed mid-session is picked up. Return
   *   null or {} when there is no session: the collect endpoint then records nothing, which is
   *   the intended behaviour for a signed-out browser. Omit entirely for a cookie-session
   *   surface, where `credentials: 'same-origin'` already carries the identity.
   */
  init(o = {}) {
    if (this.started) return
    this.app = String(o.app || '')
    this.endpoint = o.endpoint || this.endpoint
    this.consent = typeof o.consent === 'function' ? o.consent : null
    this.auth = typeof o.auth === 'function' ? o.auth : null
    this.enabled = o.enabled !== undefined
      ? !!o.enabled
      : !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)
    if (!this.enabled) return
    this.started = true

    this.timer = setInterval(() => this.flush(), FLUSH_INTERVAL_MS)

    // pagehide is the only unload event that fires reliably on mobile Safari.
    addEventListener('pagehide', () => this.flush(true))
    addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') this.flush(true)
    })

    this.track('app.opened')
  }

  /** Record a route change. Masks the path, and times how long the previous one was open. */
  page(path) {
    const masked = maskPath(path || location.pathname)
    if (masked === this.lastPagePath) return
    const now = Date.now()
    if (this.lastPagePath && this.pageEnteredAt) {
      this.track('page.viewed', {}, {
        page_path: this.lastPagePath,
        duration_ms: Math.min(now - this.pageEnteredAt, 86400000),
      })
    }
    this.lastPagePath = masked
    this.pageEnteredAt = now
  }

  /**
   * Record an event. `properties` holds counts and coarse shapes only — a key that looks
   * sensitive is dropped here with a console warning, so it is caught while writing the code
   * rather than as a server rejection nobody reads.
   */
  track(event, properties = {}, extra = {}) {
    if (!this.started || !this.enabled) return
    if (this.consent && !this.consent()) return

    const props = {}
    for (const [k, v] of Object.entries(properties || {})) {
      const key = String(k).toLowerCase()
      if (isSensitiveKey(key)) {
        if (typeof console !== 'undefined') {
          console.warn(`[analytics] property "${key}" looks sensitive and was dropped from "${event}"`)
        }
        continue
      }
      if (v === null || v === undefined) continue
      if (typeof v === 'object') continue
      props[key] = typeof v === 'string' ? v.slice(0, 200) : v
    }

    this.queue.push({
      event: String(event),
      occurred_at: new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
      page_path: extra.page_path || this.lastPagePath || maskPath(location.pathname),
      session_id: sessionId(),
      referrer_host: referrerHost(),
      origin_surface: 'web',
      outcome: extra.outcome || 'success',
      duration_ms: extra.duration_ms,
      properties: props,
      client: clientHints(),
    })

    // A burst should not grow without limit, and should not wait for the timer either.
    if (this.queue.length >= MAX_QUEUE) this.flush()
  }

  /** Convenience wrappers for the universal core events (docs/04). */
  error(code) { this.track('error.shown', { error_code: String(code).slice(0, 60) }) }
  emptyState(where) { this.track('empty_state.shown', { where: String(where).slice(0, 60) }) }
  searched(resultCount) { this.track('search.performed', { result_count: Number(resultCount) || 0 }) }
  exported(format) { this.track('export.generated', { format: String(format).slice(0, 16) }) }

  /**
   * Send what is queued.
   *
   * On unload a cookie-authenticated send uses sendBeacon, which the browser delivers after the
   * page is gone where a normal fetch would be cancelled and the events lost. A
   * header-authenticated send cannot: sendBeacon carries no request headers, so the batch would
   * arrive without the Authorization header, be treated as signed out, and record nothing — the
   * failure would be silent on both sides. Those surfaces use `fetch(keepalive: true)` instead,
   * which survives unload and does carry headers. `session.ended` is the event this decides,
   * since it is only ever sent on unload.
   */
  flush(isUnload = false) {
    if (!this.queue.length) return
    const batch = this.queue.splice(0, this.queue.length)
    const body = JSON.stringify({ events: batch })

    let authHeaders = null
    if (this.auth) {
      try {
        const h = this.auth()
        if (h && typeof h === 'object' && Object.keys(h).length > 0) authHeaders = h
      } catch {
        // A throwing auth hook must not stop the page unloading.
      }
      // A header-authenticated surface with no session right now: nothing can be attributed, so
      // the batch is dropped rather than sent to be ignored.
      if (!authHeaders) return
    }

    try {
      if (isUnload && !authHeaders && navigator.sendBeacon) {
        navigator.sendBeacon(this.endpoint, new Blob([body], { type: 'application/json' }))
        return
      }
      fetch(this.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...(authHeaders || {}) },
        body,
        credentials: 'same-origin',
        keepalive: true,
      }).catch(() => {
        // Analytics must never surface an error to the user, and must never retry in a loop.
      })
    } catch {
      // Same: a failure here is invisible by design.
    }
  }
}

export const analytics = new AicountlyAnalytics()
export default analytics

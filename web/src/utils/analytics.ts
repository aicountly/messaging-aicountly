/**
 * Google Analytics 4 (gtag) for messaging.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_MESSAGING_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_MESSAGING (numeric property ID).
 *
 * Analytics is a third party: it receives route paths only — never query
 * strings, record ids or anything from the sign-in hand-off — and is not
 * loaded while the address bar still holds /auth/*, ?auth_token= or another
 * credential (gtag.js reads the URL itself).
 *
 * Consent (DPDP Act, GDPR-style), the same in every AICOUNTLY app: the shared
 * `aic_consent` cookie (./analyticsConsent) is read before anything here runs.
 * Google's Consent Mode v2 defaults go out before any `config`; ads consent is
 * never granted; a person who declined gets no tag at all. Whatever the choice,
 * GA is told route shapes only: identifiers in the path become `:id`, the page
 * title is the route's generic label, and no person, company, amount or free
 * text is ever sent.
 */

import { analyticsChoice, saveAnalyticsChoice } from './analyticsConsent'
import type { AnalyticsChoice } from './analyticsConsent'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
  }
}

export type BeforeChoice = 'cookieless' | 'none'

/**
 * What happens before the person has chosen. 'cookieless' loads the tag with
 * analytics_storage denied: Consent Mode's cookieless measurement, no GA
 * cookies. 'none' loads nothing at all until they press Allow. Counsel's call:
 * this is the one place to change it.
 */
export const ANALYTICS_BEFORE_CHOICE: BeforeChoice = 'cookieless'

/** Linked from the consent banner. */
export const PRIVACY_POLICY_URL = 'https://aicountly.com/privacy-policy'

/** The page title when a page has no generic label of its own. */
const PRODUCT_TITLE = 'Aicountly Messaging'

const GA4_ID: string =
  import.meta.env.VITE_GA4_SAAS_MESSAGING_MEASUREMENT_ID ||
  import.meta.env.VITE_GA4_MEASUREMENT_ID ||
  ''

let initialized = false
/** The last page_location sent: the next page view's referrer. */
let lastPageLocation = ''
/** The last page the app reported, sent once the tag loads if it was not loaded then. */
let lastPage: { path: string; title?: string } | null = null
/** The consent banner's subscribers, and whether "Analytics preferences" reopened it. */
const listeners = new Set<() => void>()
let preferencesOpen = false
let preferencesRequests = 0

export function getGa4MeasurementId(): string {
  return GA4_ID
}

/** True when this build reports to GA at all: only then is anybody asked. */
export function isAnalyticsConfigured(): boolean {
  return GA4_ID !== ''
}

/** How the tag loads for a choice: not at all, cookieless, or with analytics cookies. */
export function tagMode(
  choice: AnalyticsChoice,
  beforeChoice: BeforeChoice = ANALYTICS_BEFORE_CHOICE,
): 'off' | 'cookieless' | 'granted' {
  if (choice === 'granted') return 'granted'
  if (choice === 'denied' || beforeChoice === 'none') return 'off'
  return 'cookieless'
}

/** Consent Mode v2 defaults: everything denied except analytics the person allowed, and security. */
export function consentDefaults(choice: AnalyticsChoice): Record<string, string | number> {
  return {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: choice === 'granted' ? 'granted' : 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500,
  }
}

/** Every `config` carries these: no automatic page view, no Google signals, no ad personalisation. */
const CONFIG_FIELDS = {
  send_page_view: false,
  allow_google_signals: false,
  allow_ad_personalization_signals: false,
  cookie_flags: 'SameSite=Lax;Secure',
}

/** Sign-in hand-off routes carry tokens in the URL. */
export function isUntrackedRoute(pathname = ''): boolean {
  return /^\/auth(\/|$)/.test(String(pathname || ''))
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const HEX_TOKEN = /^[0-9a-f]{16,}$/i
/** 16+ base64/base64url characters with a digit among them; a route name has none. */
const OPAQUE_TOKEN = /^(?=.*\d)[A-Za-z0-9_\-+=.~]{16,}$/
/** A query or hash parameter that is a credential: auth_token, a ses_key, a one-time ticket or code. */
const CREDENTIAL_PARAM = /[?&#](?:[\w.-]*token|ses_?key|ticket|code|otp|password|secret|signature|sig)=/i

/** A path segment that names a record or a person rather than a page. */
export function isIdentifierSegment(segment: string): boolean {
  let value = String(segment || '')
  try {
    value = decodeURIComponent(value)
  } catch {
    // Not valid percent-encoding: judge it as it is.
  }
  return (
    value.includes('@') ||
    /^\d+$/.test(value) ||
    UUID.test(value) ||
    HEX_TOKEN.test(value) ||
    OPAQUE_TOKEN.test(value)
  )
}

/** Route path without query/hash, with every identifier segment replaced by `:id`. */
export function sanitizePagePath(pathname = '/'): string {
  const path = String(pathname || '/').split(/[?#]/)[0] || '/'
  return path
    .split('/')
    .map((segment) => (segment && isIdentifierSegment(segment) ? ':id' : segment))
    .join('/')
}

/** A referrer reduced to another site's origin, or to this site's sanitised path. */
export function sanitizeReferrer(referrer = ''): string {
  if (!referrer) return ''
  try {
    const url = new URL(referrer)
    if (url.origin !== window.location.origin) return `${url.protocol}//${url.host}/`
    return `${url.origin}${sanitizePagePath(url.pathname)}`
  } catch {
    return String(referrer).split(/[?#]/)[0]
  }
}

/** True while the address bar still holds the sign-in hand-off or another credential. */
export function urlCarriesCredential(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean {
  const hashRoute = String(location.hash || '').replace(/^#/, '').split(/[?#]/)[0]
  return (
    isUntrackedRoute(location.pathname) ||
    isUntrackedRoute(hashRoute) ||
    CREDENTIAL_PARAM.test(`${location.search || ''}${location.hash || ''}`)
  )
}

/** The page fields gtag.js would otherwise take from window.location, document.referrer and document.title. */
function pageContext(
  pagePath: string,
  title?: string,
): { page_location: string; page_referrer: string; page_title: string } {
  return {
    // Never window.location.href: it would carry the query string.
    page_location: `${window.location.origin}${pagePath}`,
    page_referrer: lastPageLocation || sanitizeReferrer(document.referrer),
    page_title: title || PRODUCT_TITLE,
  }
}

/** gtag.js sends nothing for this measurement id while `window['ga-disable-<ID>']` is true. */
function setGaDisabled(disabled: boolean): void {
  const flags = window as unknown as Record<string, unknown>
  flags[`ga-disable-${GA4_ID}`] = disabled
}

export function initAnalytics(): void {
  if (initialized || !GA4_ID || typeof window === 'undefined') return
  // Not yet: the app has not cleared the hand-off. trackPageView() calls back
  // here on the next route, once it has.
  if (urlCarriesCredential()) return
  const choice = analyticsChoice()
  if (choice === 'denied') setGaDisabled(true)
  if (tagMode(choice) === 'off') return
  initialized = true

  window.dataLayer = window.dataLayer || []
  // Google's snippet pushes the `arguments` object itself: gtag.js does not run
  // a plain array (what rest parameters produce) as a command, so the consent
  // defaults below would be ignored.
  window.gtag = function gtag(..._args: unknown[]) {
    // eslint-disable-next-line prefer-rest-params
    window.dataLayer.push(arguments)
  }
  window.gtag('consent', 'default', consentDefaults(choice))
  window.gtag('set', 'ads_data_redaction', true)
  window.gtag('set', 'url_passthrough', false)
  window.gtag('js', new Date())
  window.gtag('config', GA4_ID, {
    ...CONFIG_FIELDS,
    ...pageContext(sanitizePagePath(window.location.pathname)),
  })

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`
  document.head.appendChild(script)
}

export function trackPageView(path: string, title?: string): void {
  if (!GA4_ID || typeof window === 'undefined') return
  const pagePath = sanitizePagePath(path)
  if (isUntrackedRoute(pagePath)) return
  lastPage = { path, title }
  // A new page: the consent banner checks whether this one may ask.
  notifyConsentListeners()
  initAnalytics()
  if (!initialized || analyticsChoice() === 'denied') return
  const context = pageContext(pagePath, title)
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  // The config call below therefore sends nothing either: it only moves the
  // page fields that automatic events (scroll, engagement) report, so none of
  // them falls back to the raw URL or the document's title.
  window.gtag('config', GA4_ID, { ...CONFIG_FIELDS, ...context })
  window.gtag('event', 'page_view', {
    ...context,
    page_path: pagePath,
  })
  lastPageLocation = context.page_location
}

/** Location fields come from the sanitised page context only, never from a caller. */
const LOCATION_PARAMS = ['page_location', 'page_referrer', 'page_path', 'page_title']

/** A custom event. Its parameters must never name a person, company, amount or free text. */
export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!initialized || analyticsChoice() === 'denied') return
  const safeParams = Object.fromEntries(
    Object.entries(params).filter(([key]) => !LOCATION_PARAMS.includes(key)),
  )
  window.gtag('event', eventName, safeParams)
}

/**
 * The person's answer, from the consent banner. Allow lets analytics cookies
 * be set from now on (loading the tag if it was not); No thanks stops
 * measurement at once and loads no tag from the next page load on.
 */
export function setAnalyticsConsent(choice: 'granted' | 'denied'): void {
  saveAnalyticsChoice(choice)
  preferencesOpen = false
  if (GA4_ID && typeof window !== 'undefined') {
    if (choice === 'denied') {
      setGaDisabled(true)
      if (initialized) window.gtag('consent', 'update', { analytics_storage: 'denied' })
    } else {
      setGaDisabled(false)
      const wasLoaded = initialized
      initAnalytics()
      if (initialized) window.gtag('consent', 'update', { analytics_storage: 'granted' })
      // The page they are on was never counted: the tag was not loaded then.
      if (initialized && !wasLoaded && lastPage) trackPageView(lastPage.path, lastPage.title)
    }
  }
  notifyConsentListeners()
}

// ---------------------------------------------------------------------------
// The consent banner's state
// ---------------------------------------------------------------------------

function notifyConsentListeners(): void {
  for (const listener of [...listeners]) listener()
}

/** For useSyncExternalStore: called whenever the banner may need to appear or go. */
export function subscribeAnalyticsConsent(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/**
 * Whether the consent banner shows: only in a build that reports to GA, on a
 * page analytics may see, and only until the person has chosen — or when they
 * asked to change their choice.
 */
export function isConsentBannerVisible(): boolean {
  if (!GA4_ID || typeof window === 'undefined') return false
  if (urlCarriesCredential()) return false
  return preferencesOpen || analyticsChoice() === null
}

/** How many times "Analytics preferences" was opened: the banner takes focus each time. */
export function analyticsPreferencesRequests(): number {
  return preferencesRequests
}

/** "Analytics preferences": shows the banner again so the choice can be changed. */
export function openAnalyticsPreferences(): void {
  if (!GA4_ID) return
  preferencesOpen = true
  preferencesRequests += 1
  notifyConsentListeners()
}

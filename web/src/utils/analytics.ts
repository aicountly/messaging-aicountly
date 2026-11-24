/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
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
/** The consent banner's subscribers, and whether "Analytics preferences" reopened it. */
const listeners = new Set<() => void>()
let preferencesRequests = 0

export function getGa4MeasurementId(): string { return ''; }

/** True when this build reports to GA at all: only then is anybody asked. */
export function isAnalyticsConfigured(): boolean { return false; }

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

export function initAnalytics(): void {  }

export function trackPageView(path: string, title?: string): void { void [path, title];  }

/** A custom event. Its parameters must never name a person, company, amount or free text. */
export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void { void [eventName, params];  }

/**
 * The person's answer, from the consent banner. Allow lets analytics cookies
 * be set from now on (loading the tag if it was not); No thanks stops
 * measurement at once and loads no tag from the next page load on.
 */
export function setAnalyticsConsent(choice: 'granted' | 'denied'): void { void [choice];  }

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
export function isConsentBannerVisible(): boolean { return false; }

/** How many times "Analytics preferences" was opened: the banner takes focus each time. */
export function analyticsPreferencesRequests(): number {
  return preferencesRequests
}

/** "Analytics preferences": shows the banner again so the choice can be changed. */
export function openAnalyticsPreferences(): void {  }

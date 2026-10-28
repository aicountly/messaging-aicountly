/**
 * The analytics choice every AICOUNTLY app shares.
 *
 * One cookie, `aic_consent`, holding `analytics-granted` or `analytics-denied`.
 * On aicountly.com and every *.aicountly.com host it is set on .aicountly.com,
 * so a choice made in one app applies to all of them; on any other host (a
 * custom domain, localhost) it is host-only. It lasts 180 days, after which
 * the person is asked again. Missing or unreadable means no choice yet.
 *
 * Every AICOUNTLY app writes exactly this cookie: change the name, the values
 * or the attributes in all of them or in none.
 */

export const CONSENT_COOKIE = 'aic_consent'
export const CONSENT_GRANTED = 'analytics-granted'
export const CONSENT_DENIED = 'analytics-denied'
/** 180 days, in seconds. */
export const CONSENT_MAX_AGE = 15_552_000

/** What the person chose; null until they have chosen. */
export type AnalyticsChoice = 'granted' | 'denied' | null

/** `.aicountly.com` on aicountly.com and its subdomains; null (host-only) on any other host. */
export function consentCookieDomain(hostname: string): string | null {
  const host = String(hostname || '').toLowerCase().replace(/\.$/, '')
  return host === 'aicountly.com' || host.endsWith('.aicountly.com') ? '.aicountly.com' : null
}

/** The choice a cookie header records: null when it is missing, unreadable or anything else. */
export function readConsentCookie(cookieHeader: string): AnalyticsChoice {
  for (const part of String(cookieHeader || '').split(';')) {
    const at = part.indexOf('=')
    if (at === -1 || part.slice(0, at).trim() !== CONSENT_COOKIE) continue
    const value = part.slice(at + 1).trim()
    if (value === CONSENT_GRANTED) return 'granted'
    if (value === CONSENT_DENIED) return 'denied'
  }
  return null
}

/** The cookie that records `choice` on a page at `location`. */
export function consentCookie(
  choice: 'granted' | 'denied',
  location: Pick<Location, 'hostname' | 'protocol'>,
): string {
  const domain = consentCookieDomain(location.hostname)
  return [
    `${CONSENT_COOKIE}=${choice === 'granted' ? CONSENT_GRANTED : CONSENT_DENIED}`,
    ...(domain ? [`Domain=${domain}`] : []),
    'Path=/',
    `Max-Age=${CONSENT_MAX_AGE}`,
    'SameSite=Lax',
    ...(location.protocol === 'https:' ? ['Secure'] : []),
  ].join('; ')
}

/** A choice made on this page, kept for the page's lifetime even if the browser refuses the cookie. */
let madeHere: AnalyticsChoice = null

/** The person's current choice: one made on this page, else the cookie's. */
export function analyticsChoice(): AnalyticsChoice {
  if (madeHere) return madeHere
  try {
    return typeof document === 'undefined' ? null : readConsentCookie(document.cookie)
  } catch {
    return null
  }
}

/** Records a choice in the shared cookie (and for this page, should the cookie be refused). */
export function saveAnalyticsChoice(choice: 'granted' | 'denied'): void {
  madeHere = choice
  try {
    document.cookie = consentCookie(choice, window.location)
  } catch {
    // Cookies are blocked: the choice holds for this page and is asked again next visit.
  }
}

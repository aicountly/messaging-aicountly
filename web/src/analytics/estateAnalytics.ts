/**
 * Estate analytics for the Messaging SPA — AICOUNTLY's own view of how the estate is used, for
 * product, marketing and sales (analytics.aicountly.org).
 *
 * Not the consent-gated page analytics in src/utils/analytics, and not the Manage usage metering
 * the backend reports to. Three different things; this one is first-party service telemetry about
 * a signed-in session, which is why it has no consent gate
 * (analytics-aicountly docs/05_dpdp_and_privacy.md).
 *
 * Three things here are specific to this product, and are the same three in every
 * `Aicountly\Api` product:
 *
 *  1. The API is a different origin from the SPA, so the collect endpoint must be absolute.
 *  2. The API authenticates by `Authorization: Bearer <ses_key>`, not by cookie. The hook is read
 *     at every flush, never cached, because a ses_key is short-lived and refreshed underneath us.
 *     peekSesKey() is used rather than getSesKey() because the latter clears an expired key as a
 *     side effect and analytics must not change session state; when it has lapsed the hook
 *     returns null and the SDK drops the batch rather than posting something unattributable.
 *  3. Every endpoint here is company-scoped and reads its company context from the request,
 *     which the backend then verifies against Manage. The context hook supplies it per flush, so
 *     switching company mid-session is followed. What that context consists of differs by product
 *     — some carry a financial year, some have none — so the hook passes whatever this product's
 *     own scope holds. These are claims the backend checks, never an identity it trusts:
 *     AnalyticsCollectController attaches the verified one itself.
 */
import { analytics } from './aicountly-analytics.js'
import { getScope } from '../services/api'
import { getApiBaseUrl } from '../config'
import { peekSesKey } from '../auth/tokens'

let started = false

export function initEstateAnalytics(): void {
  if (started) return
  started = true
  try {
    analytics.init({
      app: 'messaging',
      endpoint: `${getApiBaseUrl()}/v1/analytics/collect`,
      auth: () => {
        const key = peekSesKey()

        return key ? { Authorization: `Bearer ${key}` } : null
      },
      context: () => {
        const scope = getScope()

        // Spread, rather than naming the fields: what a company context consists of differs
        // across this family. Billing, Sales, POS, Pay and Purchases carry a financial year;
        // Appointments, CRM and Helpdesk have none and their CompanyScope has no fy_id at all.
        // Whatever this product's scope holds is what the backend reads and verifies.
        return scope ? { ...scope } : null
      },
    })
  } catch {
    // Analytics must never stop the app booting.
  }
}

export function recordEstatePage(path: string): void {
  try {
    analytics.page(path)
  } catch {
    // Same.
  }
}

/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
import './AnalyticsConsentBanner.css'

/**
 * The analytics question every AICOUNTLY app asks, worded the same everywhere.
 *
 * A small card at the bottom-left, never a modal: nothing is blocked while it
 * waits and focus is never trapped. It appears only in a build that reports to
 * GA, on a page analytics may see, until the person answers — or when they
 * reopen it from "Analytics preferences".
 *
 * While it shows, `--aic-consent-space` on <html> is the height it takes from
 * the bottom of the window, so a page can leave that much room at its end and
 * nothing is left stuck under the card.
 */
export function AnalyticsConsentBanner() { return null; }

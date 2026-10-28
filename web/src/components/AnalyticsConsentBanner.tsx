import { useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react'

import {
  PRIVACY_POLICY_URL,
  analyticsPreferencesRequests,
  isConsentBannerVisible,
  setAnalyticsConsent,
  subscribeAnalyticsConsent,
} from '../utils/analytics'
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
export function AnalyticsConsentBanner() {
  // This app never renders on a server; the same snapshot there lets a test render it.
  const visible = useSyncExternalStore(subscribeAnalyticsConsent, isConsentBannerVisible, isConsentBannerVisible)
  const requests = useSyncExternalStore(subscribeAnalyticsConsent, analyticsPreferencesRequests, analyticsPreferencesRequests)
  const card = useRef<HTMLDivElement>(null)
  const allow = useRef<HTMLButtonElement>(null)

  // Reopened from a menu: move focus here, or a keyboard user is left on a
  // control that has just disappeared.
  useEffect(() => {
    if (visible && requests > 0) allow.current?.focus()
  }, [visible, requests])

  useLayoutEffect(() => {
    const element = card.current
    if (!visible || !element) return
    const root = document.documentElement
    const publish = () => {
      const space = window.innerHeight - element.getBoundingClientRect().top + 16
      root.style.setProperty('--aic-consent-space', `${Math.max(0, Math.ceil(space))}px`)
    }
    publish()
    // The card grows with its text, and moves up when Connect's launcher arrives.
    const resized = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(publish)
    resized?.observe(element)
    const mounted = new MutationObserver(publish)
    mounted.observe(document.body, { childList: true })
    window.addEventListener('resize', publish)
    return () => {
      resized?.disconnect()
      mounted.disconnect()
      window.removeEventListener('resize', publish)
      root.style.removeProperty('--aic-consent-space')
    }
  }, [visible])

  if (!visible) return null

  return (
    <div ref={card} className="aic-consent" role="region" aria-label="Analytics cookies">
      <p className="aic-consent__text">
        AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that
        identifies you. Allow analytics cookies?
      </p>
      <div className="aic-consent__actions">
        <button
          ref={allow}
          type="button"
          className="aic-consent__button aic-consent__button--primary"
          onClick={() => setAnalyticsConsent('granted')}
        >
          Allow
        </button>
        <button type="button" className="aic-consent__button" onClick={() => setAnalyticsConsent('denied')}>
          No thanks
        </button>
        <a className="aic-consent__link" href={PRIVACY_POLICY_URL} target="_blank" rel="noopener noreferrer">
          Privacy policy
        </a>
      </div>
    </div>
  )
}

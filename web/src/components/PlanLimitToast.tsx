import { useEffect, useState } from 'react'
import { Notice } from '../ui'
import { PLAN_LIMIT_EVENT, managePlansUrl, planLimitMessage } from '../services/planLimit'
import type { PlanLimitDetails } from '../services/planLimit'

const SHOWN_MS = 10_000

/**
 * The plan-limit toast (CONTRACTS §3.6): shown when the API refuses a write with 402
 * PLAN_LIMIT_REACHED, with the sentence naming the limit and a link to Manage's Plans & billing,
 * where the account owner changes the plan. Web only; nothing here blocks a request.
 */
export function PlanLimitToast() {
  const [details, setDetails] = useState<PlanLimitDetails | null>(null)

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined
    const onLimit = (event: Event) => {
      setDetails((event as CustomEvent<PlanLimitDetails>).detail ?? null)
      clearTimeout(timer)
      timer = setTimeout(() => setDetails(null), SHOWN_MS)
    }
    window.addEventListener(PLAN_LIMIT_EVENT, onLimit)
    return () => {
      window.removeEventListener(PLAN_LIMIT_EVENT, onLimit)
      clearTimeout(timer)
    }
  }, [])

  if (!details) return null

  return (
    <div
      className="msg-ui"
      data-testid="plan-limit-toast"
      role="alert"
      style={{ position: 'fixed', right: '1rem', bottom: '1rem', zIndex: 1000, maxWidth: '26rem', boxShadow: '0 8px 24px rgba(0,0,0,0.18)' }}
    >
      <Notice tone="warning" title="Plan limit reached">
        {planLimitMessage(details)}{' '}
        <a href={managePlansUrl()} target="_blank" rel="noopener noreferrer">
          Plans &amp; billing in Manage
        </a>
      </Notice>
    </div>
  )
}

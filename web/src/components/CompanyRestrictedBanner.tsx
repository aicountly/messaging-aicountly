import { useEffect, useRef, useState } from 'react'
import { Notice } from '../ui'
import {
  COMPANY_RESTRICTED_EVENT,
  formatRestrictedUntil,
  getCompanyRestriction,
} from '../services/companyRestriction'
import type { CompanyRestriction, CompanyRestrictionEventDetail } from '../services/companyRestriction'

/** One toast per company per this many ms, however many writes a screen tries. */
const TOAST_EVERY_MS = 30_000
const TOAST_SHOWN_MS = 8_000

/**
 * The read-only notice for a company whose Messaging subscription lapsed (AICOUNTLY Manage's company
 * lifecycle).
 *
 * Shown above every screen of the company while the API says it is `restricted` / `purge_pending` /
 * `purged` (from Manage's companyinfo), or once the API has refused a write with 423
 * COMPANY_RESTRICTED. Conversations, reports and exports keep working, so the notice says so. A
 * refused write also raises a toast with the server's sentence, so a screen that does not show its own
 * error still explains why nothing was saved.
 */
export function CompanyRestrictedBanner({ cmpId }: { cmpId: number | null }) {
  const [restriction, setRestriction] = useState<CompanyRestriction | null>(() => getCompanyRestriction(cmpId))
  const [toast, setToast] = useState<string | null>(null)
  const lastToast = useRef(0)

  useEffect(() => {
    setRestriction(getCompanyRestriction(cmpId))
    let timer: ReturnType<typeof setTimeout> | undefined
    const onChange = (event: Event) => {
      setRestriction(getCompanyRestriction(cmpId))
      const detail = (event as CustomEvent<CompanyRestrictionEventDetail>).detail
      if (detail?.restricted && detail.cmpId === cmpId && detail.source === 'api' && detail.message && Date.now() - lastToast.current > TOAST_EVERY_MS) {
        lastToast.current = Date.now()
        setToast(detail.message)
        clearTimeout(timer)
        timer = setTimeout(() => setToast(null), TOAST_SHOWN_MS)
      }
    }
    window.addEventListener(COMPANY_RESTRICTED_EVENT, onChange)
    return () => {
      window.removeEventListener(COMPANY_RESTRICTED_EVENT, onChange)
      clearTimeout(timer)
    }
  }, [cmpId])

  if (!restriction) return null

  const until = formatRestrictedUntil(restriction.restrictedUntil)
  const title =
    restriction.lifecycle === 'purged'
      ? 'Read-only — this company’s Messaging data was deleted'
      : `${until ? `Read-only until ${until}` : 'Read-only'}${restriction.lifecycle === 'purge_pending' ? ' — scheduled for removal' : ''}`

  return (
    <div className="msg-ui" data-testid="company-restricted-banner">
      <Notice tone="warning" title={title}>
        {restriction.message} Conversations, reports and exports keep working
        {until && restriction.lifecycle === 'restricted' ? ` until ${until}` : ''}; nothing can be sent or changed.
      </Notice>
      {toast && (
        <div
          role="alert"
          style={{
            position: 'fixed',
            right: '1rem',
            bottom: '1rem',
            zIndex: 1000,
            maxWidth: '26rem',
            boxShadow: '0 8px 24px rgba(0,0,0,0.18)',
          }}
        >
          <Notice tone="danger" title="Not saved">
            {toast}
          </Notice>
        </div>
      )}
    </div>
  )
}

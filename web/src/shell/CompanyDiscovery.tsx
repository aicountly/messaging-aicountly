/**
 * Company discovery from the AICOUNTLY company registry (Manage).
 *
 * "Browse all workspace companies" lists every company in the person's
 * workspaces with their access in Messaging. Choosing one they may open selects
 * it through the same selectCompany the picker uses (the API re-checks it with
 * Manage); a restricted one opens "Request access" instead, never the company.
 * Discovery is not access.
 */

import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { Building2 } from 'lucide-react'
import { CompanyDirectoryPicker, RestrictedCompanyDialog } from '../company-access/index.ts'
import type { DirectoryCompany } from '../company-access/index.ts'
import { companyId, companyName, useMessaging } from '../context/MessagingContext'
import { companyRegistry, MESSAGING_APP_NAME } from '../services/companyRegistry'
import type { Company } from '../services/types'
import { Button, Drawer } from '../ui'

export function BrowseCompaniesButton({ label = 'Browse all workspace companies', compact = false }: { label?: string; compact?: boolean }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button tone="ghost" small onClick={() => setOpen(true)} title={label} ariaLabel={label}>
        <Building2 size={14} aria-hidden />
        {!compact && <span>{label}</span>}
      </Button>
      {open && <CompanyDirectoryDrawer onClose={() => setOpen(false)} />}
    </>
  )
}

function CompanyDirectoryDrawer({ onClose }: { onClose: () => void }) {
  const { cmpId, companies, selectCompany, reload } = useMessaging()
  // Portalled: the button sits in the top bar, whose stacking context is not the page's.
  return createPortal(
    <Drawer
      title="Workspace companies"
      subtitle="Every company registered in your Aicountly workspaces. A restricted one already exists — ask its owner for access instead of creating it again."
      onClose={onClose}
    >
      <CompanyDirectoryPicker
        client={companyRegistry}
        appName={MESSAGING_APP_NAME}
        title={null}
        selectedCmpId={cmpId > 0 ? cmpId : null}
        onOpen={(company) => {
          onClose()
          selectCompany(company.cmp_id)
          // Access granted since the list loaded: read the list again so the picker shows it.
          if (!companies.some((row) => companyId(row) === company.cmp_id && row.can_open_in_current_app !== false)) reload()
        }}
      />
    </Drawer>,
    document.body,
  )
}

/** A row of our own company list in the registry's shape, for when Manage's summary cannot be read. */
function fromListRow(company: Company): DirectoryCompany {
  const id = companyId(company)
  return {
    canonical_company_id: id,
    cmp_id: id,
    legal_name: companyName(company),
    display_name: companyName(company),
    company_code: '',
    company_status: 'active',
    relationship: 'member',
    workspace: null,
    is_owner: false,
    is_discoverable: true,
    workspace_visibility: null,
    app: 'messaging',
    access_status: (company.app_access_status as DirectoryCompany['access_status'] | undefined) ?? 'ACCESS_RESTRICTED',
    can_open_in_current_app: false,
    can_request_access: true,
    can_manage_access: false,
    request: null,
    entitlement_version: 0,
  }
}

/** "Company Access Restricted" for a company Manage lists but does not open to this person in Messaging. */
export function RestrictedCompanyNotice({ company, onClose }: { company: Company; onClose: () => void }) {
  const [row, setRow] = useState<DirectoryCompany | null>(null)
  useEffect(() => {
    let cancelled = false
    companyRegistry
      .summary(companyId(company))
      .then((summary) => {
        if (!cancelled) setRow(summary)
      })
      .catch(() => {
        if (!cancelled) setRow(fromListRow(company))
      })
    return () => {
      cancelled = true
    }
  }, [company])
  if (row === null) return null
  return createPortal(
    <RestrictedCompanyDialog client={companyRegistry} company={row} appName={MESSAGING_APP_NAME} onClose={onClose} />,
    document.body,
  )
}

/** "Request access" to the company in scope, after Manage refused it for Messaging (COMPANY_ACCESS_REQUIRED). */
export function RequestAccessButton() {
  const { cmpId, companies } = useMessaging()
  const [open, setOpen] = useState(false)
  const company = useMemo<Company>(() => companies.find((row) => companyId(row) === cmpId) ?? { cmp_id: cmpId }, [companies, cmpId])
  if (cmpId <= 0) return null
  return (
    <>
      <Button tone="primary" small onClick={() => setOpen(true)}>
        Request access
      </Button>
      {open && <RestrictedCompanyNotice company={company} onClose={() => setOpen(false)} />}
    </>
  )
}

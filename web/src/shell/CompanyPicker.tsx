/**
 * The company/branch context selector.
 *
 * The list comes from Aicountly Manage, live. When Manage cannot be reached
 * the picker is disabled and says so, rather than offering a remembered list
 * that might include a company the user has since lost access to.
 *
 * A company Manage lists as in the person's workspace but not open to them in
 * Messaging (can_open_in_current_app === false) is shown, never entered:
 * choosing it explains and offers "Request access". "Browse all workspace
 * companies" opens the registry's directory (discovery is not access).
 */

import { useState } from 'react'
import { accessStatusLabel } from '../company-access/index.ts'
import { companyId, companyName, useMessaging } from '../context/MessagingContext'
import { BrowseCompaniesButton, RestrictedCompanyNotice } from './CompanyDiscovery'

export function CompanyPicker() {
  const { companies, companiesError, cmpId, selectCompany, isRestricted } = useMessaging()
  const [restrictedId, setRestrictedId] = useState<number | null>(null)
  const restricted = restrictedId === null ? null : companies.find((company) => companyId(company) === restrictedId) ?? null

  if (companiesError !== null && companies.length === 0) {
    return (
      <span className="msg-status msg-status-warning" title={companiesError}>
        Companies unavailable
      </span>
    )
  }

  if (companies.length <= 1) {
    const only = companies[0]
    return (
      <>
        {only && !isRestricted(companyId(only)) ? (
          <span className="shell-company" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
            {companyName(only)}
            <span className="shell-company-id" title={`Company ID ${companyId(only)}`}>
              ID: {companyId(only)}
            </span>
          </span>
        ) : null}
        <BrowseCompaniesButton compact />
      </>
    )
  }

  return (
    <>
      <label className="msg-visually-hidden" htmlFor="shell-company">
        Company
      </label>
      <select
        id="shell-company"
        className="shell-company"
        value={cmpId > 0 ? String(cmpId) : ''}
        onChange={(event) => {
          const next = Number.parseInt(event.target.value, 10)
          if (isRestricted(next)) {
            setRestrictedId(next)
            return
          }
          selectCompany(next)
        }}
      >
        {companies.map((company) => (
          <option key={companyId(company)} value={String(companyId(company))}>
            {isRestricted(companyId(company))
              ? `${companyName(company)} (ID: ${companyId(company)}) — ${accessStatusLabel(company.app_access_status ?? 'ACCESS_RESTRICTED')}`
              : `${companyName(company)} (ID: ${companyId(company)})`}
          </option>
        ))}
      </select>
      <BrowseCompaniesButton compact />
      {restricted && <RestrictedCompanyNotice company={restricted} onClose={() => setRestrictedId(null)} />}
    </>
  )
}

/**
 * The company/branch context selector.
 *
 * The list comes from Aicountly Manage, live. When Manage cannot be reached
 * the picker is disabled and says so, rather than offering a remembered list
 * that might include a company the user has since lost access to.
 */

import { companyId, companyName, useMessaging } from '../context/MessagingContext'

export function CompanyPicker() {
  const { companies, companiesError, cmpId, selectCompany } = useMessaging()

  if (companiesError !== null && companies.length === 0) {
    return (
      <span className="msg-status msg-status-warning" title={companiesError}>
        Companies unavailable
      </span>
    )
  }

  if (companies.length <= 1) {
    const only = companies[0]
    return only ? (
      <span className="shell-company" style={{ display: 'inline-flex', alignItems: 'center' }}>
        {companyName(only)}
      </span>
    ) : null
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
        onChange={(event) => selectCompany(Number.parseInt(event.target.value, 10))}
      >
        {companies.map((company) => (
          <option key={companyId(company)} value={String(companyId(company))}>
            {companyName(company)}
          </option>
        ))}
      </select>
    </>
  )
}

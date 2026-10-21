import { createCompanyRegistryClient } from '../company-access/index.ts'
import { ensureSesKey } from '../auth/portal'
import { getApiBaseUrl } from '../config'

/**
 * The AICOUNTLY company registry (Manage /api/v1/*) as Messaging sees it.
 *
 * Discovery and Users & Access reach Manage through this product's own API
 * (`/api/manage/v1/*`, server-php/src/ManageRelay.php), with the person's own
 * ses_key (the one credential this product's API also takes). Not directly:
 * the hosting's bot shield answers some networks (common Indian broadband)
 * with a challenge page that has no CORS headers, so a cross-site call to
 * manage.aicountly.com failed with no response, while a same-origin call
 * passes. The server forwards the call unchanged and grants nothing. Whether a
 * company may be opened here is still decided by the Messaging API, which
 * asks Manage companyinfo naming Messaging on every scoped request.
 */
export const MESSAGING_APP_KEY = 'messaging'
export const MESSAGING_APP_NAME = 'Aicountly Messaging'

export const companyRegistry = createCompanyRegistryClient({
  appKey: MESSAGING_APP_KEY,
  baseUrl: `${getApiBaseUrl()}/manage`,
  getSesKey: () => ensureSesKey(),
})

import { createCompanyRegistryClient } from '../company-access/index.ts'
import { ensureSesKey } from '../auth/portal'
import { getManageApiOrigin } from './appLauncher'

/**
 * The AICOUNTLY company registry (Manage /api/v1/*) as Messaging sees it.
 *
 * Discovery and Users & Access talk to Manage directly with the person's own
 * ses_key (the one credential this product's API also takes). Whether a
 * company may be opened here is still decided by the Messaging API, which
 * asks Manage companyinfo naming Messaging on every scoped request.
 */
export const MESSAGING_APP_KEY = 'messaging'
export const MESSAGING_APP_NAME = 'Aicountly Messaging'

export const companyRegistry = createCompanyRegistryClient({
  appKey: MESSAGING_APP_KEY,
  baseUrl: `${getManageApiOrigin()}/api`,
  getSesKey: () => ensureSesKey(),
})

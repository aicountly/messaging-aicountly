/**
 * Settings → Users & Access, native in Messaging.
 *
 * Who may OPEN this company in Messaging, from the one AICOUNTLY company
 * registry in Manage: the verified company owner grants, changes and revokes
 * it here; everyone else sees their own access and can ask for it. No redirect
 * to Manage. What people may DO inside Messaging stays with the permission
 * profiles under Settings → Access, which remain authoritative.
 */

import { Link } from 'react-router-dom'
import { CompanyUsersAccessPanel, MyWorkspacesPanel } from '../company-access/index.ts'
import { useMessaging } from '../context/MessagingContext'
import { companyRegistry, MESSAGING_APP_NAME } from '../services/companyRegistry'
import { Notice, Panel } from '../ui'

export function UsersAccessPage() {
  const { cmpId, can } = useMessaging()

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Users &amp; Access</h1>
          <p>Who may open this company in Messaging. Seeing a company in your workspace is not access to it.</p>
        </div>
      </div>

      <div className="msg-stack">
        <Notice tone="info">
          Only the company owner can grant or revoke access to the company. What each person may do inside Messaging is
          set by their permission profile{can('messaging.access.manage') ? <> under <Link to="/settings?tab=access">Settings → Access</Link></> : ' (Settings → Access)'}.
        </Notice>

        {cmpId > 0 && (
          <Panel>
            <CompanyUsersAccessPanel key={cmpId} client={companyRegistry} cmpId={cmpId} appName={MESSAGING_APP_NAME} />
          </Panel>
        )}

        <Panel title="My workspaces" subtitle="Accept an invitation to discover a workspace's companies; leave one you no longer belong to.">
          <MyWorkspacesPanel client={companyRegistry} />
        </Panel>
      </div>
    </div>
  )
}

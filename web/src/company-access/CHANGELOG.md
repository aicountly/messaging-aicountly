# Changelog — AICOUNTLY shared company access

Copy the whole folder into a product (`web/src/company-access/`); `VERSION` says which release a
product carries. Newest first.

## 1.4.0 — 2026-10-29

One set of team permissions for several people at once (owner request, 2026-10-29: "a single profile to either all
workspace users, or different profiles to my choice"). Per-person actions are unchanged. No API change and no new
route: relays need nothing new.

- `<WorkspaceManager>`, Workspace team: a checkbox per person ("Select <name>") and "Select all" (mixed while only some
  are chosen). "Set permissions for N selected" opens the permissions form once; applying it gives each of them exactly
  those team permissions — it replaces theirs, and the confirmation says so. When everyone selected holds the same
  permissions the form starts from them; otherwise it starts empty and says that theirs differ.
- Company users: the same selection; "Move N selected to Workspace team" opens the form once and moves them all with
  those permissions, with the usual line on how many companies they will open.
- Each person is changed by the call the per-person action makes (`updateMemberSettings`, `moveMember(…, 'team',
  settings)`), one after another, so each change is validated, checked against the owner's plan and audited as before.
  Progress is shown; the end says how many were changed and, for anyone who was not, why — a plan with no seat left
  names the company and the app in plain words. They stay selected, with the form open, to retry.
- Shown on the owner's own workspace only (`workspace.is_owner`), and only with the role catalogue (`v1/app-roles`).

## 1.3.1 — 2026-10-29

The styles ship as a static stylesheet. No visual change and no API change.

- **Host: import it once in the app entry, after the product's own CSS — `import './company-access/company-access.css';`.**
  The bundler serves it as a same-origin CSS file, which a Content-Security-Policy without `'unsafe-inline'`
  (`style-src 'self'`) allows. Until now the components only injected a `<style>` element at run time, which such
  a policy refuses — Integrate's production Settings → Users & Access rendered unstyled.
- `injectCompanyAccessStyles()` is now the fallback for a product that has not imported it: it injects nothing while
  the stylesheet's marker (`--aic-ca-stylesheet` on `:root`) is applied. The rules are the same as before, line for
  line, in `company-access.css` and `styles.ts`.

## 1.3.0 — 2026-10-28

Workspace team and Company users (Manage migration 026; owner decision 2026-10-28). Against an older Manage
every component behaves as in 1.2.x (no Company users room; everyone listed is the team).

- The model: the **Workspace team** opens every company of the workspace (now and later, except companies the
  owner hides) with its team permissions; **Company users** open only the companies shared with them and see
  nothing else. Sharing a company with someone who is not on the team makes them a Company user — before, it
  enrolled them in the workspace, where they discovered every company.
- Client: `companyUsers(workspaceId)`, `moveMember(workspaceId, uuid, 'team' | 'company_users', settings?)`,
  `removeFromCompany(cmpId, uuid)`; `removeMember()` now sends no `revoke_company_access` unless one is passed —
  removing ends ALL of the person's access in the workspace (an invitation is only withdrawn).
- Types: `WorkspaceGroup`, `DirectCompany`, `CompanyUser`, `MoveMemberResult`, `RemoveMemberResult`,
  `RemoveFromCompanyResult`; `WorkspaceMember.member_group / direct_companies`, `Workspace.my_group /
  team_member_count / company_user_count / team_company_count`, `PermissionMember.workspace_group / workspace_team /
  workspace_role_key / app_access_via`, `DirectoryCompany.workspace_team`.
- `<WorkspaceManager>`: two sections, Workspace team and Company users; "Move to Company users" lists, before
  confirming, the companies they keep (none = they lose access); "Move to Workspace team" shows how many companies
  they will open and their app permissions; Remove / Withdraw invitation / Remove from one company / Remove from
  all say exactly what ends. Inline confirmations (no `window.confirm`).
- `<CompanyUsersAccessPanel>`: says when access comes through the Workspace team (no Revoke offered for it), and a
  revoke of a grant held by a team member says they keep the app through the team. "Open to my Workspace team"
  replaces "Visible to my workspace".
- Copy: settings summaries read "every company in …"; the workspaces panel names the team and Company users.
- Relays: add `GET v1/workspaces/{id}/company-users`, `POST v1/workspaces/{id}/members/{uuid}/move` and
  `DELETE v1/companies/{id}/members/{uuid}`.

## 1.2.0 — 2026-10-23

Workspace sharing settings and company creation in a workspace (CONTRACTS §1, §2). Additive: every
1.1.x call and component behaves as before against a newer or older Manage.

- Client: `appRoles()`, `creationTargets()`, `updateMemberSettings()`, `retryCreatorAccess()`,
  `pendingCounts()`; `inviteMember()` accepts `settings`. Helpers `fetchCreationTargets(client)`,
  `fetchAppRoles(client)`, `createCompanyRequestFields(target, creatorAccess)` (→ `{workspace_id,
  creator_access}`), `emptyWorkspaceSettings()`.
- Types: `WorkspaceSettings`, `AppRoles`, `AppRole`, `CreationTarget`, `CreatorAccessChoice`,
  `CreatorAccessResult`, `PendingCounts`; `WorkspaceMember.settings`, `Workspace.my_settings`,
  `Entitlement.granted_via/seat_status`, `DirectoryCompany.access_via/workspace_role_key`.
- Creation targets: `reason` may also be `'plan_limit_reached'` (the owner's plan `max_companies` is used
  up; `companies_remaining` = min(member cap, owner plan) — integration of PlanGate); the picker explains it.
- Components: `<WorkspaceTargetPicker>`, `<CreatorAccessFields>`, `<WorkspaceMemberSettingsForm>`;
  `workspaceSettingsSummary()`, `appLabel()`.
- `<WorkspaceManager>`: per-member Settings (edit) and "Sharing settings…" on invite (hidden when the
  server has no `v1/app-roles`). `<MyWorkspacesPanel>`: shows what the owner lets you do.
- Copy: a missing own workspace no longer says "created when you register your first company"
  (Manage now creates it on first use).

## 1.1.1

Workspace rename, members with names and e-mails, suggestions, one workspace per owner.

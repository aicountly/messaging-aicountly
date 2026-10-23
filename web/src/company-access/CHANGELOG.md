# Changelog — AICOUNTLY shared company access

Copy the whole folder into a product (`web/src/company-access/`); `VERSION` says which release a
product carries. Newest first.

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

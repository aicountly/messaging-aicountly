# Changelog — AICOUNTLY shared company access

Copy the whole folder into a product (`web/src/company-access/`); `VERSION` says which release a
product carries. Newest first.

## 1.8.0 — 2026-11-21

One role order, one control for every row (owner report, 2026-11-21: "why for some users it is showing to change
settings in manage -> workspace instead of default, since default is what we set in manage -> workspace").

Before, a Workspace team member with access only through the team showed "Change it in the Workspace team's settings";
anyone holding a share showed a picker whose **Default** meant the *application's lowest role*, not the Workspace
setting — and a team member who had also been shared the company silently lost their Workspace team role for it.

- **The order** (Manage, `decide()`; applied by companyinfo, the panel and the seat calls): the role set for **this
  company** → else the person's **Workspace team role** (Manage → Workspace, every company) → else the
  application's **default**.
- `<CompanyUsersAccessPanel>`: every non-owner row with access has the same role picker. Its first option names what
  they inherit — **Workspace team role (Administrator)** or **Default (Viewer)** — and the Access column says where
  the role comes from (*set for this company* / *from the Workspace team* / *default*; in Books, which names no
  default role in Manage, *Books profile: …* — the profile the share gave).
  - Team-only member: choosing a role grants it for this company only (their other companies keep the team role);
    "For every company: Manage → Workspace" points to where the team role is changed.
  - Someone with a grant here: choosing the first option clears the company role and they go back to the inherited
    one (Manage `PATCH …/permissions/{ref}` with `role_key: null`; it used to answer 422).
  - Revoking the grant here from a team member says they keep the app with their Workspace team role.
- Client types: `PermissionMember.role_source` / `company_role_key` / `effective_role_key`, `RoleSource`,
  `CompanyPermissions.default_role_key` — all optional; an older Manage sends none and the panel reads what it can.
- **Needs Manage with contract `company-roles.v1`** for the order, the role note and clearing a role. No relay change:
  the panel uses the routes it already used (`POST`/`PATCH`/`DELETE v1/companies/{id}/permissions…`).
- Manage now also applies a role change **in the product** (the same seat call a grant makes) and, when a grant here
  is revoked from someone the team still gives the app, moves their product seat to the team role instead of revoking
  it.

## 1.7.0 — 2026-11-21

"Add user" finds anyone (owner request, 2026-11-21: "add user using email / uuid / mobile … show with details of user to
invite … handle multiple accounts … if user is not registered, it will show user not registered with invite to send").

- `<CompanyUsersAccessPanel>` → Add user → **Find by** E-mail / Mobile / Account ID. The answer shows who it is (name,
  e-mail, mobile, ID — `PersonIdentity`), how they are tied to the company, their status here, and the one thing to do:
  - **Grant** (in the company or the owner's workspace): role, optional end date — the existing `grant()`.
  - **Send invitation** (registered, not tied yet): role — they get access once they accept it.
  - nothing to do (already has access, already invited, the owner) — with the reason.
  - **Several accounts on one e-mail or mobile** are listed side by side (one radio each, named by account ID and name)
    to choose from, instead of the look-up finding nobody.
  - **Not registered**: "No AICOUNTLY account uses this …" and **Send invitation** to sign up; for a mobile, the
    e-mail the invitation goes to is asked for.
  - **Account ID** is looked up among the people tied to the owner only (the directory is never walked by id); an
    unknown one says to use e-mail or mobile.
- The 1.6.0 list of the workspace's restricted people stays below it ("Or choose from …"). Add user is no longer
  disabled when that list is empty.
- Client: `lookupPerson(cmpId, {kind, value})` → `POST v1/companies/{id}/people/lookup`;
  `invitePerson(cmpId, {uuid | email, mobile?, role_key?})` → `POST v1/companies/{id}/people/invite`. Types
  `PersonLookupKind`, `PersonMatch`, `PersonLookupResult`.
- **Needs Manage with contract `company-people.v1`** (and my.aicountly `userinfo/lookup … candidates=1` for several
  accounts on one e-mail or mobile). An older Manage answers the look-up 404: the panel says so and the list still works.
- **Relays:** a product that relays the client's calls through an allow-list must add
  `POST v1/companies/{id}/people/lookup` and `POST v1/companies/{id}/people/invite`.

## 1.6.0 — 2026-11-21

Users & Access lists the people who have access, and adds the rest from a picker (owner request, 2026-11-21: "show
only those users who have access … and option to add user to grant access"). The owner's table used to hold every
Workspace team member and Company user, most of them "Access restricted" with a role, a date and a Grant button each.

- `<CompanyUsersAccessPanel>`, People & access (owner): the table lists everyone whose status in THIS application is not
  `ACCESS_RESTRICTED` — the owner, people granted (directly or through the Workspace team), and those whose access
  expired, was revoked or is requested, who keep their Grant / Revoke controls as before.
- **Add user** opens a picker of the people who are `ACCESS_RESTRICTED` here: search by name, e-mail or mobile, choose
  one, the role (Default role when the product offers none) and an optional end date, Grant. On success the panel
  reloads and they move into the table; on a refusal the picker stays open with the error. Disabled, with a reason,
  when nobody is left to add.
- A table with nobody in it says so and points to Add user.
- Display only: the same `permissions()` answer split in two, the same `grant()` call. No new route, no API change,
  no host change.

## 1.5.0 — 2026-11-13

Who somebody is, said the same way on every screen that lists people — Books' team list
(`modules/access/TeamMembersPage.jsx`) is the shape: name, e-mail, mobile and the account id, all four,
each on a labelled line of its own.

The rule that comes with it is the point. **A field that is missing says so.** It is never quietly
replaced by another field and never dropped from the row. These screens used to print whichever field
happened to be there — so a person the registry had no name for was listed as "User #4243", which reads
like an account called that — and then showed the e-mail ONLY when a name was there too, so the row that
needed the address most was the one that hid it.

- New `<PersonIdentity person={…} />` draws the four labelled fields; anything the registry has not been
  told reads "Not Available", quietly, never emphasised. New `formatPersonDisplay()` behind it, ported
  from Books field for field, including Books' correction for an address filed as a name (a share made by
  e-mail before the directory knew one) — it is read back onto the e-mail line instead of standing in as
  a name.
- `<CompanyUsersAccessPanel>` people table and `<WorkspaceManager>`'s Workspace team, Company users and
  suggestion lists all draw their rows with it.
- `PermissionMember.mobile`, `WorkspaceMember.mobile` and `MemberSuggestion.mobile`, all optional: a
  registry older than this sends none and the row says the mobile is not available.
- `CompanyPermissions.viewer_uuid`, optional: who is asking, so the people table marks their own row
  "(you)" as the sharing dialogs do. Absent from an older registry and the row is simply not marked.
- `personName()` stays, for a person's name in a SENTENCE — a toast, a confirmation, an aria-label, where
  "Not Available was invited to your workspace" would be nonsense. `personSecondary()` is deprecated: it
  is the rule that hid the address.
- Styles: `.aic-ca-identity`, `.aic-ca-detail`, `.aic-ca-detail-label`, `.aic-ca-detail-value`,
  `.aic-ca-detail-strong`, `.aic-ca-detail-missing`.

Needs no new route and no API change; a relay passes the mobile through if its registry sends one.

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

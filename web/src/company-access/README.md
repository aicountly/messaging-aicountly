# AICOUNTLY shared company access (v1.2.0)

One company directory, one access model and one set of screens for every AICOUNTLY product.
The authority is Manage's company registry (`/api/v1/*`, contract in
`../company-registry.openapi.yaml`). This folder is the browser side: a typed client and React
components that products **copy** (like `../app-launcher/`), so they render natively inside
each product while every change goes to the same central API.

> **Copy, don't edit.** Copy this whole folder to `web/src/company-access/` in the product.
> Change it here and copy it again. Manage's own copy is checked byte-for-byte by
> `server-php/tests/company_access_package_drift_test.php`.

TypeScript sources (`.ts` / `.tsx`); Vite compiles them in JavaScript products too. No
dependencies beyond React 18/19. Styles are injected once (`aic-ca-*` classes, theme `#25b003`,
overridable through the CSS variables in `styles.ts`).

## The two rules

1. **Discovery is not access.** `directory()` lists every company the person may *see*: those
   they own, those shared with them, and those of every workspace they actively belong to — each
   with `access_status` for *this* application. A restricted company is listed (never hidden), so
   nobody concludes it does not exist and registers it again. Choosing it opens
   `RestrictedCompanyDialog` ("Request access"), never the company.
2. **Only the server decides.** The UI is a convenience. Every product must keep checking the
   company on every scoped request through Manage `GET /api/companyinfo` **naming itself**
   (`?app=<key>`, or the `X-Saas-Origin` header most products already send). Manage then answers
   **403 `COMPANY_ACCESS_REQUIRED`** for a member who may see the company but not open it in that
   product. Existing members (shared before per-application access) hold every product.

## Application keys

Manage catalog ids: `books`, `inventory`, `sales`, `purchases`, `billing`, `pos`, `pay`,
`contacts`, `insights`, `capital`, `valuations`, `benefits`, `contracts`, `hrms`, `calendar`,
`appointments`, `notes`, `docs` (Drive), `vault`, `chat` (Connect), `crm`, `helpdesk`, `remote`,
`messaging`, `voice`, `email`, `receptionist` (Lobby), `fr`, `secretarial`, `advisor`, `edge`,
`aspire`, `integrate`, `apis`, `buddy` (Pulse). Aliases accepted: `drive`, `connect`, `pulse`,
`lobby`, `purchase`. Auditor is not part of this (its own seat leg).

## 1. Backend — name the product to Manage (required)

Wherever the product's PHP asks Manage `companyinfo` (its company-access gate), add the product's
key as a query parameter **or** send `X-Saas-Origin: <key>`:

```php
// before:  GET {manage}/api/companyinfo?comp_id=42
// after:   GET {manage}/api/companyinfo?comp_id=42&app=sales
```

A Manage 403 with a JSON body must keep meaning **denied** (most products already map
401/403/404-with-JSON to their own 403). Surface Manage's `code` (`COMPANY_ACCESS_REQUIRED`) to
the browser where the product already passes a code through, so the SPA can show
`RestrictedCompanyDialog` instead of a generic error. Never treat a Manage outage as "allowed".

## 2. Client

```ts
import { createCompanyRegistryClient } from './company-access/index.ts';

export const companyRegistry = createCompanyRegistryClient({
  appKey: 'sales',
  // Manage's API base; default resolves *.gh.aicountly.com / gh-* / localhost → manage.gh, else manage.
  // baseUrl: import.meta.env.VITE_MANAGE_API_BASE,
  getSesKey: () => getSesKey(),          // the product's own my.aicountly ses_key accessor
  // or fetchImpl: (url, init) => productAuthedFetch(url, init)  // to reuse session refresh
});
```

The browser calls Manage directly with the person's `Authorization: Bearer <ses_key>`; Manage
allows `*.aicountly.com` origins. Products that only talk to Manage through their own proxy can
pass `fetchImpl` pointing at a proxy that forwards `/v1/*` unchanged.

## 3. Company selector

Either render `<CompanyDirectoryPicker>` where the product chooses a company, or keep the
product's own picker and feed it from `client.directory()`:

```tsx
<CompanyDirectoryPicker
  client={companyRegistry}
  appName="Aicountly Sales"
  selectedCmpId={scope.cmp_id}
  createCompanyHref={manageCreateCompanyUrl}   // optional
  onOpen={(c) => selectCompany(c.cmp_id)}      // only called for companies this person may open here
/>
```

Use `isOpenable(company)` before navigating into a company; for anything else show
`RestrictedCompanyDialog`. `CompanyAccessBadge` renders the status label in the shared
vocabulary.

## 4. Company Settings → Users & Access

```tsx
<CompanyUsersAccessPanel
  client={companyRegistry}
  cmpId={scope.cmp_id}
  appName="Aicountly Sales"
  roles={[{ key: 'sales_manager', label: 'Sales manager' }, …]}   // optional: the product's own roles
/>
```

The verified owner sees everyone related to the company with their access **in this product**,
grants / changes / revokes it, reviews access requests, manages the workspace and reads the
history. Anyone else sees their own access and can ask for it. No redirect to Manage.

`role_key` is advisory for products that keep their own role assignments (Sales profiles, CRM
profiles, Contacts roles…): the product remains authoritative for what a role may do. Books maps
`role_key` to its profile template and is seated by Manage at grant time.

`<MyWorkspacesPanel>` lists the person's workspaces and invitations (accept / decline). Each row
expands: the person's **own** workspace opens `<WorkspaceManager>` (rename, members with name and
e-mail, remove, invite by e-mail, suggestions); a workspace they **belong to** lists its companies
(`directory({ workspace_id })`) with their access badge, and offers Leave. Pass `onOpenCompany` to
make the companies they may open clickable. Each owner has exactly **one** workspace — everyone
they invite, or share a company with (on accepting the share), joins it. An unnamed workspace comes back from the server as
"<Owner name>'s workspace"; rows the person does not own carry `owner_name` / `owner_email`.

`<WorkspaceManager>` is the same owner view on its own (Users & Access uses it for its Workspace
tab); without a `workspace` prop it manages the one the signed-in person owns.
`<DuplicateCompanyNotice>` shows `client.duplicateCheck()` results on a create-company form.

## 5. Workspace sharing settings and creating a company in a workspace (v1.2.0)

Each person has exactly **one** workspace of their own (created the first time they look — no
company needed). What a member may do in someone else's workspace is set by its owner, per member,
at invite time or later (Manage `PATCH v1/workspaces/{id}/members/{uuid}`, CONTRACTS §1):

```ts
interface WorkspaceSettings {
  can_create_companies: boolean;            // default false
  max_companies_created: number | null;     // null = only the owner's plan limit
  creator_roles: Record<string, string[]>;  // roles they may give THEMSELVES on companies they create
  all_companies_access: Record<string, string>; // a role on EVERY company of the workspace
}
```

`<WorkspaceManager>` now shows each member's settings with a **Settings** button and offers
"Sharing settings…" on the invite form; `<WorkspaceMemberSettingsForm value onChange appRoles />`
is the same form on its own (`appRoles` from `fetchAppRoles(client)` → `GET v1/app-roles`; the
owner role is never listed or accepted). An older Manage without `v1/app-roles` simply hides it.

A company created in someone else's workspace is **owned by the workspace owner** (their plan
applies; they share and grant). The creator gets the access they choose, within the roles the
owner allows. A create form adds two things:

```tsx
const targets = await fetchCreationTargets(client);          // own workspace first
<WorkspaceTargetPicker targets={targets} value={wsId} onChange={setWsId} />
<CreatorAccessFields target={chosen} value={access} onChange={setAccess} />  // nothing for one's own
await createCompany({ ...form, ...createCompanyRequestFields(chosen, access) }); // {workspace_id, creator_access}
```

The create answer adds `owner {is_me, name}` and `creator_access [{app, role_key, status}]`
(`granted | seat_pending | refused`); `client.retryCreatorAccess(cmpId)` seats pending ones again.
Refusals: `WORKSPACE_NOT_MEMBER` / `WORKSPACE_CREATE_NOT_ALLOWED` (403), `CREATOR_ACCESS_REQUIRED`,
`CREATOR_ROLE_NOT_ALLOWED`, `WORKSPACE_COMPANY_LIMIT_REACHED`, `PLAN_LIMIT_REACHED` (422).
A form that sends no `workspace_id` creates in the person's own workspace, exactly as before.

Directory rows may now say `access_via: 'workspace'` with `workspace_role_key` (access from the
settings rather than a share); `client.pendingCounts()` answers what waits on the person (workspace
invitations, requests to review). A request may name Manage itself (`app: 'manage'`).

**Relays.** Products that relay the client's calls through an allow-list must add
`GET v1/app-roles`, `GET v1/company-creation-targets`, `PATCH v1/workspaces/{id}/members/{uuid}`,
`POST v1/companies/{id}/creator-access/retry` and `GET v1/me/pending`.

## 6. Mock / offline mode

Products with a mock API layer should stub `client.directory()` / `permissions()` with fixtures;
the components accept any object implementing `CompanyRegistryClient`.

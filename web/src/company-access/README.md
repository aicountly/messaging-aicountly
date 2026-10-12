# AICOUNTLY shared company access (v1.0.0)

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

`<MyWorkspacesPanel>` lists the person's workspaces and invitations (accept / decline / leave).
`<DuplicateCompanyNotice>` shows `client.duplicateCheck()` results on a create-company form.

## 5. Mock / offline mode

Products with a mock API layer should stub `client.directory()` / `permissions()` with fixtures;
the components accept any object implementing `CompanyRegistryClient`.

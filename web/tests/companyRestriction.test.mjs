/**
 * AICOUNTLY company lifecycle in the browser: the 423 COMPANY_RESTRICTED answer and the API's
 * lifecycle are read the same way, and the shell shows the read-only banner.
 *
 * Node's own runner; the TypeScript module is loaded with Node's type stripping.
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(fileURLToPath(import.meta.url))
const src = join(root, '..', 'src')
const restriction = await import(join(src, 'services', 'companyRestriction.ts'))

test('a 423 COMPANY_RESTRICTED answer is read with its message and date', () => {
  const r = restriction.restrictionFromResponse(423, {
    code: 'COMPANY_RESTRICTED',
    message: 'This company is read-only until 1 Dec 2026 because its subscription ended.',
    restricted_until: '2026-12-01T00:00:00Z',
    lifecycle: 'restricted',
    error: { code: 'COMPANY_RESTRICTED', message: 'x', restricted_until: '2026-12-01T00:00:00Z' },
  })
  assert.equal(r.restrictedUntil, '2026-12-01T00:00:00Z')
  assert.match(r.message, /read-only until 1 Dec 2026/)
  assert.equal(restriction.restrictionFromResponse(423, { error: { code: 'LOCKED' } }), null)
  assert.equal(restriction.restrictionFromResponse(403, { error: { code: 'forbidden' } }), null)
})

test('the lifecycle the API reports sets and clears the restriction', () => {
  restriction.__resetCompanyRestrictionsForTests()
  restriction.noteCompanyLifecycle(7, { lifecycle: 'purge_pending', restricted_until: null, message: 'Export now.' })
  assert.equal(restriction.getCompanyRestriction(7)?.lifecycle, 'purge_pending')
  assert.equal(restriction.getCompanyRestriction(7)?.message, 'Export now.')
  restriction.noteCompanyLifecycle(7, { data: { lifecycle: 'active' } })
  assert.equal(restriction.getCompanyRestriction(7), null)
  restriction.noteCompanyLifecycle(7, { data: { company: { lifecycle: 'restricted', restricted_until: '2026-12-01T00:00:00Z' } } })
  assert.match(restriction.getCompanyRestriction(7)?.message ?? '', /until 1 Dec 2026/)
  restriction.noteCompanyLifecycle(7, null)
  assert.equal(restriction.getCompanyRestriction(7), null)
})

test('the API client reports 423s and the shell renders the banner', () => {
  const sources = [['services/api.ts', 'noteCompanyRestricted('], ['shell/AppShell.tsx', '<CompanyRestrictedBanner'], ['context/MessagingContext.tsx', 'noteCompanyLifecycle(']]
  for (const [file, needle] of sources) {
    assert.ok(readFileSync(join(src, file), 'utf8').includes(needle), `${file} uses ${needle}`)
  }
})

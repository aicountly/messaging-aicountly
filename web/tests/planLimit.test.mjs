/**
 * Plan limits (CONTRACTS §3.6): a 402 PLAN_LIMIT_REACHED is read with its {code, limit, used}, the web
 * sentence names the limit and who can change it, and the shell shows it with a link to Manage's
 * Plans & billing.
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
const plan = await import(join(src, 'services', 'planLimit.ts'))

test("the API's 402 is read with its details", () => {
  const d = plan.planLimitFromResponse(402, {
    error: { code: 'PLAN_LIMIT_REACHED', message: 'x', details: { code: 'messaging.max_channels', limit: 2, used: 2 } },
    message: 'x',
  })
  assert.deepEqual(d, { code: 'messaging.max_channels', limit: 2, used: 2 })
  assert.equal(plan.planLimitFromResponse(422, { error: { code: 'validation_failed' } }), null)
  assert.equal(plan.planLimitFromResponse(402, { error: { code: 'payment_required' } }), null)
  assert.deepEqual(plan.planLimitFromResponse(402, null), { code: '', limit: 0, used: null })
})

test('the sentence names the limit and who can change it', () => {
  assert.equal(
    plan.planLimitMessage({ code: 'messaging.max_channels', limit: 2, used: 2 }),
    'Your plan allows 2 channel connections — ask the account owner to upgrade in Manage.',
  )
  assert.equal(
    plan.planLimitMessage({ code: 'messaging.max_messages_per_month', limit: 1, used: 1 }),
    'Your plan allows 1 outgoing message a month — ask the account owner to upgrade in Manage.',
  )
  assert.match(plan.planLimitMessage(null), /ask the account owner to upgrade in Manage/)
})

test("Manage's Plans & billing for the environment", () => {
  assert.equal(plan.managePlansUrl('messaging.aicountly.com'), 'https://manage.aicountly.com/billing')
  assert.equal(plan.managePlansUrl('messaging.gh.aicountly.com'), 'https://manage.gh.aicountly.com/billing')
})

test('the API client reports 402s and the shell renders the toast', () => {
  const sources = [['services/api.ts', 'notePlanLimit('], ['shell/AppShell.tsx', '<PlanLimitToast'], ['components/PlanLimitToast.tsx', 'managePlansUrl()']]
  for (const [file, needle] of sources) {
    assert.ok(readFileSync(join(src, file), 'utf8').includes(needle), `${file} uses ${needle}`)
  }
})

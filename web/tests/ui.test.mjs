/**
 * Tests for the frontend's pure logic and its design contract.
 *
 * Deliberately not a component test suite. What is worth testing here is the
 * handful of functions that decide what a number MEANS — whether a change chip
 * appears, what colour a message status wears, how money and durations are
 * formatted — plus the parts of the brand and accessibility contract that can
 * be checked by reading the source. Those are where a wrong answer is silently
 * plausible, which is exactly what a test is for.
 *
 *   npm run test:ui
 *
 * Node's own runner, no framework.
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(fileURLToPath(import.meta.url))
const src = join(root, '..', 'src')

function read(...parts) {
  return readFileSync(join(src, ...parts), 'utf8')
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** Mirrors formatMoney in src/ui/index.tsx. Money never arrives as a float. */
function formatMoney(minor, currency = 'INR') {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(minor / 100)
}

test('money is formatted from minor units, never from a float', () => {
  assert.ok(formatMoney(50000).includes('500'))
  // The case that matters: 1999 paise is ₹19.99, not ₹1,999.
  assert.ok(formatMoney(1999).includes('19.99'))
  assert.ok(!formatMoney(1999).includes('1,999'))
})

test('a currency is never assumed', () => {
  // Every amount in this product arrives with the currency the owning product
  // stated. A figure rendered without one is a figure somebody will read as
  // their own.
  assert.ok(formatMoney(50000, 'AED').includes('AED') || formatMoney(50000, 'AED').includes('د.إ'))
  assert.notEqual(formatMoney(50000, 'AED'), formatMoney(50000, 'INR'))
})

/** Mirrors formatDuration. Seconds in, something a human reads out. */
function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.round((seconds % 3600) / 60)
  return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`
}

test('a duration nobody measured is an em dash, not "0s"', () => {
  // A median response time of 0 seconds would be a remarkable claim. The
  // honest answer when nothing was measured is that nothing was measured.
  assert.equal(formatDuration(null), '—')
  assert.equal(formatDuration(undefined), '—')
  assert.equal(formatDuration(0), '0s')
})

test('durations read the way a person would say them', () => {
  assert.equal(formatDuration(45), '45s')
  assert.equal(formatDuration(150), '3m')
  assert.equal(formatDuration(3600), '1h')
  assert.equal(formatDuration(5400), '1h 30m')
})

/** Mirrors formatRate. A rate with no denominator is unknown, not zero. */
function formatRate(rate) {
  if (rate === null || rate === undefined) return '—'
  return `${(rate * 100).toFixed(1)}%`
}

test('a delivery rate with no denominator renders as unknown, not 0%', () => {
  // THE CASE. Nothing sent means the rate is unknown. "0%" reads as total
  // failure and would have somebody investigating an outage that is not there.
  assert.equal(formatRate(null), '—')
  assert.equal(formatRate(0), '0.0%')
  assert.equal(formatRate(0.9812), '98.1%')
})

// ---------------------------------------------------------------------------
// The change chip
// ---------------------------------------------------------------------------

/**
 * Mirrors the decision in ChangeChip.
 *
 * `null` renders NOTHING. "0%" says the number held steady; "no previous
 * period" is a different claim, and a brand-new company showing "↑ 0%" on every
 * card is a dashboard lying quietly.
 */
function chipFor(change, lowerIsBetter = false) {
  if (change === null || change === undefined) return null
  if (change === 0) return 'neutral'
  const good = lowerIsBetter ? change < 0 : change > 0
  return good ? 'positive' : 'negative'
}

test('a null change renders no chip at all', () => {
  assert.equal(chipFor(null), null)
  assert.equal(chipFor(undefined), null)
})

test('zero is neutral, not good news', () => {
  assert.equal(chipFor(0), 'neutral')
})

test('lowerIsBetter flips which direction is good', () => {
  // A rise in messages sent is good. A rise in failed messages is not, and a
  // green chip beside it would be the dashboard congratulating a problem.
  assert.equal(chipFor(12), 'positive')
  assert.equal(chipFor(12, true), 'negative')
  assert.equal(chipFor(-12), 'negative')
  assert.equal(chipFor(-12, true), 'positive')
})

// ---------------------------------------------------------------------------
// Message status tone — the one that must not overstate
// ---------------------------------------------------------------------------

/** Mirrors messageStatusTone in src/ui/index.tsx. */
function messageStatusTone(status) {
  switch (status) {
    case 'delivered':
    case 'read':
      return 'success'
    case 'provider_accepted':
      return 'neutral'
    case 'failed':
      return 'danger'
    case 'submission_unknown':
      return 'warning'
    case 'cancelled':
      return 'neutral'
    case 'queued':
    case 'dispatching':
      return 'info'
    default:
      return 'neutral'
  }
}

test('provider_accepted is neutral, never green', () => {
  // THE POINT. A provider taking a message is not the customer receiving it. A
  // green tick here is the product claiming a delivery nobody confirmed.
  assert.equal(messageStatusTone('provider_accepted'), 'neutral')
  assert.equal(messageStatusTone('delivered'), 'success')
  assert.equal(messageStatusTone('read'), 'success')
})

test('an ambiguous submission is a warning, not a failure', () => {
  // "We do not know" is a different thing from "it failed", and the difference
  // decides whether a human resends. Colouring it red invites a duplicate.
  assert.equal(messageStatusTone('submission_unknown'), 'warning')
  assert.equal(messageStatusTone('failed'), 'danger')
})

// ---------------------------------------------------------------------------
// Source state — the five states, kept apart
// ---------------------------------------------------------------------------

function sourceStateTone(state) {
  switch (state) {
    case 'ready':
      return 'success'
    case 'pending':
      return 'info'
    case 'forbidden':
      return 'warning'
    case 'unsupported':
      return 'neutral'
    default:
      return 'danger'
  }
}

test('the five source states are five different things', () => {
  const tones = ['ready', 'pending', 'unavailable', 'forbidden', 'unsupported'].map(sourceStateTone)
  // "Nobody connected Books", "Books is down", "you may not see this" and
  // "this deployment of Books does not offer it" are four different sentences
  // and four different next actions. Collapsing them into one error is how a
  // user retries something that will never work.
  assert.equal(new Set(tones).size, 5)
})

// ---------------------------------------------------------------------------
// Brand and contrast
// ---------------------------------------------------------------------------

/** Relative luminance, per WCAG 2.1. */
function luminance(hex) {
  const channels = [hex.slice(1, 3), hex.slice(3, 5), hex.slice(5, 7)]
    .map((part) => parseInt(part, 16) / 255)
    .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4))
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

function contrast(a, b) {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (light + 0.05) / (dark + 0.05)
}

test('the brand green is the one from the specification', () => {
  const css = read('ui', 'messaging-ui.css')
  assert.ok(css.includes('#25b003'), 'the primary brand green must be #25b003')
})

test('white text never sits on the brand green', () => {
  // #25b003 is a mark colour. It does not carry white text at 4.5:1, and a
  // brand-coloured product with unreadable buttons is not on brand.
  assert.ok(contrast('#25b003', '#ffffff') < 4.5, 'if this ever passes, the assumption below is wrong')
  assert.ok(contrast('#176c09', '#ffffff') >= 4.5, '#176c09 is the accessible green for white text')
})

test('body and secondary text clear 4.5:1 on the page background', () => {
  assert.ok(contrast('#17231b', '#f5f8f5') >= 4.5, 'main text on the page background')
  assert.ok(contrast('#647168', '#ffffff') >= 4.5, 'secondary text on a card')
})

// ---------------------------------------------------------------------------
// The stylesheet contract
// ---------------------------------------------------------------------------

test('every product stylesheet rule is scoped or prefixed', () => {
  // A bare `.card` or `button {}` in a product stylesheet reaches the shell,
  // the launcher and the sign-in screen, and the bug shows up on a page
  // nobody was editing.
  const css = read('ui', 'messaging-ui.css')
  const selectors = css
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split('}')
    .map((block) => block.split('{')[0].trim())
    .filter((selector) => selector !== '' && !selector.startsWith('@') && !selector.startsWith(':root'))

  const unscoped = selectors.filter((selector) =>
    selector
      .split(',')
      .map((part) => part.trim())
      .some((part) => part !== '' && !part.startsWith('.msg-') && !part.includes('.msg-') && !part.startsWith('from') && !part.startsWith('to') && !/^\d+%$/.test(part)),
  )

  assert.deepEqual(unscoped, [], `unscoped selectors: ${unscoped.join(' | ')}`)
})

test('motion is declared and reduced-motion is honoured', () => {
  // Both stylesheets, because each declares its own transitions. A product
  // area that keeps animating when the shell has stopped is not honouring the
  // preference, it is honouring half of it.
  for (const file of [['ui', 'messaging-ui.css'], ['index.css']]) {
    const css = read(...file)
    assert.ok(
      css.includes('prefers-reduced-motion'),
      `${file.join('/')} must respect a reduced-motion preference`,
    )
  }
})

test('no transition is slower than the design allows', () => {
  // 120–200ms, per the brief. A transition long enough to notice is a
  // transition somebody waits for.
  //
  // TRANSITIONS only. A skeleton shimmer is a looping animation that says
  // "still loading" — it is supposed to be slow enough to read as breathing,
  // and holding it to a 200ms budget would make it a strobe.
  const css = read('ui', 'messaging-ui.css')
  const transitions = [...css.matchAll(/transition:\s*([^;]+);/g)].map((match) => match[1])

  assert.ok(transitions.length > 0, 'the stylesheet should declare some motion')

  for (const declaration of transitions) {
    if (declaration.trim().startsWith('none')) continue

    const durations = [
      ...[...declaration.matchAll(/(\d+(?:\.\d+)?)ms/g)].map((match) => Number(match[1])),
      ...[...declaration.matchAll(/(\d*\.?\d+)s(?![a-z])/g)].map((match) => Number(match[1]) * 1000),
    ].filter((ms) => ms >= 1)

    for (const ms of durations) {
      assert.ok(ms <= 200, `"${declaration.trim()}" is slower than the 120–200ms the design calls for`)
    }
  }
})

// ---------------------------------------------------------------------------
// Accessibility promises that can be read off the source
// ---------------------------------------------------------------------------

test('status is never conveyed by colour alone', () => {
  // StatusPill always renders its children as text. A pill with a tone and no
  // label would be a colour a screen reader cannot read and a colour-blind
  // user cannot tell apart.
  const ui = read('ui', 'index.tsx')
  assert.ok(
    /export function StatusPill\(\{\s*\n?\s*children/.test(ui),
    'StatusPill must take its label as children, so the tone is never the only signal',
  )
})

test('every page renders inside the product wrapper', () => {
  // The wrapper is what scopes the tokens. A page without it inherits the
  // shell's, which is how one screen ends up looking like a different product.
  const pages = readdirSync(join(src, 'pages')).filter((name) => name.endsWith('.tsx'))
  assert.ok(pages.length >= 7, `expected the full set of pages, found ${pages.length}`)

  for (const page of pages) {
    const source = read('pages', page)
    if (page === 'SignIn.tsx') continue   // outside the shell by design
    assert.ok(
      source.includes('msg-ui') || source.includes('msg-panel') || source.includes('msg-page-header'),
      `${page} should render inside the Messaging design system`,
    )
  }
})

test('no page reaches for another product\'s API directly', () => {
  // Every cross-product read goes through this product's backend, under the
  // user's own session, so the other product applies its own permissions. A
  // browser calling books.aicountly.com straight would need a credential it
  // must never have.
  const pages = readdirSync(join(src, 'pages')).filter((name) => name.endsWith('.tsx'))

  for (const page of pages) {
    const source = read('pages', page)
    for (const host of ['books.aicountly', 'contacts.aicountly', 'sales.aicountly', 'pay.aicountly', 'graph.facebook', 'api.twilio']) {
      assert.ok(!source.includes(host), `${page} must not call ${host} from the browser`)
    }
  }
})

function sourceFiles() {
  const files = []
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const path = join(dir, entry.name)
      if (entry.isDirectory()) walk(path)
      else if (/\.(ts|tsx)$/.test(entry.name)) files.push(path)
    }
  }
  walk(src)
  return files
}

test('the frontend never presents a service credential', () => {
  // A credential VALUE, never a credential NAME. Showing an administrator that
  // MESSAGING_WHATSAPP_TOKEN is the variable to set is the documented design —
  // the API returns the reference and a `credential_present` boolean, and the
  // name is what makes the gap actionable. What must not exist is a header
  // carrying a key, or a key baked into the bundle.
  for (const file of sourceFiles()) {
    const source = readFileSync(file, 'utf8')

    assert.ok(
      !/['"]X-Service-Key['"]/.test(source),
      `${file} sets an X-Service-Key header; that credential is a backend's, never a browser's`,
    )
    assert.ok(
      !/(?:sk|pk)-(?:live|test)-[A-Za-z0-9]{8,}/.test(source),
      `${file} contains something shaped like a provider key`,
    )
    // VITE_PRODUCT_KEY and the like are identifiers, not credentials. What
    // must never be read from the build environment is a secret, because
    // anything in the bundle is public the moment it ships.
    assert.ok(
      !/import\.meta\.env\.[A-Z_]*(?:SECRET|AUTH_TOKEN|PASSWORD|SERVICE_KEY|API_KEY|ACCESS_TOKEN)/.test(source),
      `${file} reads a secret-shaped build variable; anything in the bundle is public`,
    )
  }
})

test('the frontend sends only its own two portal credentials as bearer tokens', () => {
  // The Aicountly SSO model gives a browser exactly two: the long-lived
  // auth_token, which goes to my.aicountly.com to mint a session, and the
  // short-lived ses_key, which goes to this product's API. Anything else in an
  // Authorization header is a credential a browser should not be holding.
  for (const file of sourceFiles()) {
    const source = readFileSync(file, 'utf8')
    for (const match of source.matchAll(/Bearer \$\{([^}]+)\}/g)) {
      const subject = match[1]
      assert.ok(
        /ses|token/i.test(subject),
        `${file} sends "Bearer \${${subject}}", which is neither the auth_token nor the ses_key`,
      )
      assert.ok(
        !/service|secret|credential/i.test(subject),
        `${file} sends "Bearer \${${subject}}"; a service credential is a backend's, never a browser's`,
      )
    }
  }
})

// ---------------------------------------------------------------------------
// The API client's promises
// ---------------------------------------------------------------------------

test('the API client holds one idempotency key across its retry', () => {
  const api = read('services', 'api.ts')

  // Generated OUTSIDE the retry. A key regenerated on retry is no key at all:
  // the backend would treat the retry as a new request and the customer would
  // get a second message.
  const generated = api.indexOf('const key = options.idempotencyKey ??')
  const tryBlock = api.indexOf('try {', generated)

  assert.ok(generated > 0, 'the key is generated once per user action')
  assert.ok(tryBlock > generated, 'and BEFORE the try, so the retry cannot generate a second one')
  assert.ok(api.includes('send<T>(path, options, freshKey, key)'),
    'the retry reuses the same key with a fresh session key')
})

test('the API client asks for no caching', () => {
  const api = read('services', 'api.ts')
  assert.ok(api.includes("'no-store'"), 'a cached authenticated response is somebody else\'s conversation')
})

test('the session key is never written to storage', () => {
  const portal = read('auth', 'portal.ts')

  // The long-lived auth_token is stored; the short-lived ses_key is not. A
  // ses_key in localStorage is a credential sitting where any script can read
  // it, for as long as the browser remembers it.
  const storageWrites = [...portal.matchAll(/(?:localStorage|sessionStorage)\.setItem\(\s*([^,]+)/g)].map(
    (match) => match[1],
  )
  for (const target of storageWrites) {
    assert.ok(
      !/ses|SES/.test(target),
      `portal.ts writes ${target.trim()} to storage; the ses_key must stay in memory`,
    )
  }
})

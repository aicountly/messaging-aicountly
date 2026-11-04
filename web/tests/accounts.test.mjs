/**
 * The roster of AICOUNTLY accounts signed in on this browser.
 *
 * The readable half of the portal's roster (`AIC_ACCOUNTS_VIEW`) is the only
 * channel the account menu has — the token half is httpOnly and the portal's
 * API is cross-origin with credentials refused. So what matters here is that a
 * cookie this app did not write is read defensively: a missing one, a corrupt
 * one and a hostile one all have to read as "no accounts" rather than throw,
 * because a render error in the shell takes the whole topbar with it.
 *
 * `src/auth/accounts.ts` is a twin of the Notes and Books modules; these checks
 * are the twins of their tests, plus the one thing only this product can get
 * wrong — dropping the remembered company on a switch while keeping the tokens.
 *
 * The shipped modules are loaded through Vite's SSR module graph (they use
 * extensionless imports and import.meta.env), under a minimal fake browser —
 * the same harness as tests/auth.test.mjs.
 *
 * Run with: npm run test:ui
 */

import { after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

// ---------------------------------------------------------------------------
// A fake browser
// ---------------------------------------------------------------------------

const location = {}
let assigned = []

function setLocation(href) {
  const url = new URL(href)
  Object.assign(location, {
    href: url.href,
    origin: url.origin,
    protocol: url.protocol,
    host: url.host,
    hostname: url.hostname,
    pathname: url.pathname,
    search: url.search,
    hash: url.hash,
    assign: (next) => void assigned.push(String(next)),
  })
}

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => data.clear(),
  }
}

let jar = ''
setLocation('https://messaging.aicountly.com/')

globalThis.window = {
  location,
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
  addEventListener: () => {},
  removeEventListener: () => {},
}
globalThis.localStorage = globalThis.window.localStorage
globalThis.sessionStorage = globalThis.window.sessionStorage
globalThis.document = {
  get cookie() {
    return jar
  },
  set cookie(value) {
    /* the roster cookie is the portal's to write, never this app's */
    void value
  },
}

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const accounts = await server.ssrLoadModule('/src/auth/accounts.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
})

const { MAX_ACCOUNTS } = accounts

/** Encode a roster the way the portal's AccountRoster::summaryCookieValue does. */
function viewCookie(list) {
  const json = JSON.stringify({ v: 1, max: MAX_ACCOUNTS, a: list })
  const binary = Array.from(new TextEncoder().encode(json), (byte) => String.fromCharCode(byte)).join('')
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function withCookie(value) {
  jar = value === '' ? '' : `AIC_ACCOUNTS_VIEW=${value}`
}

function at(href) {
  setLocation(href)
}

beforeEach(() => {
  jar = ''
  assigned = []
  localStorage.clear()
  at('https://messaging.aicountly.com/')
})

// ---------------------------------------------------------------------------
// Reading the roster
// ---------------------------------------------------------------------------

test('the portal’s summary cookie is read as the accounts on this browser', () => {
  withCookie(
    viewCookie([
      { u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 },
      { u: 77, n: 'Priya Sharma', e: 'pr•••@example.com', a: 0 },
    ]),
  )

  const roster = accounts.readAccountRoster()
  assert.equal(roster.length, 2)
  assert.deepEqual(roster[0], {
    uuid: 88,
    name: 'Rahul Gupta',
    fullName: 'Rahul Gupta',
    maskedEmail: 'ra•••@cagupta.in',
    maskedMobile: '+91••••••••78',
    active: true,
  })
  assert.equal(roster[1].active, false)
})

test('the active account comes first however the cookie ordered them', () => {
  // The menu draws the identity block from the active entry, so this is not a
  // cosmetic ordering — getting it wrong labels the session with the wrong
  // person's masked mobile.
  withCookie(viewCookie([{ u: 77, n: 'Priya', a: 0 }, { u: 88, n: 'Rahul', a: 1 }]))
  assert.deepEqual(accounts.readAccountRoster().map((account) => account.uuid), [88, 77])
})

test('the portal’s masking is kept — a full address never reaches the menu', () => {
  withCookie(viewCookie([{ u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 }]))
  const [account] = accounts.readAccountRoster()
  assert.doesNotMatch(account.maskedEmail, /rahul@/)
  assert.match(account.maskedEmail, /•/)
  assert.match(account.maskedMobile, /•/)
})

// A browser signed in before the roster shipped, local development, and a
// cleared cookie jar all land here. None is an error: the menu falls back to
// the portal chooser, which always knows the truth.
test('no cookie reads as no accounts', () => {
  withCookie('')
  assert.deepEqual(accounts.readAccountRoster(), [])
})

test('a corrupt cookie reads as no accounts rather than throwing', () => {
  for (const value of ['!!!not-base64!!!', 'YWJj', btoa('{"a":'), btoa('[]'), btoa('null')]) {
    withCookie(value)
    assert.deepEqual(accounts.readAccountRoster(), [], value)
  }
})

test('entries with no usable subscriber id are dropped', () => {
  withCookie(
    viewCookie([
      { u: 0, n: 'Nobody' },
      { n: 'Also nobody' },
      { u: 'not-a-number', n: 'Nor this' },
      { u: -3, n: 'Nor this either' },
      null,
      { u: 88, n: 'Rahul', a: 1 },
    ]),
  )
  assert.deepEqual(accounts.readAccountRoster().map((account) => account.uuid), [88])
})

test('never more accounts than the portal allows, whatever the cookie claims', () => {
  withCookie(viewCookie(Array.from({ length: 9 }, (_, index) => ({ u: index + 1, n: `User ${index + 1}` }))))
  assert.equal(accounts.readAccountRoster().length, MAX_ACCOUNTS)
})

// ---------------------------------------------------------------------------
// The cap
// ---------------------------------------------------------------------------

test('another account may be added while there is room, and not once there is none', () => {
  assert.equal(accounts.canAddAccount([]), true)
  assert.equal(accounts.canAddAccount(Array.from({ length: MAX_ACCOUNTS - 1 }, () => ({}))), true)
  assert.equal(accounts.canAddAccount(Array.from({ length: MAX_ACCOUNTS }, () => ({}))), false)
})

test('"Use another account" is hidden when the browser already holds five', () => {
  withCookie(
    viewCookie(Array.from({ length: MAX_ACCOUNTS }, (_, index) => ({ u: index + 1, a: index === 0 ? 1 : 0 }))),
  )
  assert.equal(accounts.canAddAccount(accounts.readAccountRoster()), false)
})

// ---------------------------------------------------------------------------
// The portal URLs
// ---------------------------------------------------------------------------

test('production: the URLs name this product so the portal hands the user back here', () => {
  at('https://messaging.aicountly.com/')
  assert.equal(
    accounts.buildSwitchAccountUrl(88),
    'https://my.aicountly.com/login/switch_account/messaging?uuid=88',
  )
  assert.equal(accounts.buildAddAccountUrl(), 'https://my.aicountly.com/login/add_account/messaging')
  assert.equal(accounts.buildChooseAccountUrl(), 'https://my.aicountly.com/login/select_account/messaging')
})

test('sandbox: the sandbox portal, and still the `messaging` product key', () => {
  // A sandbox build pointed at my.aicountly.com is the classic way to break
  // sandbox sign-in; the product key must not pick up the `gh` label either.
  at('https://messaging.gh.aicountly.com/')
  assert.equal(
    accounts.buildSwitchAccountUrl(88),
    'https://sandbox.aicountly.com/login/switch_account/messaging?uuid=88',
  )
  assert.equal(accounts.buildAddAccountUrl(), 'https://sandbox.aicountly.com/login/add_account/messaging')
  assert.equal(accounts.buildChooseAccountUrl(), 'https://sandbox.aicountly.com/login/select_account/messaging')
})

test('localhost is a sandbox host and falls back to the configured product key', () => {
  at('http://localhost:5173/')
  assert.equal(accounts.buildChooseAccountUrl(), 'https://sandbox.aicountly.com/login/select_account/messaging')
})

test('no token and no ses_key is ever put in a portal URL', () => {
  for (const url of [accounts.buildSwitchAccountUrl(88), accounts.buildAddAccountUrl(), accounts.buildChooseAccountUrl()]) {
    assert.doesNotMatch(url, /auth_token|ses_key|Bearer/i)
  }
})

// ---------------------------------------------------------------------------
// Leaving for another account
// ---------------------------------------------------------------------------

test('switching navigates to the portal with the account asked for', () => {
  accounts.performAccountSwitch(77)
  assert.deepEqual(assigned, ['https://my.aicountly.com/login/switch_account/messaging?uuid=77'])
})

test('switching with no account named goes to the add-account screen', () => {
  accounts.performAccountSwitch(undefined)
  assert.deepEqual(assigned, ['https://my.aicountly.com/login/add_account/messaging'])
})

test('the remembered company and branch are dropped — they belong to the account being left', () => {
  // MessagingProvider reads these on its first render. Carried across, the
  // next account's very first screen asks v1/session for a company they cannot
  // open, and a successful sign-in looks like a 403.
  localStorage.setItem('messaging:cmp_id', '60')
  localStorage.setItem('messaging:bo_id', '3')

  accounts.performAccountSwitch(77)

  assert.equal(localStorage.getItem('messaging:cmp_id'), null)
  assert.equal(localStorage.getItem('messaging:bo_id'), null)
})

test('the ses_key of the account being left is dropped', () => {
  // It is the Bearer on every API call to this product.
  tokens.saveSession('ses-key-of-the-old-account', 900)
  assert.equal(tokens.getSesKey(), 'ses-key-of-the-old-account')

  accounts.performAccountSwitch(77)

  assert.equal(tokens.getSesKey(), null)
})

test('the auth_token is NOT dropped, so backing out of the chooser is not a sign-out', () => {
  // The portal replaces the tokens on the way back through /auth/callback.
  // Clearing them here would strand anyone who cancels at a login form.
  tokens.setAuthToken('still-signed-in')

  accounts.performAccountSwitch(77)

  assert.equal(tokens.getAuthToken(), 'still-signed-in')
  assert.equal(localStorage.getItem('auth_token'), 'still-signed-in')
})

/**
 * Sign-in: the retired cookie purge and the deep link across the portal.
 *
 *   - The retired `.aicountly.com` `auth_token` cookie is only ever purged on
 *     *.aicountly.com, and only on that domain: a host-only `auth_token` on
 *     localhost or a custom domain belongs to some other application.
 *   - Every first visit goes through my.aicountly.com, whose only return address
 *     is /auth/callback; the address the user opened (path, query, hash) must be
 *     what the tab shows afterwards, not "/". Modelled on Inventory's
 *     web/src/auth/returnRoute.test.tsx.
 *
 * The shipped modules are loaded through Vite's SSR module graph (they use
 * extensionless imports and import.meta.env), under a minimal fake browser.
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

let replaced = []
let jar = ''
let cookieWrites = []
location.replace = (url) => void replaced.push(String(url))
setLocation('http://localhost:5173/')

globalThis.window = {
  location,
  history: { replaceState: (_state, _title, url) => setLocation(new URL(url, location.href).href) },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
}
globalThis.sessionStorage = globalThis.window.sessionStorage
globalThis.localStorage = globalThis.window.localStorage
globalThis.document = {
  get cookie() {
    return jar
  },
  set cookie(value) {
    cookieWrites.push(value)
  },
}
const realFetch = globalThis.fetch
globalThis.fetch = async () => new Response('{}', { status: 200 })

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const cookie = await server.ssrLoadModule('/src/auth/sharedAuthCookie.ts')
const portal = await server.ssrLoadModule('/src/auth/portal.ts')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
})

function at(href) {
  setLocation(href)
}

function go(path) {
  window.history.replaceState(null, '', `${location.origin}${path}`)
}

function here() {
  return `${location.pathname}${location.search}${location.hash}`
}

beforeEach(() => {
  replaced = []
  cookieWrites = []
  jar = ''
  sessionStorage.clear()
  localStorage.clear()
  at('http://localhost:5173/')
})

// ---------------------------------------------------------------------------
// The retired cookie
// ---------------------------------------------------------------------------

test('localhost: document.cookie is never touched', () => {
  jar = 'auth_token=other-app'
  cookie.purgeLegacySharedAuthToken()
  cookie.clearSharedAuthToken()
  assert.deepEqual(cookieWrites, [])
})

test('a host outside .aicountly.com: document.cookie is never touched', () => {
  at('https://chat.example.com/')
  jar = 'auth_token=other-app'
  cookie.purgeLegacySharedAuthToken()
  cookie.clearSharedAuthToken()
  assert.deepEqual(cookieWrites, [])
})

test('messaging.aicountly.com: one write, on .aicountly.com, expired', () => {
  at('https://messaging.aicountly.com/')
  jar = 'auth_token=legacy; other=1'
  cookie.purgeLegacySharedAuthToken()
  assert.deepEqual(cookieWrites, ['auth_token=; domain=.aicountly.com; path=/; max-age=0; SameSite=Lax; Secure'])
})

test('the cookie is never read as a token', () => {
  at('https://messaging.aicountly.com/')
  jar = 'auth_token=planted'
  assert.equal(cookie.readSharedAuthToken(), null)
})

// ---------------------------------------------------------------------------
// The deep link across the portal
// ---------------------------------------------------------------------------

const DEEP_LINK = '/inbox/0b6c2d5e-1111-4222-8333-944445555666?channel=whatsapp#latest'

test('the deep link, query and hash included, is reopened after the portal answers', () => {
  go(DEEP_LINK)
  assert.equal(portal.redirectToPortalSso(), true)
  assert.equal(replaced.length, 1)
  assert.match(replaced[0], /\/login\/authentication_jump\//)

  go('/auth/callback?auth_token=portal-token')
  assert.equal(portal.readAuthCallback().authToken, 'portal-token')
  portal.clearCallbackFromUrl()

  assert.equal(here(), DEEP_LINK)
  assert.equal(sessionStorage.getItem(portal.RETURN_ROUTE_KEY), null)
})

test('the explicit login form keeps the destination too', () => {
  go(DEEP_LINK)
  portal.redirectToPortalLoginForm()
  assert.match(replaced[0], /prompt=login/)
  go('/auth/callback?auth_token=t')
  portal.clearCallbackFromUrl()
  assert.equal(here(), DEEP_LINK)
})

test('a destination from an abandoned attempt is never replayed', () => {
  go(DEEP_LINK)
  portal.redirectToPortalSso()
  go('/')
  portal.redirectToPortalSso()
  go('/auth/callback?auth_token=t')
  portal.clearCallbackFromUrl()
  assert.equal(here(), '/')
})

test('sign-out forgets the destination', () => {
  go(DEEP_LINK)
  portal.redirectToPortalSso()
  portal.performLogout()
  assert.equal(sessionStorage.getItem(portal.RETURN_ROUTE_KEY), null)
})

test('nothing remembered lands on "/" as before', () => {
  go('/auth/callback?auth_token=t')
  portal.clearCallbackFromUrl()
  assert.equal(here(), '/')
})

test('normaliseReturnRoute keeps an in-app address and refuses everything else', () => {
  assert.equal(portal.normaliseReturnRoute(DEEP_LINK), DEEP_LINK)
  for (const route of ['//evil.example/x', '/\\evil.example/x', 'https://evil.example/x', 'inbox', '', null, '/', '/auth/callback?auth_token=x', '/auth/anything']) {
    assert.equal(portal.normaliseReturnRoute(route), null, String(route))
  }
  assert.equal(portal.normaliseReturnRoute('/contacts?auth_token=x&q=a'), '/contacts?q=a')
  assert.equal(portal.normaliseReturnRoute('/contacts?sso_code=abc'), '/contacts')
})

test('the router opens the restored address, not "/", after the callback', async () => {
  const { readFileSync } = await import('node:fs')
  const app = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../src/App.tsx'), 'utf8')
  assert.match(app, /<Route path="auth\/callback" element=\{<AfterSignIn \/>\} \/>/)
  assert.match(app, /normaliseReturnRoute\(`\$\{pathname\}\$\{search\}\$\{hash\}`\) \?\? '\/'/)
})

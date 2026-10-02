/**
 * Analytics is a third party, and the portal's sign-in hand-off
 * (/auth/callback?auth_token=…) carries a reusable credential. Nothing GA is
 * handed — page_location, page_path, page_referrer — may carry a query string,
 * and gtag.js (which reads the address bar itself) is not loaded until the app
 * has cleared the hand-off.
 *
 * src/utils/analytics.ts reads its measurement id from import.meta.env, which
 * only Vite provides, so the shipped source is loaded with that one expression
 * pointed at a test value, under a minimal window and document.
 *
 * Run with: npm run test:ui
 */

import { afterEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const SRC = join(dirname(fileURLToPath(import.meta.url)), '../src')
const ORIGIN = 'https://messaging.aicountly.com'
const ENV = { VITE_GA4_SAAS_MESSAGING_MEASUREMENT_ID: 'G-TEST000000' }

async function loadAnalytics(url, referrer = '') {
  const location = new URL(url, ORIGIN)
  const appended = []
  globalThis.window = { location }
  globalThis.document = {
    referrer,
    createElement: () => ({}),
    head: { appendChild: (node) => appended.push(node) },
  }
  globalThis.__ANALYTICS_TEST_ENV__ = ENV

  const source = readFileSync(join(SRC, 'utils/analytics.ts'), 'utf8')
    .replaceAll('import.meta.env', 'globalThis.__ANALYTICS_TEST_ENV__')
  const file = join(mkdtempSync(join(tmpdir(), 'analytics-')), 'analytics.ts')
  writeFileSync(file, source)

  return {
    ...(await import(pathToFileURL(file).href)),
    appended,
    navigate: (next) => { location.href = new URL(next, ORIGIN).href },
  }
}

/** Every gtag call so far, as plain arrays. */
function calls() {
  return (globalThis.window.dataLayer || []).map((args) => Array.from(args))
}

function pageViews() {
  return calls().filter(([command, name]) => command === 'event' && name === 'page_view').map(([, , params]) => params)
}

afterEach(() => {
  delete globalThis.window
  delete globalThis.document
  delete globalThis.__ANALYTICS_TEST_ENV__
})

test('sends nothing, and loads nothing, for the sign-in hand-off', async () => {
  const a = await loadAnalytics('/auth/callback?auth_token=DUMMYTOKEN&state=DUMMYSTATE')

  a.initAnalytics()
  a.trackPageView('/auth/callback?auth_token=DUMMYTOKEN&state=DUMMYSTATE')

  assert.equal(a.appended.length, 0)
  assert.equal(globalThis.window.gtag, undefined)
  assert.doesNotMatch(JSON.stringify(calls()), /DUMMYTOKEN|DUMMYSTATE|auth_token/)
})

test('waits for AuthProvider to clear the hand-off, then starts on the next page view', async () => {
  // App.tsx calls initAnalytics() at module evaluation, before AuthProvider's boot.
  const a = await loadAnalytics('/auth/callback?auth_token=DUMMYTOKEN')
  a.initAnalytics()
  assert.equal(globalThis.window.gtag, undefined)

  a.navigate('/') // clearCallbackFromUrl()
  a.trackPageView('/inbox', 'Inbox')

  assert.equal(a.appended.length, 1)
  assert.equal(pageViews().length, 1)
  assert.equal(pageViews()[0].page_location, `${ORIGIN}/inbox`)
  assert.equal(pageViews()[0].page_path, '/inbox')
  assert.doesNotMatch(JSON.stringify(calls()), /DUMMYTOKEN/)
})

test('recognises every form the hand-off arrives in', async () => {
  const { urlCarriesCredential } = await loadAnalytics('/')

  assert.equal(urlCarriesCredential({ pathname: '/', search: '?auth_token=x', hash: '' }), true)
  assert.equal(urlCarriesCredential({ pathname: '/', search: '', hash: '#/auth/callback?auth_token=x' }), true)
  assert.equal(urlCarriesCredential({ pathname: '/', search: '', hash: '#/auth/callback#auth_token=x' }), true)
  assert.equal(urlCarriesCredential({ pathname: '/inbox', search: '?view=mine', hash: '' }), false)
})

test('sends the route path only, with an explicit page_location, to the page view and the page context', async () => {
  const a = await loadAnalytics('/')

  a.trackPageView('/inbox/0f8fad5b-d9cb-469f-a165-70867728950e?view=mine#reply')

  const [view] = pageViews()
  assert.equal(view.page_path, '/inbox/:id')
  assert.equal(view.page_location, `${ORIGIN}/inbox/:id`)
  const context = calls().filter(([command]) => command === 'config').at(-1)[2]
  assert.equal(context.page_location, `${ORIGIN}/inbox/:id`)
  assert.equal(context.send_page_view, false)
  assert.doesNotMatch(JSON.stringify(calls()), /mine|reply|0f8fad5b/)
})

test('reduces the referrer to an origin or a sanitised path', async () => {
  const a = await loadAnalytics('/', 'https://my.aicountly.com/login?returnUrl=x&auth_token=DUMMYTOKEN')

  a.trackPageView('/')
  a.trackPageView('/inbox')

  const [first, second] = pageViews()
  assert.equal(first.page_referrer, 'https://my.aicountly.com/')
  assert.equal(second.page_referrer, `${ORIGIN}/`)
  assert.equal(a.sanitizeReferrer(`${ORIGIN}/auth/callback?auth_token=DUMMYTOKEN`), `${ORIGIN}/auth/callback`)
  assert.doesNotMatch(JSON.stringify(calls()), /DUMMYTOKEN/)
})

test('keeps location fields a caller passes to trackEvent out of GA', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/')

  a.trackEvent('message_sent', { channel: 'web', page_location: 'https://x.test/?auth_token=DUMMYTOKEN' })

  assert.deepEqual(calls().at(-1), ['event', 'message_sent', { channel: 'web' }])
})

test('is never handed a query string, hash or full URL by a call site', () => {
  const files = (function walk(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
      const path = join(dir, entry.name)
      if (entry.isDirectory()) return walk(path)
      return /\.tsx?$/.test(entry.name) && !entry.name.includes('.test.') ? [path] : []
    })
  })(SRC)

  const offenders = files.flatMap((file) =>
    [...readFileSync(file, 'utf8').matchAll(/trackPageView\(([^)]*)\)/g)]
      .filter(([, args]) => /\.(search|href|hash)\b/.test(args))
      .map(([call]) => `${file}: ${call}`),
  )
  assert.deepEqual(offenders, [])
})

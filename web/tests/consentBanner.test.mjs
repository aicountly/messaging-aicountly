/**
 * The analytics consent card, rendered.
 *
 * The shipped component and analytics module are loaded through Vite's SSR
 * module graph (as in auth.test.mjs) with a stand-in measurement id, under a
 * minimal window and document with a cookie jar, and rendered with
 * react-dom/server: what the card says, when it is there and when it is not.
 * What a click does is the module's setAnalyticsConsent (analytics.test.mjs);
 * that the buttons call it, where the card sits at 390px and what it covers
 * were checked with Playwright against the built app (the change's commit
 * message says how).
 *
 * Run with: npm run test:ui
 */

import { after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'

const GA_ID = 'G-TEST000000'
const TEXT =
  'AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you. Allow analytics cookies?'

let jar = new Map()
let cookieWrites = []
const location = new URL('https://messaging.aicountly.com/inbox')
globalThis.window = { location }
globalThis.document = {
  get cookie() {
    return [...jar].map(([name, value]) => `${name}=${value}`).join('; ')
  },
  set cookie(value) {
    cookieWrites.push(value)
    const [name, ...rest] = value.split(';')[0].split('=')
    jar.set(name.trim(), rest.join('=').trim())
  },
  referrer: '',
  title: 'Messaging · Aicountly',
  createElement: () => ({}),
  head: { appendChild: () => {} },
}

process.env.VITE_GA4_SAAS_MESSAGING_MEASUREMENT_ID = GA_ID
const server = await createServer({
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false, ws: false, watch: null },
  appType: 'custom',
})

let analytics
let Banner

beforeEach(async () => {
  jar = new Map()
  cookieWrites = []
  location.href = 'https://messaging.aicountly.com/inbox'
  // Fresh module state (the choice made on this page, the reopened flag) for every test.
  server.moduleGraph.invalidateAll()
  analytics = await server.ssrLoadModule('/src/utils/analytics.ts')
  Banner = (await server.ssrLoadModule('/src/components/AnalyticsConsentBanner.tsx')).AnalyticsConsentBanner
})

after(async () => {
  await server.close()
})

const render = () => renderToStaticMarkup(createElement(Banner))

test('no choice yet: a labelled region with the agreed words, Allow, No thanks and the privacy policy', () => {
  const html = render()

  assert.match(html, /role="region"/)
  assert.match(html, /aria-label="Analytics cookies"/)
  assert.ok(html.replace(/\s+/g, ' ').includes(TEXT), html)
  assert.deepEqual([...html.matchAll(/<button[^>]*>([^<]*)<\/button>/g)].map((m) => m[1]), ['Allow', 'No thanks'])
  assert.match(html, /<a[^>]*href="https:\/\/aicountly\.com\/privacy-policy"[^>]*>Privacy policy<\/a>/)
  assert.doesNotMatch(html, /aria-modal|role="dialog"/, 'a card, not a modal: nothing is blocked and focus is not trapped')
})

test('gone once the person answers, and the answer is the shared cookie', () => {
  analytics.setAnalyticsConsent('denied')
  assert.equal(render(), '')
  assert.deepEqual(cookieWrites, ['aic_consent=analytics-denied; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure'])

  analytics.openAnalyticsPreferences()
  assert.match(render(), /Allow analytics cookies\?/)
  analytics.setAnalyticsConsent('granted')
  assert.equal(render(), '')
  assert.equal(cookieWrites.at(-1), 'aic_consent=analytics-granted; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure')
})

test('not shown when a choice was made in another AICOUNTLY app', () => {
  jar.set('aic_consent', 'analytics-granted')
  assert.equal(render(), '')
})

test('not shown while the address still holds the sign-in hand-off', () => {
  location.href = 'https://messaging.aicountly.com/auth/callback?auth_token=DUMMYTOKEN'
  assert.equal(render(), '')
})

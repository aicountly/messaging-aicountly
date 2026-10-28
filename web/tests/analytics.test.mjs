/**
 * Analytics is a third party, and the portal's sign-in hand-off
 * (/auth/callback?auth_token=…) carries a reusable credential. Nothing GA is
 * handed — page_location, page_path, page_referrer — may carry a query string,
 * and gtag.js (which reads the address bar itself) is not loaded until the app
 * has cleared the hand-off.
 *
 * Consent, the same in every AICOUNTLY app: the shared aic_consent cookie is
 * read first; Consent Mode v2 defaults (ads always denied) go out before any
 * config; the tag runs cookieless until a choice (or not at all, with
 * ANALYTICS_BEFORE_CHOICE = 'none'), never after "No thanks", and Allow sends
 * a consent update. Whatever the choice, ids in the path become :id and the
 * page title is a label, never document.title.
 *
 * src/utils/analytics.ts reads its measurement id from import.meta.env, which
 * only Vite provides, so the shipped source is loaded with that one expression
 * pointed at a test value, under a minimal window and document, next to a copy
 * of src/utils/analyticsConsent.ts.
 *
 * Run with: npm run test:ui
 */

import { afterEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const SRC = join(dirname(fileURLToPath(import.meta.url)), '../src')
const ORIGIN = 'https://messaging.aicountly.com'
const ENV = { VITE_GA4_SAAS_MESSAGING_MEASUREMENT_ID: 'G-TEST000000' }

/** The temporary copies made so far, removed after each test. */
const copies = []

/**
 * A fresh copy of the shipped module. `cookies` is what document.cookie holds
 * at load; `beforeChoice` loads it with ANALYTICS_BEFORE_CHOICE set to that
 * value instead (the constant counsel may flip); `env` replaces the build's.
 */
async function loadAnalytics(url, referrer = '', { cookies = '', beforeChoice = null, env = ENV, origin = ORIGIN } = {}) {
  const location = new URL(url, origin)
  const appended = []
  const jar = new Map(cookies.split(';').filter(Boolean).map((pair) => pair.trim().split('=')))
  const cookieWrites = []
  globalThis.window = { location }
  globalThis.document = {
    referrer,
    // A title that names somebody: it must never reach GA.
    title: 'Asha Verma — conversation · Messaging',
    get cookie() {
      return [...jar].map(([name, value]) => `${name}=${value}`).join('; ')
    },
    set cookie(value) {
      cookieWrites.push(value)
      const [name, ...rest] = value.split(';')[0].split('=')
      jar.set(name.trim(), rest.join('=').trim())
    },
    createElement: () => ({}),
    head: { appendChild: (node) => appended.push(node) },
  }
  globalThis.__ANALYTICS_TEST_ENV__ = env

  const dir = mkdtempSync(join(tmpdir(), 'analytics-'))
  copies.push(dir)
  let source = readFileSync(join(SRC, 'utils/analytics.ts'), 'utf8')
    .replaceAll('import.meta.env', 'globalThis.__ANALYTICS_TEST_ENV__')
    .replaceAll("from './analyticsConsent'", "from './analyticsConsent.ts'")
  if (beforeChoice) {
    const declared = "export const ANALYTICS_BEFORE_CHOICE: BeforeChoice = 'cookieless'"
    assert.ok(source.includes(declared), 'ANALYTICS_BEFORE_CHOICE declaration not found')
    source = source.replace(declared, `export const ANALYTICS_BEFORE_CHOICE: BeforeChoice = '${beforeChoice}'`)
  }
  writeFileSync(join(dir, 'analytics.ts'), source)
  writeFileSync(join(dir, 'analyticsConsent.ts'), readFileSync(join(SRC, 'utils/analyticsConsent.ts'), 'utf8'))

  return {
    ...(await import(pathToFileURL(join(dir, 'analytics.ts')).href)),
    consent: await import(pathToFileURL(join(dir, 'analyticsConsent.ts')).href),
    appended,
    cookieWrites,
    navigate: (next) => { location.href = new URL(next, origin).href },
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
  for (const dir of copies.splice(0)) rmSync(dir, { recursive: true, force: true })
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

// ---------------------------------------------------------------------------
// Consent
// ---------------------------------------------------------------------------

const ADS = ['ad_storage', 'ad_user_data', 'ad_personalization']

test('no choice yet: Consent Mode defaults come first, every ads signal denied, then redaction and no passthrough', async () => {
  const a = await loadAnalytics('/')
  a.initAnalytics()

  const [first, second, third] = calls()
  assert.deepEqual(first, ['consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500,
  }])
  assert.deepEqual(second, ['set', 'ads_data_redaction', true])
  assert.deepEqual(third, ['set', 'url_passthrough', false])
  assert.ok(calls().findIndex(([command]) => command === 'config') > 0)
})

test('no choice yet: the tag loads cookieless, and nothing writes a cookie', async () => {
  const a = await loadAnalytics('/')
  assert.equal(a.ANALYTICS_BEFORE_CHOICE, 'cookieless')
  a.trackPageView('/inbox', 'Unified Inbox')

  assert.equal(a.appended.length, 1)
  assert.equal(a.appended[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-TEST000000')
  assert.equal(pageViews().length, 1)
  assert.deepEqual(a.cookieWrites, [])
})

test('every config: no automatic page view, no Google signals or ad personalisation, SameSite=Lax;Secure', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/inbox', 'Unified Inbox')
  a.trackPageView('/contacts', 'Contacts')

  const configs = calls().filter(([command]) => command === 'config')
  assert.equal(configs.length, 3)
  for (const [, id, fields] of configs) {
    assert.equal(id, 'G-TEST000000')
    assert.equal(fields.send_page_view, false)
    assert.equal(fields.allow_google_signals, false)
    assert.equal(fields.allow_ad_personalization_signals, false)
    assert.equal(fields.cookie_flags, 'SameSite=Lax;Secure')
  }
})

test("ANALYTICS_BEFORE_CHOICE = 'none': nothing loads before a choice; Allow loads it and counts the page", async () => {
  const a = await loadAnalytics('/', '', { beforeChoice: 'none' })
  assert.equal(a.ANALYTICS_BEFORE_CHOICE, 'none')
  a.initAnalytics()
  a.trackPageView('/inbox', 'Unified Inbox')
  assert.equal(a.appended.length, 0)
  assert.equal(globalThis.window.dataLayer, undefined)
  assert.equal(a.isConsentBannerVisible(), true)

  a.setAnalyticsConsent('granted')
  assert.equal(a.appended.length, 1)
  assert.equal(calls()[0][2].analytics_storage, 'granted')
  for (const signal of ADS) assert.equal(calls()[0][2][signal], 'denied')
  assert.deepEqual(calls().filter(([command, kind]) => command === 'consent' && kind === 'update'), [['consent', 'update', { analytics_storage: 'granted' }]])
  assert.equal(pageViews()[0].page_location, `${ORIGIN}/inbox`)
})

test('declined: no tag at all, ga-disable set, nothing tracked — with either setting', async () => {
  for (const beforeChoice of [null, 'none']) {
    const a = await loadAnalytics('/', '', { cookies: 'aic_consent=analytics-denied', beforeChoice })
    a.initAnalytics()
    a.trackPageView('/inbox', 'Unified Inbox')
    a.trackEvent('message_sent', { channel: 'web' })

    assert.equal(a.appended.length, 0)
    assert.equal(globalThis.window.dataLayer, undefined)
    assert.equal(globalThis.window['ga-disable-G-TEST000000'], true)
    assert.equal(a.isConsentBannerVisible(), false)
  }
})

test('allowed earlier: the defaults grant analytics_storage, never ads', async () => {
  const a = await loadAnalytics('/', '', { cookies: 'aic_consent=analytics-granted' })
  a.initAnalytics()

  assert.equal(calls()[0][2].analytics_storage, 'granted')
  for (const signal of ADS) assert.equal(calls()[0][2][signal], 'denied')
  assert.equal(a.isConsentBannerVisible(), false)
})

test('Allow: the shared cookie on .aicountly.com, then one consent update for analytics only', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/inbox', 'Unified Inbox')
  a.setAnalyticsConsent('granted')

  assert.deepEqual(a.cookieWrites, ['aic_consent=analytics-granted; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure'])
  assert.deepEqual(calls().filter(([command, kind]) => command === 'consent' && kind === 'update'), [['consent', 'update', { analytics_storage: 'granted' }]])
  assert.equal(a.appended.length, 1)
  assert.equal(a.isConsentBannerVisible(), false)
})

test('No thanks: ga-disable at once, analytics denied, and nothing sent after it', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/inbox', 'Unified Inbox')
  const sent = calls().length
  a.setAnalyticsConsent('denied')

  assert.deepEqual(a.cookieWrites, ['aic_consent=analytics-denied; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure'])
  assert.equal(globalThis.window['ga-disable-G-TEST000000'], true)
  assert.deepEqual(calls().slice(sent), [['consent', 'update', { analytics_storage: 'denied' }]])
  a.trackPageView('/contacts', 'Contacts')
  a.trackEvent('message_sent', { channel: 'web' })
  assert.equal(calls().length, sent + 1)
})

test('no ads signal is ever granted, whatever is chosen', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/inbox', 'Unified Inbox')
  a.setAnalyticsConsent('granted')
  a.setAnalyticsConsent('denied')
  a.setAnalyticsConsent('granted')

  for (const [command, , fields] of calls()) {
    if (command !== 'consent') continue
    for (const signal of ADS) assert.notEqual(fields[signal], 'granted')
  }
})

test('every gtag command reaches the dataLayer as an Arguments object, the only form gtag.js runs — never an Array', async () => {
  const a = await loadAnalytics('/')
  a.trackPageView('/inbox', 'Inbox')
  a.setAnalyticsConsent('granted')
  a.trackEvent('message_sent', { channel: 'web' })

  assert.ok(globalThis.window.dataLayer.length > 6)
  for (const entry of globalThis.window.dataLayer) {
    assert.equal(Array.isArray(entry), false)
    assert.equal(Object.prototype.toString.call(entry), '[object Arguments]')
  }
})

test('a build without a measurement id: no tag, no cookie, no banner', async () => {
  const a = await loadAnalytics('/', '', { env: {} })
  assert.equal(a.isAnalyticsConfigured(), false)
  a.initAnalytics()
  a.trackPageView('/inbox', 'Unified Inbox')
  a.openAnalyticsPreferences()

  assert.equal(a.appended.length, 0)
  assert.equal(globalThis.window.dataLayer, undefined)
  assert.equal(a.isConsentBannerVisible(), false)
  assert.deepEqual(a.cookieWrites, [])
})

test('the banner: not while the address holds a credential; shown until a choice; reopened by Analytics preferences', async () => {
  const a = await loadAnalytics('/auth/callback?auth_token=DUMMYTOKEN')
  assert.equal(a.isConsentBannerVisible(), false)
  a.navigate('/inbox')
  assert.equal(a.isConsentBannerVisible(), true)

  let told = 0
  const stop = a.subscribeAnalyticsConsent(() => { told += 1 })
  a.setAnalyticsConsent('denied')
  assert.equal(a.isConsentBannerVisible(), false)
  a.openAnalyticsPreferences()
  assert.equal(a.isConsentBannerVisible(), true)
  assert.equal(a.analyticsPreferencesRequests(), 1)
  a.setAnalyticsConsent('granted')
  assert.equal(a.isConsentBannerVisible(), false)
  assert.ok(told >= 3)
  stop()
})

test('the aic_consent cookie: .aicountly.com on aicountly hosts, host-only elsewhere, Secure only on https', async () => {
  const { consent } = await loadAnalytics('/')

  for (const hostname of ['messaging.aicountly.com', 'messaging.gh.aicountly.com', 'aicountly.com']) {
    assert.equal(consent.consentCookie('granted', { hostname, protocol: 'https:' }),
      'aic_consent=analytics-granted; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure')
  }
  assert.equal(consent.consentCookie('granted', { hostname: 'aicountly.io', protocol: 'https:' }),
    'aic_consent=analytics-granted; Path=/; Max-Age=15552000; SameSite=Lax; Secure')
  assert.equal(consent.consentCookie('denied', { hostname: 'localhost', protocol: 'http:' }),
    'aic_consent=analytics-denied; Path=/; Max-Age=15552000; SameSite=Lax')
  assert.equal(consent.consentCookieDomain('notaicountly.com'), null)

  assert.equal(consent.readConsentCookie('a=1; aic_consent=analytics-granted'), 'granted')
  assert.equal(consent.readConsentCookie('aic_consent=analytics-denied'), 'denied')
  assert.equal(consent.readConsentCookie('aic_consent=yes'), null)
  assert.equal(consent.readConsentCookie(''), null)
})

// ---------------------------------------------------------------------------
// Masking
// ---------------------------------------------------------------------------

test('every identifier in a path becomes :id; query and fragment go', async () => {
  const { sanitizePagePath } = await loadAnalytics('/')

  assert.equal(sanitizePagePath('/contacts/12345'), '/contacts/:id')
  assert.equal(sanitizePagePath('/inbox/0f8fad5b-d9cb-469f-a165-70867728950e'), '/inbox/:id')
  assert.equal(sanitizePagePath('/contacts/asha.verma@example.com'), '/contacts/:id')
  assert.equal(sanitizePagePath('/contacts/asha.verma%40example.com/notes'), '/contacts/:id/notes')
  assert.equal(sanitizePagePath('/journey-runs/9f86d081884c7d659a2feaa0c55ad015'), '/journey-runs/:id')
  assert.equal(sanitizePagePath('/share/dGhpc2lzYXRva2VuMTIzNDU2Nzg'), '/share/:id')
  assert.equal(sanitizePagePath('/inbox?view=mine&q=Asha#reply'), '/inbox')
  for (const route of ['/', '/inbox', '/journeys', '/outcomes', '/trust', '/contacts', '/settings', '/settings/users-access', '/auth/callback']) {
    assert.equal(sanitizePagePath(route), route)
  }
})

test('a page view carries a label as its title, or the product name — never document.title', async () => {
  const a = await loadAnalytics('/contacts/42?tab=notes')
  a.trackPageView('/contacts/42?tab=notes', 'Contact detail')
  a.trackPageView('/somewhere')

  const [labelled, unlabelled] = pageViews()
  assert.equal(labelled.page_title, 'Contact detail')
  assert.equal(labelled.page_location, `${ORIGIN}/contacts/:id`)
  assert.equal(unlabelled.page_title, 'Aicountly Messaging')
  const context = calls().filter(([command]) => command === 'config').at(-1)[2]
  assert.equal(context.page_title, 'Aicountly Messaging')
  assert.doesNotMatch(JSON.stringify(calls()), /Asha|Verma|notes|\/42/)
})

test('routes are labelled from the navigation; a record page by its kind, never its record', async () => {
  const { pageLabel } = await import('../src/shell/navConfig.ts')

  assert.equal(pageLabel('/'), 'Command Centre')
  assert.equal(pageLabel('/inbox'), 'Unified Inbox')
  assert.equal(pageLabel('/inbox/0f8fad5b-d9cb-469f-a165-70867728950e'), 'Conversation')
  assert.equal(pageLabel('/contacts/42'), 'Contact detail')
  assert.equal(pageLabel('/journey-runs/7'), 'Journey run')
  assert.equal(pageLabel('/settings/users-access'), 'Users & Access')
  assert.equal(pageLabel('/auth/callback'), undefined)
})

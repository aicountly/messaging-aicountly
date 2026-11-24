import assert from 'node:assert/strict'
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { dirname, resolve, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { execFileSync } from 'node:child_process'

export async function verifyNoGa4() {
  // Vite/esbuild need Node's native typed arrays, outside a test runner's DOM realm.
  if (typeof window !== 'undefined') {
    execFileSync(process.execPath, [fileURLToPath(import.meta.url)], {stdio: 'pipe'})
    return
  }
  const { createServer } = await import('vite')
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
  const utils = ['ts', 'js'].map(ext => join(root, 'src/utils/analytics.' + ext)).find(existsSync)
  assert.ok(utils, 'analytics compatibility module exists')
  assert.doesNotMatch(readFileSync(utils, 'utf8'), /googletagmanager\.com\/(?:gtag|gtm)/, 'no Google tag loader remains')
  const saved = new Map(['window', 'document', 'fetch'].map(k => [k, Object.getOwnPropertyDescriptor(globalThis, k)]))
  const writes = []
  const win = {location: new URL('https://test.gh.aicountly.com/'), dispatchEvent() {}, addEventListener() {}, removeEventListener() {}}
  const doc = {referrer: '', title: 'Private title', head: {appendChild(node) { writes.push(node) }}, createElement() { return {} }, get cookie() { return 'aic_consent=analytics-granted; aic_ads_consent=ads-granted' }, set cookie(value) { writes.push(value) }}
  Object.defineProperty(globalThis, 'window', {value: win, configurable: true, writable: true})
  Object.defineProperty(globalThis, 'document', {value: doc, configurable: true, writable: true})
  Object.defineProperty(globalThis, 'fetch', {value: (...args) => { writes.push(args); throw new Error('Unexpected tracker request') }, configurable: true, writable: true})
  const server = await createServer({root, configFile: false, envFile: false, optimizeDeps: {noDiscovery: true, include: []}, appType: 'custom', server: {middlewareMode: true, watch: null, hmr: false, ws: false}, resolve: {alias: {'@': join(root, 'src')}}})
  try {
    const a = await server.ssrLoadModule(utils)
    assert.equal(a.getGa4MeasurementId(), '')
    for (const key of ['isAnalyticsConfigured', 'analyticsConfigured']) if (a[key]) assert.equal(a[key](), false)
    a.initAnalytics?.()
    a.trackPageView?.('/private/123?token=SECRET')
    a.trackEvent?.('signup', {email: 'private@example.test'})
    a.setAnalyticsConsent?.('analytics-granted')
    a.setAnalyticsChoice?.('analytics-granted')
    a.allowAnalytics?.()
    a.declineAnalytics?.()
    a.openAnalyticsPreferences?.()
    if (a.startGoogleTag) assert.equal(a.startGoogleTag('G-OLD-CONFIG', 'granted', 'cookieless', win, doc), false)
    if (a.applyConsent) assert.equal(a.applyConsent('granted', 'G-OLD-CONFIG', true, win), 'done')
    if (a.createAnalytics) {
      const runtime = a.createAnalytics({measurementId: 'G-OLD-CONFIG', win, doc}, 'G-OLD-CONFIG')
      runtime.init?.(); runtime.pageView?.('/'); runtime.trackPageView?.('/'); runtime.event?.('signup'); runtime.trackEvent?.('signup')
      runtime.setConsent?.('granted'); runtime.openPreferences?.()
      if(runtime.isConfigured) assert.equal(runtime.isConfigured(), false)
      if(runtime.bannerShown) assert.equal(runtime.bannerShown(), false)
    }
    if (a.sanitizePagePath) assert.ok(!a.sanitizePagePath('/records/123?token=SECRET').includes('SECRET'))
    function walk(dir) {
      return readdirSync(dir, {withFileTypes:true}).flatMap(e => e.isDirectory() ? walk(join(dir,e.name)) : [join(dir,e.name)])
    }
    const banners = walk(join(root,'src')).filter(f => /\/(AnalyticsConsentBanner|ConsentBanner|AnalyticsConsent)\.(tsx|jsx)$/.test(f))
    for (const file of banners) {
      const module = await server.ssrLoadModule(file)
      const component = module.default || module.AnalyticsConsentBanner || module.ConsentBanner || module.AnalyticsConsent
      assert.equal(component({path:'/'}), null, file)
    }
    assert.deepEqual(writes, [], 'no scripts, tracking cookies, network calls or consent grants')
    assert.equal(win.dataLayer, undefined)
    assert.equal(win.gtag, undefined)
  } finally {
    await server.close()
    for (const [key, desc] of saved) { if(desc) Object.defineProperty(globalThis,key,desc); else delete globalThis[key] }
  }
}
if(process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  verifyNoGa4().then(() => {
    console.log('PASS: GA4 and consent UI remain retired, including with old configuration and granted cookies')
  }).catch(error => { console.error(error); process.exitCode = 1 })
}

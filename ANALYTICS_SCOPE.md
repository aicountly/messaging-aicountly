# Analytics scope — messaging-aicountly

Owner decision recorded: 24 November 2026.

**Decision: Required.**

This customer/partner-facing AICOUNTLY product is deployed within the included aicountly.com domain family. First-party adoption, meaningful workflow outcomes and reliability measurements are required; beta status does not remove that requirement.

This file records the required deployment policy. It does not assert that instrumentation, credentials, delivery, tracker removal or legal-notice changes are already implemented or verified.

## Deployments

| Host | Analytics coverage |
| --- | --- |
| `messaging.aicountly.com` | Required |
| `messaging.gh.aicountly.com` | Required |

The host mapping is based on repository documentation and the supplied s01 configuration. A configured host is not proof that it is currently serving traffic. Apply the rules below to additional aliases and deployments.

## Estate-wide rule and reasons

- Required: `aicountly.com` and its subdomains, including beta/product pages and `*.gh.aicountly.com`; `aicountly.io`; and `ourpeople.work`. These are the agreed product, marketing and distribution surfaces whose adoption and reliability need measurement.
- Exact-host exception: `sandbox.aicountly.com` is excluded by the owner's explicit decision. This exception does **not** exclude `*.gh.aicountly.com`.
- Excluded domain families, including all subdomains: `aicountly.org`, `aicountly.co.in`, `carahulgupta.in`, `aicountly.in`, `shivanshispl.com`, and `sispl.org`. The owner placed these deployments outside this analytics programme.
- Receiver exception: `analytics.aicountly.org` remains the ingestion/reporting service, not an automatically included source of staff behaviour.
- Evaluate the actual deployment host, not the repository name or a shared authentication dependency. Excluded hosts must not send their own usage events or snapshots through a proxy to evade the exclusion. Do not disable authentication, business APIs, security/audit logging or other essential integrations merely because a dependency's host is excluded.
- Shared code must support per-deployment configuration. In particular, enabling `my.aicountly.com` must not enable `sandbox.aicountly.com`. Keep this exact-host distinction in deployment checks.
- A new domain outside this list needs an explicit scope decision before activation.

## Requirements for included deployments

- Use the internal receiver at `https://analytics.aicountly.org/api`. Keep labelled service credentials server-side; no ingest key belongs in a browser or mobile bundle.
- A frontend-only app can use its authoritative backend or an approved first-party relay. Attribute events to the actual originating product; do not invent a local API or duplicate business events merely to make a dashboard look active.
- Separate production, sandbox and development data. `*.gh.aicountly.com` uses `environment=sandbox`; beta product tier is separate from environment. Test/staff activity and health probes must not inflate customer adoption.
- Use registered event names, stable idempotency keys on retries, bounded retry/backoff and observable queues. Maintain collector schedules and aggregate snapshots where applicable.
- Verify an actual accepted event and its occurrence/arrival times. An HTTP 200 health response proves connectivity, key recognition and reported store availability, not event generation or delivery. A receipt containing only rejections is not a successful delivery.
- Monitor delivery, rejection/duplicate counts, queue age and snapshot freshness separately. A quiet app is not automatically broken. Record operational heartbeats separately from user events and conversion metrics.
- Check production and each included beta/sandbox deployment independently. A passing production key does not prove its corresponding `.gh` deployment is instrumented.

## Privacy and advertising direction

The owner has requested removal of advertising trackers across the included estate. Do not introduce or re-enable advertising pixels, ad conversion tags or cross-site advertising identifiers as part of internal analytics integration.

Use minimal first-party measurement appropriate to the stated purpose. Do not export secrets, raw IP addresses, free-text content, document/accounting records, message bodies, or sensitive HR/education/health information into event payloads. Hashing a stable user ID does not by itself make data anonymous.

A requirement for analytics is not blanket permission to track every person. Preserve applicable consent/objection requirements; do not force an existing denial to become an allow. Terms of Use are not a substitute for a legally required choice. Privacy, Cookie and Terms notices must reflect the implemented data flows. Remove obsolete prompts only after their underlying tracking has been removed or the remaining measurement has been assessed appropriately.

## Acceptance and change boundary

For an included deployment, completion requires verified instrumentation, actual accepted data, correct environment attribution, working delivery/retry monitoring and appropriate privacy controls.

For an excluded deployment, completion means no contribution of its own product-usage events or analytics snapshots; retain necessary local security and operational logs. An old source-registry entry alone does not override the exclusion or justify a missing-feed alert. Preserve historical data under the applicable retention policy; this scope decision does not authorize deleting it.

This documentation change does not rotate keys, provision sources, change runtime settings, remove trackers, deploy code, or certify legal compliance. Those implementation tasks need their own verified changes.

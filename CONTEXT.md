# Context

The domain language of this repository.
Every name here is used consistently in the source, the tests and the docs.

`AGENTS.md` explains how the repo is built and which decisions are already paid for.
This file only defines what the words mean.
When a term drifts or a new concept earns a name, change it here first.

## The site

**Site**
The WordPress install this repo stands up.
By default there is exactly one, and it serves the whole API.
Under multisite it becomes the main site of a network, but the base case is a single site with no network at all.

## Multisite (extension)

Multisite is off by default and turned on with `TEMPL_HEADLESS_MULTISITE=1`.
The terms below only apply once it is on; a single-site install has a `Site` and nothing in this section.

**Network**
The WordPress multisite install this repo stands up when multisite is enabled.
Everything is a subsite of it; there is no second install.

**Subsite**
One site on the network, reached at a path like `/customer-one/`.
Its posts, options and API keys live in its own tables and are invisible to every other subsite.

**Tenant**
The customer a subsite belongs to.
One tenant, one subsite: the word is about who the data belongs to, `subsite` is about where it lives.

**Main site**
The subsite at `/`, blog ID 1.
It is a subsite like any other for the purpose of keys and data; it holds no special authority over the rest.

**Zero-touch onboarding**
The guarantee that a subsite created from Network Admin serves the full API the moment it exists, with no plugin to activate and no theme to switch.
`tests/multisite/NewSiteTest.php` is the proof.

## Authentication

**API key**
The credential a frontend sends to reach a protected endpoint.
256 bits of `random_bytes`, prefixed `thl_`, scoped to the site that minted it.
Under multisite that scope is one subsite; on a single site it is simply the one site.

**Plaintext key**
The key as the client holds it.
Shown once, at creation, and never recoverable: the site stores only its hash.

**Key prefix**
The leading characters of a key, kept in the clear so a human can tell two keys apart in a list.
Not a secret and not enough to authenticate.

**Digest**
The SHA-256 of a plaintext key.
The only copy of the key a site ever holds.

**Revoked**
A key turned off but kept for the audit trail, so "who called this last month" stays answerable.
A revoked key authenticates nothing; it is distinct from a deleted one, which leaves no record.

**Per-site scope**
The rule that a key minted on one subsite unlocks that subsite and no other.
Only meaningful under multisite; not a feature bolted on, but a consequence of the key post type being registered separately on every subsite.

## The frontend

**Headless frontend**
The application built in front of a site.
It owns the public pages; WordPress here serves only JSON and the admin.

**Proxy**
The expected shape of a submission from a headless frontend: the browser posts to the frontend's own server, which holds the API key and forwards the request.
Keeps the key off the client.

**Headless theme**
`templ-headless-theme`, which redirects every public page request to `wp-login.php`.
It exists only so WordPress has an active theme; nothing renders a visitor-facing page.

## Contact form

**Submission**
One message sent through the contact endpoint: a name, an email, an optional subject and a message.
Stored as a private post; read and deleted from the Contact Form screen or the REST list.

**Honeypot**
A form field a real client leaves empty and a bot fills.
A filled honeypot is answered with a fake success so the bot learns nothing, and nothing is stored.

**Rate limit**
The ceiling on submissions from one sender in a window, keyed on the hashed sender IP.
Five per hour by default, changeable through a filter.

## Newsletter

**Subscriber**
One email address on a site's list, carrying a status and an unsubscribe token.
The email is the post title, which is what makes the admin search and the idempotent subscribe work.

**Single opt-in**
Subscribing takes effect immediately; no confirmation email is sent.
Double opt-in is an extension point, not the default.

**Idempotent subscribe**
Subscribing an address that already exists returns the same record rather than erroring or duplicating, and re-activates it if it had unsubscribed.
A headless frontend retries, so this is deliberate.

**Unsubscribe token**
The one credential in this repo that is not an API key.
256 bits, single-purpose, embedded in the unsubscribe link a frontend puts in its own email; it only ever unsubscribes its own subscriber, which is why its endpoint needs no key.

## Words we do not use

- **"customer"** where `site`, `subsite` or `tenant` is meant. A customer is a person; a site is a thing.
- **"token"** for an API key. The unsubscribe token is the only token; keys are keys.
- **"form"** for the contact endpoint. There is exactly one, it is not configurable, and calling it "a form" invites a form builder that is explicitly out of scope.
- **"login"** for a key. Keys are not users; no key maps to a WordPress account.

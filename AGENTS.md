# AGENTS.md

A boilerplate for running WordPress as a headless backend.
It boots a single site by default; an agency that needs to host many customer sites as isolated subsites of one network turns on multisite with `TEMPL_HEADLESS_MULTISITE=1`.
Each site exposes REST endpoints for a contact form and a newsletter, guarded by per-site API keys managed from WP Admin and WP-CLI.

This file records the decisions that are already paid for.
`CONTEXT.md` defines the words.
Read both before changing anything, and when a rule here exists because something broke, the breakage is named so it is not undone by accident.

## Toolchain lives in containers

There is no PHP, Composer or WP-CLI on the host, and none is needed.
Everything runs through `compose.yaml` (podman, but `docker compose` is interchangeable).

```sh
composer dev:up               # single site on :8080 (override with TEMPL_HEADLESS_PORT), admin/password
composer dev:up:multisite     # same, but as a subdirectory network with a sample subsite
composer dev:down
composer dev:reset            # tear the volumes down and reprovision single-site from empty
composer dev:reset:multisite  # reprovision as multisite from empty (needed to switch mode)
composer dev:logs
composer dev:install          # composer install in the pinned image
composer dev:lint             # phpcs
composer dev:php-floor        # parse every file on the oldest supported PHP
composer dev:test             # unit suite, then integration suite (needs the stack up)
composer dev:test:multisite   # multisite suite (needs a multisite stack up)
composer dev:cli wp plugin list
```

The same lifecycle is mirrored in `package.json` for people who reach for pnpm first:

```sh
pnpm dev                      # composer dev:up
pnpm run deploy production    # rsync wp-content to a Templ host (see Deploy)
```

`pnpm deploy` without `run` is a reserved pnpm builtin and will not run our script; it is always `pnpm run deploy`.

**Never run Composer from a newer PHP than the floor.**
`config.platform.php` is pinned to `8.3` because dependencies resolved against a newer PHP can install code that does not parse in the runtime the fleet has, which surfaces as a baffling parse error deep inside PHPUnit rather than at the point of the mistake.

## Repo layout

This repository is a `wp-content` overlay, nothing more.
WordPress core, uploads and the database live in containers (dev) or on the server (production) and are never in git.
`compose.yaml` bind-mounts each directory of `wp-content` into the official `wordpress` image; the mounts are the contract, so a new plugin directory has to be added to every service that loads WordPress or it is present on one side and missing on the other.

```
wp-content/
  mu-plugins/
    templ-headless.php              # loader: WP does not recurse into mu-plugin subdirs
    templ-headless/                 # the key store and the auth contract
    templ-updates.php               # restores core auto-updates under a git checkout
    templ-user-enumeration.php      # hides /wp/v2/users from anonymous callers
  plugins/
    templ-contact-form/
    templ-newsletter/
  themes/
    templ-headless-theme/           # redirects the whole frontend to wp-login.php
```

## Architecture

**Namespaced functions, no classes.**
Every file holds functions in one namespace.
There are no god classes and no service containers; the closest thing to a class in the runtime is `WP_Error` and friends, which come from core.
`phpcs.xml` excludes `WordPress.Files.FileName.InvalidClassFileName` for exactly this reason, and the exclusion carries its own why-comment.

**One `bootstrap()` per module, and it is the only place hooks are registered.**
A module's `bootstrap()` calls `add_action`/`add_filter` and nothing else of consequence; a plugin's top-level `bootstrap()` calls each module's.
This is the whole map of when code runs, so keep it that way: a stray `add_action` outside a `bootstrap()` is a hook nobody can find.

**No production autoloader.**
PSR-4 maps class names to files, and this codebase has no classes, so there is nothing for an autoloader to trigger on.
Each plugin `require_once`s its modules explicitly, in load order, in the main file.

**To add a module to a plugin:** write `src/<module>.php` in its own sub-namespace with a `bootstrap()`, add one `require_once` in the plugin's main file, and one call to it from the plugin's `bootstrap()`.
Nothing else.

**To add a whole plugin:** copy the shape of `templ-contact-form` (main file with the MU-plugin dependency guard, `src/` modules, a versioned REST namespace), add its directory to the bind mounts in `compose.yaml` (the `x-wp-content` anchor, the `wordpress` service, the `init` service, and both test services), and add it to the activation loop in `tools/init.sh`.

## Multisite (opt-in extension)

Multisite is off by default. The default `dev:up` runs `wp core install` for one plain site; `TEMPL_HEADLESS_MULTISITE=1` (via `composer dev:up:multisite` / `dev:reset:multisite`) runs `wp core multisite-install` instead and creates the sample subsite. `tools/init.sh` is the branch point, and switching mode needs an empty database, which is why there are separate `reset` scripts. The feature code is identical in both modes: the same CPTs, the same key store, the same routes. Multisite only changes how many isolated copies of them exist.

Reach for it when you host many customer sites on one install and need them isolated; a single site needs none of this and pays none of its complexity.

Everything below applies once multisite is on.

The MU plugin runs on every site of the network with no activation step, and that is what makes API keys per-site: the key post type is registered everywhere, so a key minted on subsite A lives in subsite A's tables and is invisible to subsite B.
This is not a convenience; it is the promise the agency model rests on, and `tests/multisite/KeyIsolationTest.php` is the test that guards it.

**A new subsite must work with zero activation steps.**
`WP_DEFAULT_THEME` in `compose.yaml` makes it headless the moment it exists, the MU plugin gives it the key store, and the feature plugins are network-activated so their routes and post types are already there.
`tests/multisite/NewSiteTest.php` creates a site with `wp_insert_site()` and asserts all of this; do not break it.

**Prefer per-site storage.**
Anything in a CPT, in post meta, or in a site option is automatically per-site.
Anything in a network option is shared across every tenant.
Reach for the former unless a value genuinely belongs to the whole network, because the default failure mode of the latter is one customer seeing another's data.
This matters under multisite; on a single site there is only one tenant, so a site option and a network option amount to the same thing.

## Auth contract

Consumer plugins authenticate through exactly one function and nothing else:

```php
'permission_callback' => 'Templ\\Headless\\Keys\\permission_callback',
```

A signature change in `mu-plugins/templ-headless/src/keys.php` is a change to every plugin on the network; treat that file as the public API it is.

- Keys arrive as `Authorization: Bearer <key>`, or `X-Templ-Api-Key: <key>` for clients that cannot set Authorization.
- **Query-string keys are refused.** A key in the URL ends up in access logs, browser history and the Referer header, and there is no way to un-leak it. Do not add support for one.
- Keys are 256 bits of `random_bytes` and stored as a plain SHA-256, not bcrypt: the entropy makes them unguessable, so a slow KDF would only tax every request. This is argued in `keys/hash.php`.
- **Every route is key-protected with exactly one exception:** `GET /templ-newsletter/v1/unsubscribe?token=...`, which is opened from an email client that cannot carry a key. The token is single-purpose and unguessable. **Adding any other unauthenticated route requires a note in this file explaining why**, because "authenticated by default" is the only thing that makes this repo safe to hand to a frontend.
- `templ_headless_authenticate` filters the final result, for an agency bolting on an IP allowlist or a second scheme without forking.

## Tests

Three suites, mirroring the reference plugin `~/templio/plugin`:

- **Unit** (`composer dev:test`, first half): Brain Monkey, no site booted. Only side-effect-free modules belong here: key hashing, the two validators, the unsubscribe token, the rate-limit arithmetic. Anything that calls `add_action` or `register_post_type` at include time is not a unit test.
- **Integration** (`composer dev:test`, second half): real HTTP against the running site, over the compose network. Real HTTP rather than `rest_do_request()` on purpose, because the `.htaccess` rule that hands the `Authorization` header to PHP is part of what is under test; an in-process dispatch would pass while a real client's header was being dropped.
- **Multisite** (`composer dev:test:multisite`): the same network, tests that cross site boundaries. Key isolation, data isolation, and zero-touch onboarding. Needs a multisite stack (`composer dev:reset:multisite` first); it fails loudly against a single-site install rather than skipping.

**A test that cannot fail is worse than no test.**
The integration bootstrap fails loudly when the stack is down or a plugin is missing rather than skipping, because a silently skipped suite turns "nothing is running" into a green tick.
The `UserEnumerationTest` asserts both that anonymous callers lose `/wp/v2/users` *and* that a logged-in caller keeps it; without the second half it would pass with the block editor's author dropdown broken.

**Cleanup discipline.**
Tests share a long-lived dev database, so every test purges what it created in `setUp`/`tearDown`.
Subscriber statuses are `exclude_from_search`, so a purge has to name every status explicitly (`array_keys( get_post_stati() )`); `'any'` silently leaves subscribers behind.

**Debugging a flaky integration test:**
- Every HTTP request in the suite arrives from the same container IP, so they share one rate-limit bucket. The contact form suite resets it per test; a new endpoint with its own limit needs the same.
- Stale opcache against bind mounts can make a fixed bug look unfixed. `tools/php-dev.ini` disables opcache timestamp caching for this reason; if a change is not taking, that is the first suspect.
- Leftover posts from a crashed run. `composer dev:reset` is the blunt fix.

## Lint

`WordPress` (WPCS 3.x) plus `PHPCompatibility`, layered in `phpcs.xml`.
Short array syntax is allowed against WPCS' advice; long array syntax is forbidden.
Prefixes are `Templ\Headless`, `TEMPL_HEADLESS_`, `templ_headless_`, `templ_contact_form_`, `templ_newsletter_`; text domains are `templ-headless`, `templ-headless-theme`, `templ-contact-form`, `templ-newsletter`.

**Every exclusion states why.**
The test tree relaxes a set of sniffs (PSR-4 filenames, braced multi-namespace bootstraps, direct DB access, short ternaries) because test code answers to PHPUnit's conventions and has to impersonate WordPress' global surface.
Each relaxation is scoped to `/tests/*` and carries a comment.
A new exclusion without a why-comment does not get merged.

## Version floors

PHP 8.3 and WordPress 7.1 appear in four places that must move together:

1. `Requires PHP` / `Requires at least` in every plugin and theme header,
2. `config.platform.php` in `composer.json`,
3. `minimum_wp_version` and `testVersion` in `phpcs.xml`,
4. the `wordpress:7.1-php8.3-apache` image tag in `compose.yaml`.

Changing one means changing all four.
The `php-floor` service parses every file on the bare interpreter, because `testVersion` is only as good as PHPCompatibility's sniff database.

## Known upgrade paths

**Keys as a CPT with a postmeta lookup is a boilerplate-scale choice.**
Verifying a key runs one indexed `postmeta` query per request, which is fine at tens of keys per site.
Somewhere around thousands of keys, or when key checks become a measurable share of request time, the move is a custom table with a `key_hash` index and a direct `$wpdb` lookup, keeping the `Keys\find()` signature so nothing else changes.
This is written down so the decision is not inherited as gospel.

**Rate limiting via transients** is free when an object cache is present and degrades to `options` writes when it is not.
The failure mode is a dropped counter, which only ever lets a request through, never wrongly blocks one.

## Deploy

`pnpm run deploy <target>` runs [`templ-deploy`](https://github.com/templhosting/templ-deploy), which rsyncs the `wp-content` overlay to a Templ host and runs a post-deploy `sshCmd`.
Configuration lives in `.templ.mjs` (gitignored, names real servers); `.templ.mjs.example` is the committed template.
What ships is `wp-content` and only `wp-content`, the same contract the dev stack keeps: core, uploads and `wp-config.php` live on the server and a deploy never touches them.

**On an existing install the MU plugin must be deployed before the feature plugins are activated**, because the feature plugins refuse to bootstrap without it (they degrade to an admin notice rather than a fatal, so a missed step is visible, not a white screen).

The example `sshCmd` activates the plugins for a single site; a multisite target adds `--network` to the activate.

## Extension points

Every hook the repo ships, with its intended use:

| Hook | Type | Use |
| --- | --- | --- |
| `templ_headless_authenticate` | filter | IP allowlist, alternative auth, internal bypass |
| `templ_contact_form_validate` | filter | add a field to the contact form |
| `templ_contact_form_rate_limit` | filter | change the submission ceiling per window |
| `templ_contact_form_submission_created` | action | send a notification email |
| `templ_newsletter_validate` | filter | add a field to the subscribe request |
| `templ_newsletter_subscribed` | action | add double opt-in |
| `templ_newsletter_unsubscribed` | action | sync an unsubscribe to another system |

The boilerplate uses none of these; they exist so an agency does not fork.
`docs/extending.md` walks through three of them.

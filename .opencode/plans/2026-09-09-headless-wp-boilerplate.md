# Headless WordPress Backend Boilerplate - Implementation Plan

## Overview

Build a greenfield, AI-first boilerplate repository for running WordPress as a headless backend on a multisite network.
An agency forks this repo, spins up the podman dev stack, and hosts many customer sites as subsites of one network.
Each subsite exposes REST APIs for a contact form and a newsletter, protected by per-site API keys that are managed from WP Admin and from WP-CLI.

## Current State Analysis

The repository at `/Users/emanuel/templio/headless-wp-backend` is empty apart from `.git`.
Everything in this plan is new code.

The conventions are inherited from `~/templio/plugin` (the `templ-companion` plugin), which is the reference implementation for how Templ writes WordPress PHP:

- Namespaced plain functions, no classes, one namespace per file, module constants as `const`.
- Every module exposes exactly one `bootstrap(): void` that is the only place hooks get registered.
- No production autoloader, because PSR-4 cannot autoload namespaced functions; the main file `require_once`s each module explicitly.
- Toolchain lives in containers; there is no host PHP requirement.
- Tests are split into a Brain Monkey unit suite plus container-backed integration and multisite suites; there is no wp.org-style `wp-phpunit` scaffold.
- `phpcs.xml` layers `WordPress` (WPCS 3.x) and `PHPCompatibility`, allows short array syntax, and excludes `WordPress.Files.FileName.InvalidClassFileName` because files hold namespaced functions rather than classes.
- Every phpcs exclusion and every compose comment states *why*, not *what*.
- `AGENTS.md` is a decision record ("decisions already paid for"), `CONTEXT.md` is a pure domain glossary.

## Desired End State

A fresh clone plus `composer dev:up` yields a running WordPress multisite at `http://localhost:8080` with:

- A network admin site and one sample subsite at `http://localhost:8080/customer-one/`.
- A headless theme network-activated, so any frontend request redirects to `wp-login.php`.
- The `templ-headless` MU plugin present on every subsite, providing API key management in WP Admin and via WP-CLI.
- The contact form and newsletter plugins network-activated, each with a versioned REST namespace and an admin submissions screen.
- `composer lint`, `composer dev:test`, and `composer dev:test:multisite` all green.
- `AGENTS.md`, `CONTEXT.md`, and `README.md` sufficient for an agent to add a new endpoint or a new plugin without asking questions.

### Key Decisions (settled)

- **Repo layout**: `wp-content` overlay bind-mounted into the official `wordpress` image. WordPress core is never in git.
- **No frontend**: a minimal headless theme redirects all frontend requests to `wp_login_url()`.
- **API key scope**: per-site. A key minted on subsite A only unlocks subsite A.
- **API key storage**: a private custom post type. Only a SHA-256 hash and a display prefix are stored; plaintext is shown once at creation.
- **Submission auth**: every endpoint requires a key. The headless frontend is expected to proxy submissions through its own server. Rate limiting and a honeypot exist anyway.
- **Newsletter**: single opt-in, no email is ever sent.
- **Contact form**: fixed field schema (name, email, subject, message).
- **Multisite**: subdirectory install, multisite by default in the dev stack.
- **Naming**: namespace root `Templ\Headless\`, function prefix `templ_headless_`, constant prefix `TEMPL_HEADLESS_`, per-package text domains.
  Sub-namespacing under `Templ\Headless\` is deliberate: `templ-companion` already occupies `Templ\Rest`, `Templ\Cache`, and the `templ_`/`TEMPL_` prefixes, and both codebases can end up on the same site.
- **Floors**: PHP 8.3, WordPress 7.1.
- **CI**: out of scope.

## What We're NOT Doing

- No GitHub Actions or any other CI configuration.
- No email sending anywhere: no `wp_mail` calls, no mailpit container, no admin notification on submission, no double opt-in.
  Both are documented extension points with the filter/action names already in place.
- No campaign sending, segmentation, or list management beyond subscribe and unsubscribe.
- No configurable/multi-form builder for the contact form.
- No network-wide API keys and no key scopes (read vs write).
- No custom database tables. Everything is post types, post meta, and options.
- No block editor work, no Gutenberg blocks, no frontend assets beyond a few kilobytes of admin CSS.
- No release/packaging pipeline (`build-zip.sh`, `release.sh` and friends from `templ-companion` are not ported).
- No subdomain multisite dev stack. Subdomain differences are documented, not automated.
- No GraphQL, no WPGraphQL integration.
- No user-facing REST for core content types beyond what WordPress ships by default.

## Implementation Approach

The repo is a `wp-content` overlay.
`compose.yaml` bind-mounts the repo's directories into the official `wordpress` image, so core, uploads, and the database stay inside containers and out of git:

```
.
├── compose.yaml
├── composer.json
├── phpcs.xml
├── phpunit.xml
├── phpunit-integration.xml
├── phpunit-multisite.xml
├── AGENTS.md
├── CONTEXT.md
├── README.md
├── wp-content/
│   ├── mu-plugins/
│   │   ├── templ-headless.php        # loader: WP does not recurse into mu-plugin subdirs
│   │   ├── templ-updates.php         # restores core auto-updates under a git checkout
│   │   └── templ-headless/
│   │       ├── plugin.php
│   │       └── src/
│   │           ├── keys.php
│   │           ├── keys/store.php
│   │           ├── keys/hash.php
│   │           ├── rest.php
│   │           ├── admin.php
│   │           ├── admin/screen.php
│   │           └── cli.php
│   ├── plugins/
│   │   ├── templ-contact-form/
│   │   │   ├── templ-contact-form.php
│   │   │   └── src/{post-type.php,rest.php,validation.php,admin.php,rate-limit.php}
│   │   └── templ-newsletter/
│   │       ├── templ-newsletter.php
│   │       └── src/{post-type.php,rest.php,validation.php,admin.php,token.php}
│   └── themes/
│       └── templ-headless-theme/
│           ├── style.css
│           ├── index.php
│           └── functions.php
├── docs/
│   ├── api.md
│   └── extending.md
├── tests/
│   ├── bootstrap-unit.php
│   ├── bootstrap-integration.php
│   ├── bootstrap-multisite.php
│   ├── unit/
│   ├── integration/
│   ├── multisite/
│   └── shared/
└── tools/
    ├── init.sh
    ├── php-dev.ini
    └── check-php-floor.sh
```

Each phase is independently verifiable.
Phases 1 to 5 build the runtime; phase 6 adds the safety net; phase 7 writes the documentation that makes the repo AI-first.

---

## Phase 1: Repo Skeleton and Podman Dev Environment

### Overview

Get a WordPress multisite running from a bind-mounted `wp-content` overlay with a single command, plus the lint toolchain.

### Changes Required

#### 1. `compose.yaml`

Podman compose (Docker compatible), `name: templ-headless`.
Services:

- **`db`**: `mariadb:11`, named volume for data, healthcheck with `healthcheck.sh --connect --innodb_initialized`.
- **`wordpress`**: `wordpress:7.1-php8.3-apache` (pinned, never `latest`; the version floor is a contract with `phpcs.xml` and the plugin headers), port `8080:80`, `depends_on: db (service_healthy)`.
  Bind mounts:
  - `./mu-plugins:/var/www/html/wp-content/mu-plugins`
  - `./plugins/templ-contact-form:/var/www/html/wp-content/plugins/templ-contact-form`
  - `./plugins/templ-newsletter:/var/www/html/wp-content/plugins/templ-newsletter`
  - `./themes/templ-headless-theme:/var/www/html/wp-content/themes/templ-headless-theme`
  - `./tools/php-dev.ini:/usr/local/etc/php/conf.d/dev.ini`
  - `./tests:/var/www/html/tests`, `./phpunit-integration.xml`, `./phpunit-multisite.xml`, `./composer.json`, `./vendor` (needed by the container-run test suites)

  `WORDPRESS_CONFIG_EXTRA` defines the multisite constants:

  ```php
  define( 'WP_ALLOW_MULTISITE', true );
  define( 'MULTISITE', true );
  define( 'SUBDOMAIN_INSTALL', false );
  define( 'DOMAIN_CURRENT_SITE', 'localhost:8080' );
  define( 'PATH_CURRENT_SITE', '/' );
  define( 'SITE_ID_CURRENT_SITE', 1 );
  define( 'BLOG_ID_CURRENT_SITE', 1 );
  define( 'WP_DEBUG', true );
  define( 'WP_DEBUG_LOG', true );
  define( 'DISALLOW_FILE_MODS', true );
  ```

  A comment must explain that the multisite constants are set here rather than written by `wp core multisite-convert`, because the container's `wp-config.php` is regenerated on every recreate.

- **`init`**: `wordpress:cli-php8.3`, `network_mode: "service:wordpress"`, runs `tools/init.sh`, `restart: "no"`.
- **`tests`** (profile `tools`): `wordpress:cli-php8.3`, `network_mode: "service:wordpress"` so loopback HTTP requests to `localhost` reach Apache, runs `phpunit -c phpunit-integration.xml`.
- **`tests-multisite`** (profile `tools`): same, `phpunit-multisite.xml`.
- **`composer`**, **`lint`**, **`unit`** (profile `tools`): `composer:2` image, working dir `/app`, repo bind-mounted.
- **`php-floor`** (profile `tools`): `php:8.3-cli`, asserts the floor still parses.

`.gitignore`: `vendor/`, `.phpunit.result.cache`, `*.log`, `.DS_Store`.

#### 2. `tools/init.sh`

Idempotent provisioning, safe to re-run.
Every step guarded so a second run is a no-op:

1. `wp core is-installed --network` guard, else `wp core multisite-install` with title, admin user `admin`/`password`, `--url=http://localhost:8080`, `--skip-email`.
2. Create subsite `customer-one` via `wp site create --slug=customer-one` if `wp site list --field=slug` does not contain it.
3. `wp theme activate templ-headless-theme` on the network, and `wp theme enable templ-headless-theme --network`.
4. `wp plugin activate templ-contact-form templ-newsletter --network`.
5. Print a summary block with the admin URL, network admin URL, and subsite URL.

The script must fail loudly (`set -euo pipefail`) so a broken stack does not silently look healthy.

#### 3. `composer.json`

- `name: templ/headless-wp-backend`, `type: project`, license proprietary or MIT (decide at write time; MIT for a boilerplate).
- `config.platform.php: 8.3`, `sort-packages: true`.
- `require-dev`: `brain/monkey ^2.6`, `phpunit/phpunit ^9.6`, `squizlabs/php_codesniffer ^3.9`, `wp-coding-standards/wpcs ^3.1`, `dealerdirect/phpcodesniffer-composer-installer ^1.0`, `phpcompatibility/php-compatibility ^10.0`, `php-stubs/wordpress-stubs` is *not* included (Brain Monkey covers the unit suite).
- `autoload-dev` PSR-4: `Templ\Headless\Tests\{Unit,Shared,Integration,Multisite}\` mapping to `tests/{unit,shared,integration,multisite}/`.
- No production `autoload` block, matching `templ-companion`.
- Scripts:

  ```
  lint                 phpcs
  lint:fix             phpcbf
  test                 phpunit -c phpunit.xml
  test:integration     phpunit -c phpunit-integration.xml
  test:multisite       phpunit -c phpunit-multisite.xml
  dev:up               podman compose up -d --wait
  dev:down             podman compose down
  dev:reset            podman compose down -v && podman compose up -d --wait
  dev:logs             podman compose logs -f wordpress
  dev:cli              podman compose run --rm cli
  dev:install          podman compose run --rm composer install
  dev:lint             podman compose run --rm lint
  dev:test             podman compose run --rm unit && podman compose run --rm tests
  dev:test:multisite   podman compose run --rm tests-multisite
  ```

#### 4. `phpcs.xml`

Modelled on `templ-companion`'s, with the same "every exclusion states its reason" discipline:

- Rules: `WordPress`, `PHPCompatibility`.
- `minimum_wp_version` 7.1, `testVersion` `8.3-`.
- Exclude `WordPress.Files.FileName.InvalidClassFileName` (files hold namespaced functions, not classes).
- Exclude `Universal.Arrays.DisallowShortArraySyntax`, include `Generic.Arrays.DisallowLongArraySyntax`.
- Prefixes: `Templ\Headless`, `TEMPL_HEADLESS_`, `templ_headless_`, `templ_contact_form_`, `templ_newsletter_`.
- Text domains: `templ-headless`, `templ-contact-form`, `templ-newsletter`.
- Files: `mu-plugins`, `plugins`, `themes`, `tests`. Exclude `vendor`, `node_modules`.
- `tests/` relaxations: PSR-4 filenames, `WordPress.PHP.DevelopmentFunctions`, commenting sniffs, `WordPress.DB.DirectDatabaseQuery`.

#### 5. `tools/php-dev.ini`

`opcache.revalidate_freq=0` and `opcache.validate_timestamps=1`.
Comment must say this exists because stale opcache against bind mounts causes test flakiness that looks like real failures.

### Success Criteria

#### Automated Verification:

- [ ] `podman compose config` parses without error
- [ ] `composer dev:up` exits 0 and `podman compose ps` shows `wordpress` healthy
- [ ] `podman compose run --rm cli wp core is-installed --network` exits 0
- [ ] `podman compose run --rm cli wp site list --format=count` returns `2`
- [ ] `composer dev:install && composer dev:lint` exits 0 (trivially, on an almost empty tree)
- [ ] `composer dev:reset && composer dev:up` reprovisions cleanly from an empty volume

#### Manual Verification:

- [ ] `http://localhost:8080/wp-admin/` logs in with admin/password
- [ ] `http://localhost:8080/wp-admin/network/sites.php` lists both sites
- [ ] `http://localhost:8080/customer-one/wp-admin/` loads that subsite's dashboard
- [ ] Editing a PHP file on the host is reflected on the next request with no container restart

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 2: Headless Theme

### Overview

WordPress requires an active theme.
Ship the smallest possible one and make the frontend cease to exist as a public surface.

### Changes Required

#### 1. `themes/templ-headless-theme/style.css`

Theme header only: name `Templ Headless`, description explaining this theme exists so WordPress has an active theme and that all frontend traffic is redirected to `wp-login.php`, version, requires PHP 8.3, requires at least WP 7.1, text domain `templ-headless-theme`.

#### 2. `themes/templ-headless-theme/index.php`

Required by WordPress to consider the directory a theme.
Contains only an `ABSPATH` guard and a comment stating it is intentionally empty because `functions.php` redirects before the template loader runs.

#### 3. `themes/templ-headless-theme/functions.php`

```php
namespace Templ\Headless\Theme;

function bootstrap(): void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_frontend' );
	add_action( 'after_setup_theme', __NAMESPACE__ . '\\disable_frontend_features' );
	add_filter( 'rest_url_prefix', ... ); // not modified; documented as untouched
}
```

`redirect_frontend()`:

- Returns early for `is_robots()`, `is_favicon()`, and any REST request (`REST_REQUEST`), and for logged-in users with `edit_posts` so previews still work while authoring.
  The preview exemption is a deliberate concession, not an oversight; a comment must say so.
- Otherwise `wp_safe_redirect( wp_login_url(), 302 )` and `exit`.
  302 rather than 301 so browsers do not cache the redirect if a real frontend is ever attached to the domain later.

`disable_frontend_features()`: remove emoji scripts, the RSD/WLW manifest links, the generator tag, and `feed_links`, since none of them make sense without a frontend.

Also disable the theme's own file editor exposure by relying on `DISALLOW_FILE_MODS` (set in compose), noted in a comment rather than re-defined.

#### 4. `tools/init.sh`

Add `wp theme enable` + `wp theme activate` steps (already listed in phase 1, wired now).
Also `wp theme delete twentytwentyfour twentytwentyfive` guarded by existence checks, so the network ships without default themes.

### Success Criteria

#### Automated Verification:

- [ ] `composer dev:lint` exits 0
- [ ] `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' http://localhost:8080/` returns `302` and a `wp-login.php` URL
- [ ] `curl -sS -o /dev/null -w '%{redirect_url}' 'http://localhost:8080/?author=1'` returns the login URL and **not** an `/author/<slug>/` URL (the theme must beat `redirect_canonical`, which leaks login names otherwise)
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/customer-one/` returns `302`
- [ ] `curl -sS http://localhost:8080/wp-json/` returns HTTP 200 and valid JSON (REST is unaffected)
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/wp-admin/` returns `302` to login, not a redirect loop

#### Manual Verification:

- [ ] Visiting `http://localhost:8080/` in a browser lands on the login form, and logging in from there reaches the dashboard rather than bouncing
- [ ] With the network admin logged in, previewing a draft post still renders (the `edit_posts` exemption works)
- [ ] Network admin > Themes shows only the headless theme

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 3: MU Plugin - API Keys

### Overview

The `templ-headless` MU plugin owns authentication for every other plugin in the network.
It defines the key post type, the hashing rules, the admin UI, the WP-CLI commands, and the single public function that consumer plugins call from their `permission_callback`.

### Changes Required

#### 1. `mu-plugins/templ-headless.php`

WordPress does not recurse into mu-plugin subdirectories, so this loader file is required:

```php
require_once __DIR__ . '/templ-headless/plugin.php';
```

Nothing else lives here.
A comment must state the reason, because it looks like pointless indirection otherwise.

#### 2. `mu-plugins/templ-headless/plugin.php`

Plugin header, `namespace Templ\Headless;`, `const VERSION`, `define()` of `TEMPL_HEADLESS_DIR` and `TEMPL_HEADLESS_URL`, explicit `require_once` of each `src/` module, a top-level `bootstrap()` that calls each module's `bootstrap()`, then `bootstrap();`.

#### 3. `src/keys/hash.php` - `namespace Templ\Headless\Keys\Hash`

Pure functions, no WordPress state, so they are fully unit testable:

- `const PREFIX = 'thl_';`
- `const SECRET_BYTES = 32;`
- `generate(): string` returns `PREFIX . bin2hex( random_bytes( SECRET_BYTES ) )`.
- `hash( string $plaintext ): string` returns `hash( 'sha256', $plaintext )`.
  A comment must explain why this is a plain SHA-256 and not `wp_hash_password`/bcrypt: API keys are 256 bits of `random_bytes` entropy, so they are not brute-forceable and do not need a slow KDF, and every API request would otherwise pay the bcrypt cost.
- `display_prefix( string $plaintext ): string` returns the first 12 characters, used to identify a key in lists.
- `looks_like_key( string $value ): bool` cheap shape check so malformed input is rejected before a database round trip.

#### 4. `src/keys/store.php` - `namespace Templ\Headless\Keys\Store`

Owns the post type and all data access.
The key CPT:

- `const POST_TYPE = 'templ_api_key';`
- `register_post_type()` with `public => false`, `show_ui => false` (the admin screen is custom, phase 3.6), `show_in_rest => false`, `exclude_from_search => true`, `publicly_queryable => false`, `capability_type` mapped so only `manage_options` can touch it, `supports => array( 'title' )`.
  A comment must state that `show_in_rest => false` is a security boundary, not a preference.
- Registered on every subsite (the MU plugin runs everywhere), which is exactly what gives per-site scoping for free.

Meta keys (all registered with `register_post_meta` using `single => true`, `show_in_rest => false`, and an `auth_callback` returning false):

- `_templ_headless_key_hash`
- `_templ_headless_key_prefix`
- `_templ_headless_last_used_at`
- `_templ_headless_revoked_at`

Functions:

- `create( string $label ): array` generates the plaintext, inserts the post, writes hash and prefix meta, and returns `array( 'id' => int, 'plaintext' => string, 'prefix' => string )`.
  This is the only function that ever returns plaintext, and it must document that the caller has one chance to show it.
- `find_by_hash( string $hash ): ?WP_Post` uses `get_posts()` with a `meta_key`/`meta_value` query limited to 1, `post_status => 'publish'`, `no_found_rows => true`.
  A comment must note the meta query is fine at boilerplate scale and point to the custom-table upgrade path described in `AGENTS.md`.
- `all(): array` for the admin list, ordered by date.
- `revoke( int $id ): bool` sets `post_status => 'draft'` and stamps `_templ_headless_revoked_at`.
  Revoking is soft so the admin list retains an audit trail; a comment must say so.
- `delete( int $id ): bool` for hard removal.
- `touch_last_used( int $id ): void` writes `_templ_headless_last_used_at` at most once per hour, guarded by a transient, so a busy endpoint does not write meta on every request.

#### 5. `src/keys.php` - `namespace Templ\Headless\Keys`

The public contract other plugins consume.
This file is the cross-plugin API and its docblock must say so explicitly.

- `bootstrap(): void` wires the store's post type registration.
- `extract_from_request( \WP_REST_Request $request ): ?string` reads `Authorization: Bearer <key>`, falling back to the `X-Templ-Api-Key` header.
  Query-string keys are deliberately not supported because they leak into access logs; a comment must say so.
- `verify( string $plaintext ): ?\WP_Post` hashes and looks up, returns null for revoked keys.
- `authenticate( \WP_REST_Request $request ): true|\WP_Error` returns `WP_Error` with status 401 and code `templ_headless_missing_key` or `templ_headless_invalid_key`.
- `permission_callback(): callable` returns a closure suitable for direct use as a REST `permission_callback`.
  This is what `templ-contact-form` and `templ-newsletter` call.
- `hash_equals` is used for any comparison that touches secrets, even though the lookup is by hash.

Filter hook `templ_headless_authenticate` (final result) so an agency can bolt on IP allowlisting or an alternative auth scheme without forking.

#### 6. `src/admin.php` + `src/admin/screen.php` - `namespace Templ\Headless\Admin`

- Registers a submenu page under Settings on every subsite: `options-general.php?page=templ-headless-keys`, capability `manage_options`.
- The screen lists keys (label, prefix, created, last used, status) and has a create form and per-row revoke and delete actions.
- Create and revoke are handled via `admin_post_*` actions with nonces and a capability check, then a redirect back with a query flag (post/redirect/get, so a refresh does not re-mint a key).
- Newly created plaintext is passed through a short-lived user-scoped transient rather than the query string, so the secret never enters browser history or server logs.
  A comment must state that reason.
- Output uses `WP_List_Table` only if it stays simple; otherwise a plain `<table class="wp-list-table widefat">` rendered by a function in `admin/screen.php`.
  Prefer the plain table: `WP_List_Table` is a class-based, semi-private API and conflicts with the no-god-class rule.
- All output escaped with `esc_html`, `esc_attr`, `esc_url`.

#### 7. `src/cli.php` - `namespace Templ\Headless\Cli`

Guarded by `defined( 'WP_CLI' ) && WP_CLI`.
Registers commands with closures wrapping the module functions, so no command class is needed:

- `wp templ-headless key create <label>` prints the plaintext once, supports `--porcelain`.
- `wp templ-headless key list` supports `--format=table|json|csv`, never prints hashes.
- `wp templ-headless key revoke <id>`.
- `wp templ-headless key delete <id> [--yes]`.

Every command is per-site via the standard `--url=` flag; the command docblocks must say so, because that is the only way to reach a subsite's keys.

#### 8. `src/rest.php` - `namespace Templ\Headless\Rest`

- `const NAMESPACE_V1 = 'templ-headless/v1';`
- One route: `GET /ping`, key protected, returns `array( 'site_id' => get_current_blog_id(), 'ok' => true )`.
  This exists so an integration can verify a key without side effects, and so the test suite has a trivial endpoint to prove the auth layer independent of the feature plugins.
- Route paths only ever composed from constants; literals appear only in tests, because that is what proves the route exists under that name.

### Success Criteria

#### Automated Verification:

- [ ] `composer dev:lint` exits 0
- [ ] `podman compose run --rm cli wp templ-headless key create "test" --url=http://localhost:8080/customer-one/ --porcelain` prints a `thl_`-prefixed key
- [ ] `curl -sS -H "Authorization: Bearer $KEY" http://localhost:8080/customer-one/wp-json/templ-headless/v1/ping` returns 200 with the correct `site_id`
- [ ] The same key against `http://localhost:8080/wp-json/templ-headless/v1/ping` returns 401 (per-site isolation)
- [ ] A request with no header returns 401 with code `templ_headless_missing_key`
- [ ] A revoked key returns 401 with code `templ_headless_invalid_key`
- [ ] `curl -sS http://localhost:8080/customer-one/wp-json/wp/v2/templ_api_key` returns 404 (the CPT is not REST exposed)

#### Manual Verification:

- [ ] Settings > API Keys appears on both the main site and the subsite, with independent lists
- [ ] Creating a key shows the plaintext exactly once, and refreshing the page does not show it again or create a second key
- [ ] Revoke and delete work, and revoked keys remain visible with a revoked status
- [ ] An editor-role user cannot see the API Keys menu

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 4: Contact Form Plugin

### Overview

A regular (network-activated) plugin with a fixed-schema submission endpoint, a submission post type, and an admin screen to read submissions.

### Changes Required

#### 1. `plugins/templ-contact-form/templ-contact-form.php`

Standard header (`Requires at least: 7.1`, `Requires PHP: 8.3`, `Network: true`), constants, explicit `require_once` of `src/` modules, top-level `bootstrap()`.

A hard dependency check: if `function_exists( 'Templ\\Headless\\Keys\\permission_callback' )` is false, register an admin notice and return without bootstrapping.
Never fatal; a comment must state that a missing MU plugin must degrade, not white-screen.

#### 2. `src/post-type.php` - `namespace Templ\Headless\ContactForm\PostType`

- `const POST_TYPE = 'templ_submission';`
- `public => false`, `show_ui => false` (custom screen), `show_in_rest => false`, `exclude_from_search => true`, `supports => array( 'title' )`.
- Meta: `_templ_cf_name`, `_templ_cf_email`, `_templ_cf_subject`, `_templ_cf_message`, `_templ_cf_ip_hash`, `_templ_cf_user_agent`, `_templ_cf_source_url`.
- The IP is stored hashed (`sha256` of IP plus `wp_salt()`), never in the clear, so rate limiting and abuse investigation are possible without retaining personal data.
  A comment must state this is a GDPR-motivated choice.
- `create( array $fields ): int|\WP_Error` is the only writer.
- `all( array $args ): array` for the admin screen and the list endpoint.
- `to_array( \WP_Post $post ): array` shapes the REST response, so the response schema lives in exactly one place.

#### 3. `src/validation.php` - `namespace Templ\Headless\ContactForm\Validation`

Pure functions, unit tested without WordPress:

- `validate( array $input ): array|\WP_Error` checks: `name` required, 1-100 chars; `email` required and `is_email`; `subject` optional, max 200; `message` required, 1-5000 chars.
- Returns a sanitized array (`sanitize_text_field`, `sanitize_email`, `sanitize_textarea_field`).
- Errors are a single `WP_Error` with all field errors attached, so a client gets everything in one round trip.
- Filter `templ_contact_form_validate` so agencies can add fields.

#### 4. `src/rate-limit.php` - `namespace Templ\Headless\ContactForm\RateLimit`

- Transient-keyed on the hashed IP: `const MAX_PER_WINDOW = 5; const WINDOW = HOUR_IN_SECONDS;`
- `check( string $ip_hash ): true|\WP_Error` returns a 429 `WP_Error`.
- Filter `templ_contact_form_rate_limit` returning `array( max, window )`.
- A comment must state that transients are the deliberate choice over a table: this is a boilerplate, object-cache-backed transients make this free on real hosting, and the failure mode (a dropped counter) is acceptable.

#### 5. `src/rest.php` - `namespace Templ\Headless\ContactForm\Rest`

- `const NAMESPACE_V1 = 'templ-contact-form/v1';`
- `POST /submissions`: key protected, honeypot field `website` must be empty (silently returns 201 with a fake success so bots learn nothing; comment must say so), validation, rate limit, create, returns 201 with `array( 'id', 'created_at' )`.
- `GET /submissions`: key protected, supports `page`, `per_page` (max 100), `search`, returns items plus `X-WP-Total` and `X-WP-TotalPages` headers, matching core REST conventions.
- `GET /submissions/(?P<id>\d+)`: key protected, single submission.
- `DELETE /submissions/(?P<id>\d+)`: key protected.
- All routes declare a full `args` schema with `sanitize_callback` and `validate_callback`, so WordPress does the first pass of validation.
- Action `templ_contact_form_submission_created` fires after insert, with the post ID.
  This is the documented extension point for sending a notification email; the boilerplate does not use it.

#### 6. `src/admin.php` - `namespace Templ\Headless\ContactForm\Admin`

Top-level menu "Contact Form" with a submissions list (date, name, email, subject, truncated message), a single-submission view, a delete action with nonce, and pagination.
Same plain-table approach as the keys screen.
Capability `edit_posts`, so an agency's client editors can read submissions without being full admins.

### Success Criteria

#### Automated Verification:

- [ ] `composer dev:lint` exits 0
- [ ] `POST /wp-json/templ-contact-form/v1/submissions` with a valid key and body returns 201 and an id
- [ ] The same POST without a key returns 401
- [ ] A POST missing `message` returns 400 with a field-level error for `message`
- [ ] A POST with `website` filled returns 201 but `GET /submissions` count does not increase
- [ ] The 6th POST within the window returns 429
- [ ] `GET /submissions` returns the created submission and an `X-WP-Total` header
- [ ] A submission created on `customer-one` is not visible via the main site's `GET /submissions`

#### Manual Verification:

- [ ] Contact Form menu appears on each subsite with only that subsite's submissions
- [ ] The single submission view renders the full message with no HTML injection (test by submitting `<script>alert(1)</script>`)
- [ ] Deleting a submission from the admin works and survives a page refresh
- [ ] Deactivating the MU plugin loader shows the admin notice instead of a fatal error

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 5: Newsletter Plugin

### Overview

Single opt-in list capture.
Structurally a near-twin of the contact form plugin, which is intentional: two similar plugins make the pattern obvious to an agent adding a third.

### Changes Required

#### 1. `plugins/templ-newsletter/templ-newsletter.php`

Same shape and same MU-plugin dependency guard as phase 4.

#### 2. `src/post-type.php` - `namespace Templ\Headless\Newsletter\PostType`

- `const POST_TYPE = 'templ_subscriber';`
- Same privacy flags as the submission CPT.
- Post title is the email address, which makes the admin search work for free.
- Status is carried in `post_status` using two registered custom statuses, `templ_subscribed` and `templ_unsubscribed`, rather than meta, so `WP_Query` counts are cheap.
  A comment must state that reasoning.
- Meta: `_templ_nl_name`, `_templ_nl_source_url`, `_templ_nl_ip_hash`, `_templ_nl_token`, `_templ_nl_subscribed_at`, `_templ_nl_unsubscribed_at`.
- `find_by_email( string $email ): ?\WP_Post` for idempotent subscribe.
- `subscribe( string $email, array $extra ): array|\WP_Error` is idempotent: re-subscribing an existing address flips its status back and returns the same record rather than erroring or duplicating.
  A comment must state that idempotency is deliberate, because a headless frontend will retry.
- `unsubscribe( \WP_Post $post ): bool`.

#### 3. `src/token.php` - `namespace Templ\Headless\Newsletter\Token`

- `generate(): string` = 32 bytes hex.
- Stored per subscriber, used for the keyless unsubscribe link a frontend can embed in emails it sends itself.
- `find_subscriber( string $token ): ?\WP_Post` with a constant-time comparison.

#### 4. `src/validation.php` - `namespace Templ\Headless\Newsletter\Validation`

`validate( array $input ): array|\WP_Error`: `email` required and `is_email`, `name` optional max 100, honeypot `website` must be empty.
Filter `templ_newsletter_validate`.

#### 5. `src/rest.php` - `namespace Templ\Headless\Newsletter\Rest`

- `const NAMESPACE_V1 = 'templ-newsletter/v1';`
- `POST /subscribers`: key protected, returns 201 (new) or 200 (already subscribed, re-activated).
- `GET /subscribers`: key protected, `status` filter, pagination, total headers.
- `DELETE /subscribers/(?P<id>\d+)`: key protected, hard delete for GDPR erasure requests.
- `POST /subscribers/unsubscribe`: key protected, by email.
- `GET /unsubscribe?token=...`: **not** key protected, because it is opened from an email client.
  It flips status and returns a minimal JSON body.
  The token is single-purpose, unguessable, and only ever unsubscribes, so it is safe unauthenticated; a comment must say exactly that, since it is the one deliberate exception to the key-required rule.
- Actions `templ_newsletter_subscribed` and `templ_newsletter_unsubscribed`.
  The former is the documented hook for adding double opt-in.

#### 6. `src/admin.php` - `namespace Templ\Headless\Newsletter\Admin`

Top-level menu "Newsletter": subscriber list (email, name, status, subscribed at), status filter tabs with counts, delete action, and a CSV export button (`admin_post` handler streaming `text/csv` with a nonce and `edit_posts`).
The CSV export exists because it is the one thing every agency asks for on day one.

### Success Criteria

#### Automated Verification:

- [ ] `composer dev:lint` exits 0
- [ ] `POST /wp-json/templ-newsletter/v1/subscribers` with a key returns 201
- [ ] The identical POST repeated returns 200 and `GET /subscribers` still shows one record
- [ ] Unsubscribing then re-subscribing returns the same subscriber id with status `templ_subscribed`
- [ ] `GET /unsubscribe?token=<valid>` without a key returns 200 and flips the status
- [ ] `GET /unsubscribe?token=garbage` returns 404, not 500
- [ ] `GET /subscribers?status=templ_unsubscribed` returns only unsubscribed records
- [ ] Subscribers on `customer-one` are invisible from the main site's endpoints

#### Manual Verification:

- [ ] Newsletter menu shows per-subsite lists with correct status counts
- [ ] CSV export downloads and opens with correct columns and escaping
- [ ] Submitting an email with unusual casing or whitespace normalizes rather than duplicating

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 6: Test Suites

### Overview

Three suites, mirroring `templ-companion`: fast pure-unit tests with Brain Monkey, container-backed integration tests against the real running site, and a multisite suite that proves tenant isolation.
Tests are written last only in the sense that they are formalized here; each earlier phase's automated criteria become these tests.

### Changes Required

#### 1. `tests/bootstrap-unit.php`

Braced multi-namespace file:

- Define `ABSPATH`, `WPINC`, `HOUR_IN_SECONDS`, `MINUTE_IN_SECONDS`, `DAY_IN_SECONDS`.
- Minimal `WP_Post`, `WP_Error`, `WP_REST_Request`, `WP_REST_Response` stubs where Brain Monkey's do not suffice.
- `require_once` each pure module: the two `validation.php` files, `keys/hash.php`, `token.php`, `rate-limit.php`.
  Only side-effect-free modules go here; anything that calls `add_action` at include time belongs to the integration suite.

#### 2. `tests/unit/`

- `Keys/HashTest.php`: prefix shape, hash stability, `looks_like_key` rejects malformed input, `display_prefix` length, generated keys are unique across many iterations.
- `ContactForm/ValidationTest.php`: each required field, boundary lengths, email formats, error aggregation, honeypot.
- `Newsletter/ValidationTest.php`: same shape.
- `Newsletter/TokenTest.php`: length, uniqueness.
- `ContactForm/RateLimitTest.php`: window arithmetic with `Brain\Monkey\Functions\when('get_transient')` stubs.

#### 3. `tests/bootstrap-integration.php`

Loads the real site via `wp-load.php` at `TEMPL_WP_LOAD` or `/var/www/html/wp-load.php`.
Fails loudly with an actionable message if the stack is down, or if the MU plugin function or either plugin is not present.
A silently skipped suite is worse than a failing one.

#### 4. `tests/shared/`

- `MakesRequests.php`: a trait wrapping `wp_remote_request` against `home_url()`, returning decoded body plus status plus headers.
- `MintsKeys.php`: creates a key on the current site, returns the plaintext, and registers cleanup.
- `CleansUp.php`: deletes posts created during a test in `tearDown`, so a re-run against a long-lived dev database is deterministic.

#### 5. `tests/integration/`

- `KeysTest.php`: create, verify, revoke, hash never returned, last-used-at updates.
- `KeysRestTest.php`: the `/ping` matrix (no header, bad key, revoked key, good key).
- `ContactFormRestTest.php`: full CRUD matrix, validation errors with field codes, honeypot silently discards, rate limit 429, pagination headers, XSS payload stored and returned escaped.
- `NewsletterRestTest.php`: subscribe idempotency, unsubscribe by token without a key, bad token 404, status filtering, delete.
- `ThemeTest.php`: frontend 302 to login, REST unaffected, `wp-admin` reachable, and `?author=N` redirecting to login rather than to an author archive.
- `UserEnumerationTest.php`: every `/wp/v2/users*` route is 404 for an anonymous caller and absent from the anonymous route index, while a logged-in request with a valid nonce still reaches it. The second half is the one that matters: without it the test passes just as well with the block editor broken.
- `CliTest.php`: shells out to `wp templ-headless key create|list|revoke` and asserts output shape.

#### 6. `tests/bootstrap-multisite.php` + `tests/multisite/`

Same loader, but every test runs against the network.

- `KeyIsolationTest.php`: a key minted on site A returns 401 on site B, for every endpoint of every plugin.
  This is the single most important test in the repo, because it is the promise the agency model rests on.
- `DataIsolationTest.php`: submissions and subscribers created on site A never appear in site B's list endpoints or admin queries.
- `NewSiteTest.php`: `wp_insert_site()` produces a site where the key admin screen, both CPTs, and all routes exist without any activation step.
- `NetworkAdminTest.php`: a network admin is not implicitly authorized to call a subsite's API without a key.

#### 7. PHPUnit configs

Three files matching `templ-companion`: `phpunit.xml` (unit), `phpunit-integration.xml`, `phpunit-multisite.xml`.
Test suffix `Test.php`, `cacheResult=false` for the container suites.

### Success Criteria

#### Automated Verification:

- [ ] `composer dev:test` runs the unit suite and the integration suite, both green
- [ ] `composer dev:test:multisite` is green
- [ ] `composer dev:reset && composer dev:up && composer dev:test && composer dev:test:multisite` is green from a cold database
- [ ] Running `composer dev:test` twice in a row is green both times (no leaked state)
- [ ] `composer dev:lint` covers `tests/` and exits 0

#### Manual Verification:

- [ ] Deliberately breaking `Keys\verify()` to always return a key makes `KeyIsolationTest` fail (the tests can actually fail)
- [ ] Deliberately removing the honeypot check makes the corresponding contact form test fail
- [ ] Suite runtime is acceptable for an inner loop (unit under 2s, integration under 60s)

**Implementation Note**: pause after this phase for manual confirmation before continuing.

---

## Phase 7: AGENTS.md, CONTEXT.md, and Docs

### Overview

Make the repo AI-first.
An agent landing in a fresh clone must be able to add an endpoint or a plugin without guessing.

### Changes Required

#### 1. `AGENTS.md`

Decision-record style, following `templ-companion`: every rule states the reason, and where a rule exists because of a specific failure, the failure is named.

Sections:

- **Toolchain lives in containers**: every command via `composer dev:*`; never assume host PHP; never run `composer` from a different PHP than the pinned floor.
- **Repo layout**: this is a `wp-content` overlay, core is not in git, the bind mounts are the contract with `compose.yaml`.
- **Architecture**: namespaced functions only, no classes, one `bootstrap()` per module, no production autoloader and why, how to add a module (one `require_once` plus one `bootstrap()` call), how to add a whole plugin.
- **Multisite**: the MU plugin runs everywhere and that is what makes keys per-site; new subsites must work with zero activation steps; anything stored in a site option or a CPT is automatically per-site, anything in a network option is not, so prefer the former.
- **Auth contract**: the exact functions consumer plugins may call, the header formats, why query-string keys are rejected, why `/unsubscribe` is the single unauthenticated exception, and the rule that adding an unauthenticated route requires an explicit note in this file.
- **Tests**: what belongs in each suite, why there is no `wp-phpunit` scaffold, "a test that cannot fail is worse than no test", cleanup discipline, how to debug a flaky integration test (opcache, transients, leftover posts).
- **Lint**: `WordPress` plus `PHPCompatibility`, short arrays allowed, prefixes and text domains, every new exclusion must carry a why-comment.
- **Version floors**: PHP 8.3 and WP 7.1 appear in the plugin headers, `composer.json` platform, `phpcs.xml`, and the container image tag; changing one means changing all four.
- **Known upgrade paths**: keys as a CPT with a meta lookup is a boilerplate-scale choice, and the migration to a custom table with a `key_hash` index is described here so the decision is not silently inherited as gospel.
- **Extension points**: the full list of actions and filters, with the intended use of each (notification email, double opt-in, extra fields, custom auth).

#### 2. `CONTEXT.md`

Pure glossary, no instructions.
Terms: Network, Subsite, Tenant, API key, Plaintext key, Key prefix, Revoked, Headless frontend, Proxy, Submission, Subscriber, Single opt-in, Unsubscribe token, Honeypot.
Plus a "Words we do not use" section: no "customer" when "subsite" is meant, no "token" when "API key" is meant (the unsubscribe token is the only token), no "form" for the contact endpoint since there is exactly one and it is not configurable.

#### 3. `README.md`

Human-facing: what this is, quick start (three commands), where the admin UIs are, how to mint a key, one `curl` example per endpoint, how to add a subsite, what to change when forking (naming, floors, the sample subsite).

#### 4. `docs/api.md`

Endpoint reference: method, path, auth, request schema, response schema, every error code with its HTTP status, and a worked example for each.
Includes a short section on the expected frontend integration shape, showing a Next.js route handler proxying a submission so the key stays server-side.

#### 5. `docs/extending.md`

Three walkthroughs: adding a field to the contact form, adding double opt-in to the newsletter using the existing action, and adding a third plugin that reuses the key auth.

### Success Criteria

#### Automated Verification:

- [ ] Every command quoted in `README.md` and `AGENTS.md` exists in `composer.json` scripts (verify by reading both)
- [ ] Every route documented in `docs/api.md` appears in a `src/rest.php` route registration
- [ ] Every hook listed in `AGENTS.md` exists in the code (grep for each name)
- [ ] `composer dev:reset && composer dev:up` followed by the README quick start works verbatim

#### Manual Verification:

- [ ] A fresh agent session given only `AGENTS.md` can add a working endpoint to the newsletter plugin without asking a clarifying question
- [ ] `CONTEXT.md` contains no instructions, only definitions
- [ ] The `curl` examples in `docs/api.md` copy-paste and work against the dev stack

---

## Testing Strategy

### Unit Tests

Pure functions only: key generation and hashing, both validators, the unsubscribe token, rate limit arithmetic.
Boundary values on every length constraint, and malformed input on every parser.

### Integration Tests

Real HTTP against the running container for every route, covering the full auth matrix (absent, malformed, valid, revoked) and the full validation matrix.
Plus WP-CLI commands shelled out for real.

### Multisite Tests

Key isolation and data isolation across two subsites, and the guarantee that a newly created site works with no activation step.

### Manual Testing Steps

1. `composer dev:reset && composer dev:up`, then log in at `http://localhost:8080/wp-admin/`.
2. On `customer-one`, mint a key in Settings > API Keys and copy the plaintext.
3. `curl` a contact submission and a newsletter subscribe with that key; confirm both appear in the respective admin screens.
4. Repeat with the key against the main site; confirm 401.
5. Revoke the key and confirm both endpoints now return 401.
6. Open the unsubscribe URL from the subscriber record in a browser; confirm the status flips.
7. Export the subscriber CSV and open it.
8. Create a second subsite from Network Admin and confirm all screens and routes work there immediately.

## Performance Considerations

Key verification runs on every API request and does a meta query.
At boilerplate scale (tens of keys per site) this is a single indexed `postmeta` lookup and is fine.
`last_used_at` writes are throttled to once per hour per key by a transient, so a hot endpoint does not write meta on every request.
The custom-table migration path is documented in `AGENTS.md` rather than implemented, and the threshold at which it matters (roughly thousands of keys, or key checks becoming a measurable share of request time) is stated there.

Rate limiting via transients is free when an object cache is present and degrades to `options` writes when it is not.
That trade is stated in the code comment.

## Migration Notes

Not applicable.
This is a greenfield repository with no existing installations.
For agencies adopting the boilerplate on an existing network, `docs/extending.md` notes that the MU plugin must be deployed before the feature plugins are activated, because the feature plugins refuse to bootstrap without it.

## References

- Reference implementation for all conventions: `~/templio/plugin` (`templ-companion`)
- Namespaced-functions bootstrap pattern: `~/templio/plugin/templ-companion.php:45-83`
- REST route constant discipline: `~/templio/plugin/src/rest.php:8-31`
- Container-backed test bootstrap: `~/templio/plugin/tests/bootstrap-integration.php:13-22`
- phpcs layering and why-comments: `~/templio/plugin/phpcs.xml:40-79`
- `AGENTS.md` decision-record style and `CONTEXT.md` glossary style: `~/templio/plugin/AGENTS.md`, `~/templio/plugin/CONTEXT.md`

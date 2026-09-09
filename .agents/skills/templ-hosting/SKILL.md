---
name: templ-hosting
description: >-
  How to deploy this repo to Templ and operate the live site: find the SSH
  credentials, run a deploy and verify it actually landed, run WP-CLI over SSH,
  purge the Templ cache, restart PHP, and configure multisite. Use when a task
  touches a deployed Templ site rather than the local Docker stack - shipping a
  change, reading a live option, listing plugins on production, debugging a
  failed deploy, or setting up a subdirectory multisite. Triggers on: Templ
  panel, .templ.mjs, templ-deploy, templssh.com, app_<id>, templ-ccli,
  "deploy to production", "on the live site", pnpm run deploy.
metadata:
  author: emanuel
  version: "2026.2.0"
---

# Templ Hosting - Deploying and Operating a Live Site

Templ is managed WordPress hosting. Every website is a container with full SSH access and `wp`, `git`, `composer`, `node` and `templ` preinstalled. Public documentation: <https://docs.templ.site/>.

This skill covers the **live site**. The local Docker stack is a different thing - see `AGENTS.md`. Never assume a command that works locally works the same way over SSH.

## Where the credentials live

Connection details for this project's targets are in **`.templ.mjs`** in the repo root. It is gitignored because it names real servers; **`.templ.mjs.example`** is the committed template. Read `.templ.mjs` first - it is the source of truth:

```js
production: {
  app: 1234,                              // Templ app id, from the panel
  host: 'sweden1.templssh.com',           // SSH host
  port: 12345,                            // a high per-site port, never 22
  dst: 'app_{{app_dir}}/public/wp-content',
  sshCmd: '...',                          // runs on the server after rsync
}
```

If `.templ.mjs` is absent, the site has not been wired up yet. **Do not guess a host.** Ask, or point at the Templ Panel: the website's **Overview** tab has **SFTP/SSH → SSH Tools → SSH Command** ready to copy, and an **SSH Config** variant for `~/.ssh/config`.

Given an app id of `1234`, everything else is derived:

| Thing | Value | Note |
| --- | --- | --- |
| SSH user | `user_1234` | `user` in the config overrides it |
| Webroot | `/home/user_1234/app_1234` (`~/app_1234`) | |
| Deploy target | `app_1234/public/wp-content` | `dst` in the config |
| `wp` binary | `/usr/bin/wp` | |
| Templ CLI binary | `/usr/bin/templ-ccli` | not `templ`, see below |

`{{app_dir}}` in `dst` expands to the **bare app id**, not to `app_<id>`, so the `app_` prefix has to be written out. The name suggests otherwise.

Authentication is by SSH key (upload the public key in the panel) or password. For a non-default key, set `sshId` in the target.

## Deploying

`pnpm run deploy <target>` runs [`templ-deploy`](https://github.com/templhosting/templ-deploy): rsync the `dir` from `.templ.mjs` up to `user@host:dst`, then run `sshCmd` over SSH **from inside `dst`**. Only `wp-content` ships; core, uploads and `wp-config.php` live on the server and are never touched.

> **`pnpm run deploy` exits 0 even when the deploy failed.**
> A failing rsync is logged and swallowed, the post-deploy `sshCmd` runs anyway against files that never landed, and the process still exits 0. This is verified behaviour, not a theoretical risk.
> **Never report a deploy as successful on the strength of its exit status.** Read the output for `Error`, then verify on the server.

### Runbook

1. **Read `.templ.mjs`** for the target's app id, host and port. No config, no deploy.
2. **Dry run first.** Prints the exact rsync and ssh commands and runs nothing:
   ```sh
   pnpm run deploy production -- --dry
   ```
3. **Deploy.**
   ```sh
   pnpm run deploy production
   ```
   Scan the output for `Error` / `rsync exited with code`. Exit status proves nothing.
4. **Verify on the server**, over SSH:
   ```sh
   # the MU plugin has to be there, or the feature plugins refuse to bootstrap
   "/usr/bin/wp --path=/home/user_1234/app_1234 plugin list --status=must-use"
   # the feature plugins should be active (add --network on multisite)
   "/usr/bin/wp --path=/home/user_1234/app_1234 plugin list"
   ```
   Then call a real endpoint with a key, which is the only check that exercises the whole path.
5. **Purge the Templ cache.** Markup and rewrite changes stay invisible from outside until you do:
   ```sh
   ssh -p 12345 user_1234@sweden1.templssh.com /usr/bin/templ-ccli cache purge
   ```
6. **If PHP changes are not taking**, clear opcache: `/usr/bin/templ-ccli php restart`.

To roll back, check out the previous git ref and deploy it again. There is no server-side history; the overlay is whatever was last rsynced.

### Gotchas, all verified

- **The target defaults to the current git branch.** `pnpm run deploy` on `main` looks for a `main` target and fails with `No such templ configured` if there isn't one. Name the target explicitly.
- **Always name the target when passing flags.** pnpm forwards the `--` separator as a literal argument, so `pnpm run deploy -- --dry` reads `--` as the target name and fails. `pnpm run deploy production -- --dry` is correct.
- **`pnpm deploy` without `run` is a reserved pnpm builtin** and will not run this script.
- **`--delete` removes files on the server that are absent locally.** Destructive; confirm with the user first, and dry-run it.
- **`--skipRsync` is in `templ-deploy --help` but is not parsed.** Passed alongside a target it is silently ignored and the rsync still runs. Do not rely on it.
- **`sshCmd` runs with its working directory set to `dst`** - that is `wp-content`, not the webroot. Plain `wp` still works because WP-CLI walks up to find `wp-config.php`.
- **On a multisite target `sshCmd` needs `--network`** on the activate, or the plugins are only active on the main site.
- **The MU plugin and the feature plugins land in the same rsync**, so a first deploy is self-sufficient: the dependency is present before `sshCmd` activates anything.

## Running WP-CLI over SSH

Prefer one-shot non-interactive commands. Two things are mandatory in that form: the **absolute path to `wp`**, because a non-interactive shell does not get the login `PATH`, and **`--path`**, because there is no working directory to infer the install from.

```sh
ssh -p 12345 user_1234@sweden1.templssh.com \
  "/usr/bin/wp --path=/home/user_1234/app_1234 option get home"
```

Common calls, same shape:

```sh
"/usr/bin/wp --path=/home/user_1234/app_1234 plugin list"
"/usr/bin/wp --path=/home/user_1234/app_1234 core version"
"/usr/bin/wp --path=/home/user_1234/app_1234 cache flush"
```

When the site is fataling, add `--skip-plugins --skip-themes` so WP-CLI can load at all:

```sh
"/usr/bin/wp --path=/home/user_1234/app_1234 --skip-plugins --skip-themes plugin deactivate <slug>"
```

For an exploratory session, `ssh -p 12345 user_1234@sweden1.templssh.com`, then `cd ~/app_1234` and use plain `wp`.

**Read before you write.** `option get`, `plugin list`, `core version` are free. Anything that mutates production - `search-replace`, `plugin deactivate`, `user update`, `db query` - gets confirmed with the user first, and `search-replace` gets a `--dry-run` pass first.

## Templ container CLI

`templ` is a shell shortcut that only exists inside an interactive SSH session. Remotely, call the binary:

```sh
ssh -p 12345 user_1234@sweden1.templssh.com /usr/bin/templ-ccli cache purge
ssh -p 12345 user_1234@sweden1.templssh.com /usr/bin/templ-ccli php restart
```

`templ-ccli cache purge` clears the **Templ page cache**, a different layer from `wp cache flush`. If a deployed change is correct on disk but invisible from outside, this is the first suspect.

## Multisite

Enabling multisite is the normal WordPress procedure - edit `wp-config.php`, run Network Setup. See <https://docs.templ.site/development/multisite>.

**Subdirectory networks need one step that cannot be done over SSH.** In the Templ Panel, open the website, go to **Website → Advanced → Site Options**, and activate the **multisite** toggle. Without it the platform does not route subdirectory site URLs and subsites 404 no matter how correct WordPress' own config is: <https://docs.templ.site/development/multisite#subdirectory-install>.

If subsites 404 on a network that looks correctly configured in WordPress, check that toggle before debugging anything else.

**Domain-based networks** (subdomain or separate domains) instead need each domain added to the website: <https://docs.templ.site/domains/additional-domains>.

WP-CLI on a network needs to be told which site it means:

```sh
"/usr/bin/wp --path=/home/user_1234/app_1234 plugin activate <slug> --network"
"/usr/bin/wp --path=/home/user_1234/app_1234 --url=https://example.com/customer-one/ option get home"
"/usr/bin/wp --path=/home/user_1234/app_1234 site list"
```

## Docs map

- <https://docs.templ.site/> - root
- <https://docs.templ.site/development/ssh-wp-cli> - SSH, WP-CLI, Node, Templ CLI
- <https://docs.templ.site/development/multisite> - multisite, incl. the subdirectory panel toggle
- <https://docs.templ.site/development/git-push> - git push deployment
- <https://docs.templ.site/websites/file-access> - SSH keys, passwords, SFTP
- <https://docs.templ.site/platform/cron> - scheduled WP-CLI commands

Fetch the relevant page rather than guessing at panel navigation or flags; the platform changes and the docs carry a "last updated" date.

---
title: "Release Automation (Auto Version Bump & Tag)"
audience: [developer]
source_files: [".github/workflows/release.yml", ".ci/version-bump.php", ".ci/changelog.php"]
---

# Release Automation

Every merge into `main` bumps this project's version and pushes a matching git tag automatically. The bump runs *after* merge, computed serially from `main`, so a version can never collide with one chosen inside a parallel PR. No one needs push access to `main`, releases are made by a GitHub App (`wicket-release-bot`), not a person.

---

## What happens on merge

1. A PR merges into `main` (push event).
2. [.github/workflows/release.yml](../../.github/workflows/release.yml) mints a GitHub App token and checks out `main`.
3. It reads the merge commit message for a bump marker and runs [.ci/version-bump.php](../../.ci/version-bump.php).
4. It runs [.ci/changelog.php](../../.ci/changelog.php) to prepend a `CHANGELOG.md` section for the new version.
5. It commits `chore(release): x.y.z`, tags that commit `x.y.z`, and pushes both to `main`.

The workflow ignores its own `chore(release):` commits, so it does not loop. A merge that changes only docs/tooling and should **not** release must include `#norelease` (see below).

## Choosing the bump level

Default is a **patch** bump (`2.4.10` to `2.4.11`). Override with a marker anywhere in the merge commit message. With squash-merge the PR title becomes that message, so the marker can go in the PR title.

| Marker | Result | Example |
|---|---|---|
| _(none)_ | patch | `2.4.10` to `2.4.11` |
| `#minor` | minor | `2.4.10` to `2.5.0` |
| `#major` | major | `2.4.10` to `3.0.0` |
| `#norelease` | no bump, no tag | (skipped) |

## What gets bumped

[.ci/version-bump.php](../../.ci/version-bump.php) auto-detects the project's main file and keeps the version in sync across:

- `composer.json` `version` (used as the source of truth when present).
- The main file header `Version:`, in a plugin's root `*.php` (header `Plugin Name:`) or a theme's `style.css` (header `Theme Name:`).
- Any hardcoded version constant in a plugin file (`define('..._VERSION', 'x.y.z')` or `const VERSION = '...'`).

When there is no `composer.json`, the main file header is used as the source of truth instead.

Run it locally too (where a `composer version-bump` script is wired):

```bash
composer version-bump patch     # or minor | major | an explicit X.Y.Z
# or directly:
php .ci/version-bump.php patch
```

It only edits files; it does not commit or tag.

## Changelog

`CHANGELOG.md` (repo root, [Keep a Changelog](https://keepachangelog.com) style, newest on top) is updated in the same release commit. [.ci/changelog.php](../../.ci/changelog.php) reads the commits merged since the last tag (`git log <last-tag>..HEAD --no-merges`) and prepends one section for the new version.

Every commit type is included and grouped by its conventional prefix (`feat` to Added, `fix` to Fixed, `perf` to Performance, `refactor` to Changed, `docs` to Documentation, `test` to Tests, `build` to Build, `ci` to CI, `chore`/`style` to Maintenance; anything else to Other). The only commits skipped are the bot's own `chore(release):` commits. A `!` (e.g. `feat!:`) prefixes the line with **BREAKING**.

> A release lists **everything merged since the previous tag**, not just the PR that triggered it. The first automated release on a repo therefore "catches up" any work that had been merged but never tagged.

Regenerate or back-fill a range manually:

```bash
php .ci/changelog.php 2.4.11 2.4.8..2.4.11
```

---

## One-time setup (per repo)

Authentication is via the `wicket-release-bot` GitHub App so releases are decoupled from any individual account.

1. **App installed** on the repo with **Contents: Read and write**.
2. **Secrets available** (org-level, scoped to the plugin/theme repos, or per repo):
   - `RELEASE_APP_ID`, the App's numeric App ID.
   - `RELEASE_APP_PRIVATE_KEY`, the App's `.pem` private key.
3. **Bypass**: `wicket-release-bot` must be on the `main` branch ruleset **bypass list**, or the release push is rejected (`GH013`).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Push rejected: `GH013` / protected branch | App not on the ruleset bypass list | Add `wicket-release-bot` to the `main` ruleset bypass list |
| `Bad credentials` / token step fails | Wrong `RELEASE_APP_ID` or malformed `RELEASE_APP_PRIVATE_KEY` | Re-copy the whole `.pem`, including header/footer lines |
| Run is grey/skipped after a release | It was the bot's own `chore(release):` commit, or `#norelease` was present | Expected; check the "Resolved bump level" line in the run log |
| `No version field found in composer.json` | Plugin has a `composer.json` with no `version` key | Add a `version` field matching the current header |
| Wrong file bumped / "No version string found" | Main file not auto-detected | Ensure the plugin's main `*.php` (with `Plugin Name:`) or the theme's `style.css` is in the repo root |

---
title: "Release Automation (Auto Version Bump & Tag)"
audience: [developer]
source_files: [".github/workflows/release.yml", ".ci/version-bump.php", ".ci/changelog.php"]
---

# Release Automation

Stub. The canonical, stack-wide doc lives in **Wicket Atlas**:
`atlas/conventions/release-automation.md` (wicket-atlas repo, sibling of `qa/`
in the stack assembly).

TL;DR: every merge to `main` auto-bumps the version, updates `CHANGELOG.md`,
and tags via the `wicket-release-bot` GitHub App. Default bump is patch;
control with `#minor` / `#major` / `#norelease` in the PR title. Never bump
versions or create tags by hand.

For markers, changelog rules, one-time repo setup, and troubleshooting, read
the Atlas doc. Do not edit this stub with process details; change the process
in Atlas, once.

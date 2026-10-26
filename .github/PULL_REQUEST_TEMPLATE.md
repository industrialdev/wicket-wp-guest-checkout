<!--
Wicket PR template. Full spec: atlas/conventions/pr-template.md
-->

## Context
<!-- Keep every bullet. No ticket: Task ID/Link = "N/A (short reason)". Other empty bullets = "none". Values only; longer explanations go under Why.
Related PRs: full owner/repo#N, one per sub-bullet when more than one. Atlas: commit as owner/repo@sha. Replace placeholders including their backticks.
Request reviewers in the GitHub reviewers field, not with @mentions in this body. -->
- **Task ID:** `WWID-####` or `N/A (reason)`
- **Task Link:** `[Asana task](URL)` or `N/A (reason)`
- **Related PRs:** `owner/repo#N` or `none`
- **QA branch:** `[branch](wicket-warden branch URL)` or `none`
- **Atlas:** `industrialdev/wicket-atlas@sha` or `none`

## Dependencies
<!-- Version changes to other packages this PR needs, or "none". One bullet per package.
package: owner/repo for Wicket repos; plugin slug (e.g. woocommerce), php, or wordpress otherwise.
from/to: Composer syntax, full X.Y.Z. Dependency not released yet: to = "pending (owner/repo#N)", replace before merge.
change: added | raised | lowered | removed. enforcement: hard | feature-gated | undeclared.
Raised hard minimum: add "Deploy order: <package> <version> first". -->
- `package`: `from` → `to` (change, enforcement: what needs it)

## What
<!-- State the current issue in 1-2 sentences. What happens today? Keep it brief, this is the intro. -->

## Why
<!-- Explain in more detail why this issue matters and why it needs a fix. Add context. Keep it brief. -->

## How
<!--
Give a detailed technical explanation: what you changed and why you chose that approach.
Use ### subheadings under What/Why/How when a change spans multiple areas (e.g. ### API, ### Data, ### Frontend).
Subheadings are optional — only add them when one section covers more than one distinct area of change.
-->

<!-- No sensitive info: no real names, API keys/secrets, or client-specific identifiers. This PR may be public. -->

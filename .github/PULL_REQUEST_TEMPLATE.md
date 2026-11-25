<!--
Wicket PR template. Full spec: atlas://conventions/pr-template.md
Done before opening: every <placeholder> replaced with a real value, brackets included, and every comment in this template deleted.
-->

## Context
<!-- Keep all five bullets, in order. Values only; longer explanations go under Why.
Fill each <placeholder>, brackets included, or replace the whole value with the text after "or".
Filled example:
- **Task ID:** WWID-2782
- **Task Link:** [Asana task](https://app.asana.com/0/1204425091866322/1209876543210987)
- **Related PRs:** industrialdev/wicket-wp-base-plugin#98
- **QA branch:** [feature/WWID-2782-fix-timezone](https://github.com/industrialdev/wicket-warden/tree/feature/WWID-2782-fix-timezone)
- **Atlas:** industrialdev/wicket-atlas@74eaa53
2+ related PRs: one sub-bullet per PR. Request reviewers in the GitHub reviewers field; a body @mention is not a review request. -->
- **Task ID:** <WWID-####> or N/A (short reason)
- **Task Link:** [Asana task](<task URL>) or N/A (short reason)
- **Related PRs:** <owner/repo#N> or none
- **QA branch:** [<branch name>](<wicket-warden branch URL>) or none
- **Atlas:** <owner/repo@sha> or none

## Dependencies
<!-- Version changes in other packages this PR needs. No changes: leave none.
A dependency change replaces none with one bullet per package, in this shape:
- `industrialdev/wicket-wp-base-plugin`: `>=2.0.0` → `>=2.0.109` (raised, hard: bundle member removal helper)
package: owner/repo for Wicket repos; plugin slug (e.g. woocommerce), php, or wordpress otherwise.
from/to: Composer syntax, full X.Y.Z. Dependency not released yet: to = "pending (owner/repo#N)", replace before merge.
change: added | raised | lowered | removed. enforcement: hard | feature-gated | undeclared.
Raised hard minimum: add "Deploy order: <package> <version> first". -->
none

## What
<!-- State the current issue in 1-2 sentences. What happens today? Keep it brief, this is the intro. -->

## Why
<!-- Explain in more detail why this issue matters and why it needs a fix. Add context. Keep it brief. -->

## How
<!--
Give a detailed technical explanation: what you changed and why you chose that approach.
Lead with a visual when the shape matters more than words. Pick the smallest fit: call tree (control flow), component tree (UI structure), file tree (refactor), diff block (surrounding shape exists), mermaid (component interaction or data flow; GitHub renders it). Use two or three when one view does not show the whole change.
Show before and after proof for a visual or behavior change: screenshot first, test output second.
Use ### subheadings under What/Why/How when a change spans multiple areas (e.g. ### API, ### Data, ### Frontend).
Subheadings are optional. Add them only when one section covers more than one distinct area of change.
-->

<!-- No sensitive info: real names, API keys/secrets, or client-specific identifiers. This PR may be public. -->

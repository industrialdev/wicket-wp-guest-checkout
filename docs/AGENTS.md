# Documentation Rules

This `docs/` folder is for **user-facing manuals only**: guides and reference
material for the people who configure and use this plugin.

## What belongs here

- **How-tos** — step-by-step tasks for end users.
- **Settings reference** — what each WP admin setting does, defaults, gotchas.
- **Operator troubleshooting** — diagnosis steps for support staff.

## What does NOT belong here

Developer documentation (hooks, classes, architecture, internal decisions).
That material is not user-facing and does not belong in this folder.

## Rules

- Know your audience before writing. State it in the doc's front matter
  (`audience: [end-user]`, `[implementer]`, or `[support]`).
- End-user docs: plain language. No code, class names, or option keys unless
  showing exact UI input.
- Implementer/support docs: one heading per setting; include what it does,
  when to use it, the default, and any warnings.
- Keep `index.md` current when you add or remove a doc.

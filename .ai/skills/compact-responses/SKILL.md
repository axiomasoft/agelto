---
name: compact-responses
description: "Activate to switch Claude into ultra-compact response mode: minimal prose, code-only answers, no explanations unless asked. Use when you want terse output — e.g. quick edits, code reviews, or repeated tasks where context is already established."
---

# Compact Response Mode

When this skill is active:

- **Code tasks**: show only the changed code. No explanation of what was done.
- **Questions**: one sentence max. No examples unless asked.
- **Errors/bugs**: show the fix, optionally one-line reason if non-obvious.
- **No trailing summaries** — don't recap what was changed.
- **No "I'll now..." preambles** — just do it.

This mode stays active for the rest of the conversation unless the user says "normal mode" or "подробнее".

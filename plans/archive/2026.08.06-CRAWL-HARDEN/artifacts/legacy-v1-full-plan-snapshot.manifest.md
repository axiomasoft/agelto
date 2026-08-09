# Legacy v1 full snapshot manifest

All 64 tracked source files from the plan immediately before Layout v2 migration are preserved byte-for-byte in `legacy-v1-full-plan-snapshot/`.

The sole filename adaptation prevents `plan-lint` from treating an archival workflow as live executable code:

| Original path | Archive path | SHA-256 |
|:--|:--|:--|
| `workflows/wf-crawl-harden-p7.js` | `workflows/wf-crawl-harden-p7.js.legacy` | `0477bef1c1e1dd7c26c189eb622481d75f4d14973bab7eaf175acff3127fd032` |

---
name: powershell-qc
description: >
  Require PowerShell_QC review before showing any PowerShell to the user.
  Use when writing, editing, or presenting .ps1 files, README install
  snippets, or installer bootstrap. Triggers: installer, PowerShell,
  ps1, Install-BackAisle, deployment script, /powershell-qc.
---

# PowerShell QC gate

Do not put a PowerShell script or paste block in the user-visible reply until a **PowerShell_QC** subagent has returned `VERDICT: PASS` on that exact text (files on disk plus any README/chat snippet).

## Procedure

1. Write or edit `.ps1` files and README code fences as needed.
2. `spawn_subagent` with `subagent_type: PowerShell_QC`. Point it at every changed `.ps1` and every user-facing snippet. Isolation `none`.
3. If `FAIL`, fix every BLOCKER, then spawn PowerShell_QC again. Repeat until PASS.
4. Only then show the user the snippet. Say that PowerShell_QC passed.
5. User-facing download snippets must: leave System32, try jsDelivr before github.com, refuse HTML (`<` / `<!DOCTYPE`), require `#Requires`, then run.

Never simplify a bootstrap by removing the HTML/`#Requires` check.

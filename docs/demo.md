# Demo notes

Sign in with the local admin from `secrets.env` (or an AD account mapped in Org → LDAPS).

Dashboard shows campus-wide power and climate. **IDFs** opens the location tree; a closet shows rack elevations. **Power → UPS fleet** lists polled units.

An ENVIROSENSOR attached to a PR-series UPS appears under Environment → Climate after the next climate poll. Values come from live SNMP (env2 /100, C→F). Missing OIDs stay blank — they are never invented.

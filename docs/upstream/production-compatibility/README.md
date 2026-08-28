# Production compatibility integration

This directory records the audited semantic port of a private production
compatibility branch into the preserved phpBB2 Plus source tree. The private
branch began from a deployed installation rather than from this repository's
Git history, included installation-specific deployment automation and
acceptance notes, and did not include the installer and preservation material
now present here. A direct history merge would therefore have overwritten
unrelated preservation work and imported private operational details.

The product changes were instead reconciled by subject area against the
current tree. `commits.csv` lists every one of the 56 commits after the private
branch's reference baseline and records its disposition and public port
commit. No installation hostname, account, credential, database content,
uploaded content, or private deployment configuration is retained.

Disposition meanings:

- `ported`: the effective product change was adapted to the combined tree;
- `already-present`: an equivalent or newer implementation was already here;
- `superseded`: a later, safer implementation replaces the historical change;
- `not-applicable`: deployment automation, acceptance records, or other
  installation-specific material was deliberately excluded.

Grouped semantic commits keep the public history reviewable while the source
trailers and this ledger retain traceability to every applicable change.

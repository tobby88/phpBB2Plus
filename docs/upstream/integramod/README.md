# IntegraMOD phpBB2x integration

This directory records the audited port of developments from
[`IntegraMOD/phpBB2x`](https://github.com/IntegraMOD/phpBB2x) into phpBB2 Plus.
The original histories did not share a Git ancestor, and the products used
different directory layouts. The complete upstream history was therefore
rewritten reproducibly into an integration branch: `phpBB/` became `phpBB2/`
and all language packs except English and German were filtered out. Its
phpBB 2.0.23 snapshot was anchored as the merge base before the transformed
head was merged. This lets Git retain every upstream author, commit and merge
while resolving shared files against the existing phpBB2 Plus code.

## Pinned upstream

- Repository: `https://github.com/IntegraMOD/phpBB2x.git`
- Branch: `main`
- Commit: `9a860a721925af2bf8bcfd9f25bf04ba551cc74d`
- Commits in scope: 201

`commits.csv` is the authoritative ledger. Every upstream commit must end in
one of these dispositions:

- `merged`: the transformed commit is reachable through the integration merge;
- `ported`: its effective changes were ported, possibly with Plus adaptations;
- `already-present`: the repository already contains an equivalent or newer fix;
- `superseded`: a later upstream state or a newer local implementation replaces it;
- `source-only`: it contains a source package which is not installed upstream;
- `not-applicable`: it only affects unsupported infrastructure or an abandoned state;
- `license-blocked`: redistribution is not permitted or has not been established.

`MappedCommit` records the transformed commit ID corresponding to each original
upstream commit. `PortCommit` records the merge or later Plus-specific semantic
port. Intermediate changes which upstream later reverted remain visible in the
merged history rather than being reintroduced into the final tree.

## Scope decisions

- German and English are the only bundled languages. Other upstream language
  packs are intentionally outside the integration scope, including language
  files embedded in source packages under `mods/`.
- All six upstream styles are included. The existing Extreme Styles fallback
  now has every phpBB2 Plus, Album, Arcade, Portal, PAFileDB and CrackerTracker
  template, so missing style-specific templates resolve without fatal errors.
- Freely redistributable third-party assets are imported reproducibly with
  their license texts and versions recorded.
- BootstrapMade HeroBiz demo assets are excluded unless redistribution rights
  are established. Required presentation is replaced with redistributable or
  project-owned assets.
- Source packages under `mods/` are preserved for provenance. They are not
  treated as installed modules unless their files also exist in the pinned
  upstream product tree. See [`modules.md`](modules.md) for the audit.

Regenerate the ledger after fetching the pinned upstream ref with:

```powershell
./tools/update-integramod-ledger.ps1 -MappedRef integramod-mapped/main
```

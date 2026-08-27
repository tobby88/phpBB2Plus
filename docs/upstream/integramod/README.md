# IntegraMOD phpBB2x integration

This directory records the audited port of developments from
[`IntegraMOD/phpBB2x`](https://github.com/IntegraMOD/phpBB2x) into phpBB2 Plus.
The upstream histories do not share a Git ancestor, and the products use
different directory layouts. Upstream changes are therefore ported in tested,
thematic commits instead of merging the unrelated trees or replaying every
upstream commit blindly.

## Pinned upstream

- Repository: `https://github.com/IntegraMOD/phpBB2x.git`
- Branch: `main`
- Commit: `9a860a721925af2bf8bcfd9f25bf04ba551cc74d`
- Commits in scope: 201

`commits.csv` is the authoritative ledger. Every upstream commit must end in
one of these dispositions:

- `ported`: its effective changes were ported, possibly with Plus adaptations;
- `already-present`: the repository already contains an equivalent or newer fix;
- `superseded`: a later upstream state or a newer local implementation replaces it;
- `source-only`: it contains a source package which is not installed upstream;
- `not-applicable`: it only affects unsupported infrastructure or an abandoned state;
- `license-blocked`: redistribution is not permitted or has not been established.

Port commits reference their upstream commit or range in commit-message
trailers. Intermediate changes which upstream later reverted are documented as
superseded rather than reintroduced.

## Scope decisions

- German and English are the only bundled languages. Other upstream language
  packs are intentionally outside the integration scope.
- All six upstream styles are in scope, but they are not considered usable
  until their phpBB2 Plus, Album, Arcade, Portal, PAFileDB and CrackerTracker
  templates have been adapted and tested.
- Freely redistributable third-party assets are imported reproducibly with
  their license texts and versions recorded.
- BootstrapMade HeroBiz demo assets are excluded unless redistribution rights
  are established. Required presentation is replaced with redistributable or
  project-owned assets.

Regenerate the ledger after fetching the pinned upstream ref with:

```powershell
./tools/update-integramod-ledger.ps1
```

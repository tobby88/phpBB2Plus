# IntegraMOD module disposition

The transformed IntegraMOD history retains the module source packages that
were committed upstream. A package under `mods/` is not, by itself, evidence
that the module was installed in the upstream product tree. The final upstream
tree was compared with each package's `root/` directory before deciding how to
integrate it into phpBB2 Plus.

| Package | Upstream final state | phpBB2 Plus disposition |
| --- | --- | --- |
| Admin Userlist | Source package only; its product files were removed again upstream | Retained as historical source, not installed |
| Cookie consent | Instructions and assets are retained upstream | Product integration is audited separately; no installation is inferred from the package |
| Digests 1.0.14 | Source package only | Retained as historical source, not installed |
| IM Portal | Partially integrated upstream; the package also contains 209 files absent from the final upstream tree | Existing phpBB2 Plus portal remains authoritative; only files present in the final upstream product are candidates for an adapted port |
| Log actions | Source package only | Retained as historical source, not installed |
| paFileDB 1.0.1 | Older source package | Retained for provenance; the newer phpBB2 Plus PAFileDB implementation is not downgraded |
| Registration IP | Source package only | Retained as historical source, not installed |
| Registration Spam Mod | Installation source/instructions | Retained for provenance; integration is audited separately |
| Rules & Policies 1.0.1 | Source package only | Retained as historical source, not installed |
| Stop Forum Spam 2.0 | Installation source/instructions | Retained for provenance; integration is audited separately |

Only English and German language material is retained, including inside these
source packages. Files for Dutch, Italian and all other languages are filtered
out of the integration result.

This distinction prevents an incomplete module installation: copying every
file from a package's `root/` directory would produce a tree that never existed
at the pinned IntegraMOD commit and could bypass phpBB2 Plus-specific security,
database and template adaptations.

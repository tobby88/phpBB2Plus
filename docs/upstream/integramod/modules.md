# IntegraMOD module disposition

The transformed IntegraMOD history retains the module source packages that
were committed upstream. A package under `mods/` is not, by itself, evidence
that the module was installed in the upstream product tree. The final upstream
tree was compared with each package's `root/` directory before deciding how to
integrate it into phpBB2 Plus.

| Package | Upstream final state | phpBB2 Plus disposition |
| --- | --- | --- |
| Admin Userlist | Source package only; its product files were removed again upstream | Retained as historical source, not installed |
| Cookie consent | Instructions and assets are retained upstream | Integrated into the product with ACP configuration and templates for all seven styles |
| Digests 1.0.14 | Source package only | Retained as historical source, not installed |
| IM Portal | Partially integrated upstream; the package also contains 209 files absent from the final upstream tree | Existing phpBB2 Plus portal remains authoritative; only files present in the final upstream product are candidates for an adapted port |
| Log actions | Source package only | Retained as historical source, not installed |
| paFileDB 1.0.1 | Older source package | Retained for provenance; the newer phpBB2 Plus PAFileDB implementation is not downgraded |
| Registration IP | Source package only | Retained as historical source, not installed |
| Registration Spam Mod | Installation source/instructions | Retained for provenance; not installed |
| Rules & Policies 1.0.1 | Source package only | Retained as historical source, not installed |
| Stop Forum Spam 2.0 | Installation source/instructions | Integrated into registration with ACP configuration; disabled by default because enabling it transfers registration data to an external service |

Only English and German language material is retained, including inside these
source packages. Files for Dutch, Italian and all other languages are filtered
out of the integration result.

This distinction prevents an incomplete module installation: copying every
file from a package's `root/` directory would produce a tree that never existed
at the pinned IntegraMOD commit and could bypass phpBB2 Plus-specific security,
database and template adaptations.

The imported `db/pdo.php` driver has a similar provenance distinction. It was
present in the final IntegraMOD product tree, but IntegraMOD did not expose it
through its installer. Its result and cursor behavior also differs from the
database contract used by phpBB2 Plus. The source is therefore preserved but
PDO is not advertised as an installable or supported database layer. MySQLi is
the supported modern database path; legacy `mysql` and `mysql4` configuration
values transparently select MySQLi when that extension is available.

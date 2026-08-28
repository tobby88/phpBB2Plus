# UTF-8 database migration

The preserved source, language, template, JavaScript, and mail files use UTF-8
without a byte-order mark. The mysqli driver selects `utf8mb4`, and a matching
database schema is therefore required before this branch is used with existing
data.

## Preparation

1. Put the forum into maintenance mode.
2. Create and verify current database and file backups.
3. Test the complete procedure against a database clone.
4. Review the dry-run output and its selected database before applying it.

Run the migration from the repository root. It reads the untracked
`phpBB2/config.php` file:

```text
php tools/migrate-database-utf8mb4.php
```

An isolated database on the same server can be selected with
`--database=clone_name`. Apply only after checking a current backup:

```text
php tools/migrate-database-utf8mb4.php --apply --backup-confirmed
```

The script shortens indexed configuration and module-name columns where older
MySQL/MariaDB index limits require it. It aborts if an existing value would be
truncated. It also clears derived search tables before the collation change,
because words that were distinct under a legacy collation can collide under a
Unicode collation.

Rebuild the search index after the conversion:

```text
php tools/rebuild-search-index.php --apply --backup-confirmed
```

`ALTER TABLE` and `TRUNCATE TABLE` statements auto-commit. If a conversion
stops part-way through, restore the verified backup rather than serving a mixed
schema. Finally verify login, posting, private messages, search, administration,
and album upload against the converted clone.

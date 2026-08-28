# phpBB2 Plus application tree

This directory contains the deployable web application preserved by the
[phpBB2 Plus repository](../README.md). The project is a historical archive,
not an actively maintained or supported forum distribution.

## Runtime scope

- PHP 5.6 through the currently verified PHP 8.x versions;
- MySQL or MariaDB through PHP's MySQLi extension;
- UTF-8 source files and `utf8mb4` database connections.

Fresh installations write `mysqli` to `config.php`. Existing configurations
which still contain the legacy values `mysql` or `mysql4` remain accepted as
aliases and are routed through the same MySQLi driver. Drivers for PostgreSQL,
Microsoft SQL Server, Microsoft Access, DB2, the removed PHP `mysql_*`
extension, and the experimental PDO implementation are not part of this
preserved build.

## Installation and upgrades

For a fresh test installation, copy this directory to the web root and open
`install/install.php`. Remove or rename `install/` immediately afterwards.

Existing installations require a complete database and file backup before any
files are replaced. The source-specific migration paths are stored in the
repository's [`update/`](../update/) directory; the full procedure and the
post-1.53a updater are documented in the [project README](../README.md).
Installation and migration scripts must not remain in a public web root after
use.

User-generated uploads, local configuration, caches, logs, and other
deployment-specific data are intentionally separate from the reusable source
tree and must be preserved when updating an existing forum.

## License and credits

The phpBB2 base is distributed under the GNU General Public License version 2;
see [`COPYING`](../COPYING). phpBB2 Plus also includes historical third-party
MODs and assets whose notices remain in their source files and are summarized
in [`THIRD_PARTY_NOTICES.md`](../THIRD_PARTY_NOTICES.md).

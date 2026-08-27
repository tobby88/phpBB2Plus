# CrackerTracker Professional G5 5.0.4

The source tree contains the CrackerTracker 5.0.4 files and code changes described by
`updates/update_ctracker_v4x_to_v5x.txt` and `install.txt`.

For an existing database, follow the supplied upstream update guide exactly and make a
database backup first. In particular, the official process runs
`updates/db_uninstall_4x.php` once from the board root, deletes that script immediately,
runs the board-root `install.php` once, and then deletes that installer immediately.
Those scripts change or remove CrackerTracker tables and user columns and must never be
left on a public installation.

After deployment, make the six files in `ctracker/logfiles` writable by the web server,
as listed in `install.txt`.

phpBB2 Plus uses the `fisubsilversh` theme instead of the guide's `subSilver` theme.
The supplied `subSilver/ctracker` files and template edits are therefore mapped to
`templates/fisubsilversh`. phpBB2 Plus also already contains a guest-post CAPTCHA; the
CrackerTracker CAPTCHA is used when its `vconfirm_guest` setting is enabled, with the
existing Plus CAPTCHA retained as the fallback.

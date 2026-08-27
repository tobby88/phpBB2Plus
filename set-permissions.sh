#!/bin/sh

set -eu

usage()
{
	cat <<'EOF'
Usage: ./set-permissions.sh [--dry-run] [PHPBB_WEB_ROOT]

Apply the writable folder and file permissions required by phpBB2 Plus.
PHPBB_WEB_ROOT defaults to the phpBB2 directory next to this script.

Environment variables:
  PHPBB_WRITABLE_DIR_MODE   Folder mode (default: 0775)
  PHPBB_WRITABLE_FILE_MODE  File mode (default: 0664)

For shared hosting that requires the historical world-writable modes:
  PHPBB_WRITABLE_DIR_MODE=0777 PHPBB_WRITABLE_FILE_MODE=0666 \
    ./set-permissions.sh /path/to/phpbb/webroot
EOF
}

dry_run=0
if [ "${1:-}" = "--dry-run" ]; then
	dry_run=1
	shift
fi

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
	usage
	exit 0
fi

if [ "$#" -gt 1 ]; then
	usage >&2
	exit 2
fi

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
web_root=${1:-"$script_dir/phpBB2"}
dir_mode=${PHPBB_WRITABLE_DIR_MODE:-0775}
file_mode=${PHPBB_WRITABLE_FILE_MODE:-0664}

case "$dir_mode" in
	0[0-7][0-7][0-7]) ;;
	*) echo "Invalid PHPBB_WRITABLE_DIR_MODE: $dir_mode" >&2; exit 2 ;;
esac

case "$file_mode" in
	0[0-7][0-7][0-7]) ;;
	*) echo "Invalid PHPBB_WRITABLE_FILE_MODE: $file_mode" >&2; exit 2 ;;
esac

if [ ! -d "$web_root" ]; then
	echo "phpBB web root not found: $web_root" >&2
	exit 1
fi

apply_mode()
{
	mode=$1
	target=$2
	type=$3

	if [ "$type" = "directory" ]; then
		if [ ! -d "$target" ]; then
			echo "Required directory not found: $target" >&2
			return 1
		fi
	else
		if [ ! -f "$target" ]; then
			echo "Required file not found: $target" >&2
			return 1
		fi
	fi

	if [ "$dry_run" -eq 1 ]; then
		printf 'chmod %s %s\n' "$mode" "$target"
	else
		chmod "$mode" "$target"
	fi
}

failed=0

for path in \
	album_mod/upload \
	album_mod/upload/cache \
	cache \
	files \
	files/thumbs \
	images/avatars \
	pafiledb/cache \
	pafiledb/cache/templates \
	pafiledb/images/screenshots \
	pafiledb/uploads
do
	apply_mode "$dir_mode" "$web_root/$path" directory || failed=1
done

for path in \
	includes/def_icons.php \
	includes/def_themes.php \
	includes/def_tree.php \
	includes/def_words.php \
	ctracker/logfiles/logfile_attempt_counter.txt \
	ctracker/logfiles/logfile_blocklist.txt \
	ctracker/logfiles/logfile_debug_mode.txt \
	ctracker/logfiles/logfile_malformed_logins.txt \
	ctracker/logfiles/logfile_spammer.txt \
	ctracker/logfiles/logfile_worms.txt
do
	apply_mode "$file_mode" "$web_root/$path" file || failed=1
done

if [ "$failed" -ne 0 ]; then
	echo "Some required paths were missing; permissions were not fully applied." >&2
	exit 1
fi

if [ "$dry_run" -eq 1 ]; then
	echo "Dry run complete. No permissions were changed."
else
	echo "phpBB2 Plus writable permissions applied successfully."
fi

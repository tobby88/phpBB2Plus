# Sourced only by grouped native CI steps. Keep the original executable,
# arguments, environment, streams and status; measure each command once.
php() {
    local started=$SECONDS status
    if command php "$@"; then
        status=0
    else
        status=$?
    fi
    printf 'PHPBB_NATIVE_TIMING seconds=%s status=%s command=' "$((SECONDS - started))" "$status" >&2
    printf '%q ' php "$@" >&2
    printf '\n' >&2
    return "$status"
}

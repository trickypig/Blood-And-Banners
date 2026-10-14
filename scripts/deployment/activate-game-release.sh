#!/bin/sh
set -eu

usage() {
    echo "Usage: activate-game-release.sh activate|rollback trickypig/blood RELEASE_ID" >&2
    exit 2
}

test "$#" -eq 3 || usage
action=$1
root=$2
release=$3

case "$action" in
    activate|rollback) ;;
    *) usage ;;
esac
test "$root" = "trickypig/blood" || usage
timestamp=${release%-*}
revision=${release#*-}
case "$timestamp" in
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
    *) usage ;;
esac
case "$revision" in
    ''|*[!0-9a-f]*) usage ;;
esac
test "${#revision}" -ge 7 || usage
test "${#revision}" -le 40 || usage

stage="$root/.deploy/$release"
previous="$root/.deploy/previous-$release"
live_public="$root/public/play"
live_server="$root/server/api"

activate_release() {
    test -d "$stage/public/play"
    test -d "$stage/server/api"
    test ! -e "$previous"
    test -f "$root/.env"
    test "$(stat -c %a "$root/.env")" = 600

    find "$stage/server/api" -type f -name '*.php' -print |
        while IFS= read -r php_file; do
            php8.4-cli -l "$php_file" || exit 1
        done
    php8.4-cli "$stage/server/api/tools/migrate.php" --env-file "$root/.env"

    mkdir -p "$root/public" "$root/server" "$previous/public" "$previous/server"

    original_public_moved=0
    original_server_moved=0
    stage_public_moved=0
    stage_server_moved=0

    restore_failed_activation() {
        status=$?
        trap - EXIT HUP INT TERM

        if test "$stage_server_moved" -eq 1 && test -e "$live_server"; then
            mkdir -p "$stage/server"
            /bin/mv "$live_server" "$stage/server/api" || true
        fi
        if test "$stage_public_moved" -eq 1 && test -e "$live_public"; then
            mkdir -p "$stage/public"
            /bin/mv "$live_public" "$stage/public/play" || true
        fi
        if test "$original_server_moved" -eq 1 && test -e "$previous/server/api"; then
            /bin/mv "$previous/server/api" "$live_server" || true
        fi
        if test "$original_public_moved" -eq 1 && test -e "$previous/public/play"; then
            /bin/mv "$previous/public/play" "$live_public" || true
        fi
        exit "$status"
    }
    trap restore_failed_activation EXIT HUP INT TERM

    if test -e "$live_public"; then
        mv "$live_public" "$previous/public/play"
        original_public_moved=1
        : > "$previous/.had-public"
    fi
    if test -e "$live_server"; then
        mv "$live_server" "$previous/server/api"
        original_server_moved=1
        : > "$previous/.had-server"
    fi

    mv "$stage/public/play" "$live_public"
    stage_public_moved=1
    mv "$stage/server/api" "$live_server"
    stage_server_moved=1

    trap - EXIT HUP INT TERM
    echo "Activated $release; previous release retained at $previous"
}

rollback_release() {
    test -d "$stage"
    test -d "$previous"
    test -d "$live_public"
    test -d "$live_server"
    test ! -e "$stage/public/play"
    test ! -e "$stage/server/api"

    mkdir -p "$stage/public" "$stage/server"
    /bin/mv "$live_public" "$stage/public/play"
    /bin/mv "$live_server" "$stage/server/api"

    if test -f "$previous/.had-public"; then
        test -d "$previous/public/play"
        /bin/mv "$previous/public/play" "$live_public"
    fi
    if test -f "$previous/.had-server"; then
        test -d "$previous/server/api"
        /bin/mv "$previous/server/api" "$live_server"
    fi

    rm -f "$previous/.had-public" "$previous/.had-server"
    rmdir "$previous/public" "$previous/server" "$previous"
    echo "Rolled back $release; release retained at $stage"
}

if test "$action" = activate; then
    activate_release
else
    rollback_release
fi

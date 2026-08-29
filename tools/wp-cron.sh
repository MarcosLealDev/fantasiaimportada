#!/usr/bin/env bash
#
# Run WordPress cron from the system scheduler instead of from page views.
#
#   */5 * * * * /opt/fantasiaimportada/tools/wp-cron.sh
#
# WHY THIS EXISTS
#
# WordPress fires cron opportunistically: on a page view it makes a second,
# non-blocking HTTP request to itself, and that request boots the whole stack
# again. On this site that is a 6,900-product WooCommerce install on a SHARED
# single-vCPU droplet, so every spawn is expensive and the rate scales with
# traffic rather than with how much work there is to do.
#
# Measured on 2026-08-29 before this change: 70 self-requests in one hour,
# 7.2% of all requests to the site, ~1.2/min -- driven by crawler traffic, not
# by any actual scheduled work. At */5 that becomes 12/hour.
#
# This only pays off with DISABLE_WP_CRON set (see .env.production.example);
# otherwise both mechanisms run and the spawns continue.
#
# RUN IN THE CONTAINER, NOT ON THE HOST. The host has no PHP, and the webroot
# paths that WordPress resolves are the container's.

set -euo pipefail

CONTAINER=fi-wordpress
LOG=/var/log/fi-wp-cron.log

log() { echo "$(date -Is) $*" >> "$LOG"; }

if ! docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
    log "FAILED: $CONTAINER is not running; scheduled tasks are not being processed"
    exit 1
fi

# flock stops a slow run from overlapping the next tick. Without it a job that
# takes longer than the interval stacks up, and on one vCPU that compounds
# quickly. -n means "skip this tick" rather than queue behind it.
exec 9>/var/lock/fi-wp-cron.lock
if ! flock -n 9; then
    log "skipped: previous run still in progress"
    exit 0
fi

# timeout bounds a wedged run; www-data so nothing in wp-content is created
# root-owned, which would break later writes from the admin.
if timeout 300 docker exec -u www-data "$CONTAINER" \
        php -d max_execution_time=290 /var/www/html/wp-cron.php >>"$LOG" 2>&1; then
    : # quiet on success -- this runs 288 times a day
else
    rc=$?
    [ "$rc" -eq 124 ] && log "FAILED: timed out after 300s" || log "FAILED: wp-cron.php exited $rc"
    exit 1
fi

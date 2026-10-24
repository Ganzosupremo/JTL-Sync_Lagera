#!/bin/bash
set -e

# This is a plain PHP app (no Composer/npm dependencies to install).
# Database schema changes are applied automatically by
# App\Support\Database::migrate(): every entry point calls it, and it runs
# the full migration only when app/Support/Database.php changed since the
# last run, so no explicit migration step is needed here.
#
# Catch any PHP syntax error introduced by a merge before it reaches users.
find app public config -name "*.php" -print0 | xargs -0 -n1 php -l

# Ensure runtime directories exist (bootstrap also creates these, but keep
# this script self-sufficient and idempotent).
mkdir -p storage storage/logs

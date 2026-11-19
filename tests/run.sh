#!/bin/sh
# Run the test bench with Docker, from anywhere in the repository:
#
#   tests/run.sh                 every module, MariaDB 10.11
#   tests/run.sh --db mysql-8.0  another database server (see the list below)
#   tests/run.sh AUTH TIRAGE     some modules only
#   tests/run.sh --force-core    download and update the ianseo core again
#
# Nothing is installed on the machine besides the Docker images, no port is opened,
# and the database lives in memory for the duration of the run.
set -eu

cd "$(dirname "$0")"

db="mariadb-10.11"
core_args=""
while [ $# -gt 0 ]; do
    case "$1" in
        --db) db="$2"; shift 2 ;;
        --force-core) core_args="--force"; shift ;;
        *) break ;;
    esac
done

case "$db" in
    mariadb-10.11) DB_IMAGE="mariadb:10.11@sha256:7d829180b5b2a9ba74f6242876ab401a0d7dd338a807e522ef92bc9698760f20" ;;
    mariadb-11.4)  DB_IMAGE="mariadb:11.4@sha256:5e73baf8179b29f115101bb102763a47a017361f3e9792daccba6646c3ab1532" ;;
    mysql-8.0)     DB_IMAGE="mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b" ;;
    mysql-8.4)     DB_IMAGE="mysql:8.4@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902" ;;
    *) echo "unknown database server: $db (mariadb-10.11, mariadb-11.4, mysql-8.0, mysql-8.4)" >&2; exit 2 ;;
esac
export DB_IMAGE

mkdir -p .cache
compose="docker compose -f docker/compose.yml"
trap '$compose down --volumes --remove-orphans >/dev/null 2>&1 || true' EXIT

$compose build --quiet bench
$compose run --rm bench bin/core.php $core_args
status=0
$compose run --rm bench bin/check.php "$@" || status=1
$compose run --rm bench bin/pages.php "$@" || status=1
exit $status

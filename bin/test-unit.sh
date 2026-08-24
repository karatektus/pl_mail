#!/bin/sh
# PHPUnit against a database of its own.
#
# WHY THIS EXISTS
# ───────────────
# The unit suite and the browser stack both run APP_ENV=test, and Doctrine's
# `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` therefore pointed both at
# `app_test`. One database, two suites, and one of the tests in here truncates
# real tables: ResetHandsBackRegistrationsTest exercises a data reset, which is
# the whole point of it.
#
# Sequentially that is safe — Postgres rolls a TRUNCATE back like anything else.
# Run the two at once and it is not: TRUNCATE takes an ACCESS EXCLUSIVE lock, so
# every live query the browser suite makes against those tables blocks until the
# unit test's transaction ends, and the browser suite reports timeouts in specs
# that have nothing to do with any of this. It can also go the other way, which
# fits a transaction abort seen once here and never reproduced.
#
# TEST_TOKEN is Symfony's own answer to this — the mechanism that gives each
# parallel test process its own database — so the fix is to use it rather than
# to invent a second DATABASE_URL.
#
# The cost is that a fresh database needs creating, migrating and seeding, which
# is what the rest of this does. Migrations are a no-op once they have run, so
# only the first invocation is slow.
#
# The two seeded users are not optional: PageRendersTest, ListFragmentTest and
# friends skip themselves when the admin is missing, so without these a green
# run would quietly be ~50 tests smaller than it looks.
set -e

export TEST_TOKEN=_unit

php bin/console doctrine:database:create --if-not-exists --quiet
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet
php bin/console app:test:seed-user --quiet
php bin/console app:test:seed-user --admin --email=e2e-admin@plmail.test --password=e2e-admin-password --quiet

exec php vendor/bin/phpunit "$@"

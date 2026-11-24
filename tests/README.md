# Testing

## 1. In-WordPress suite (run on each real site)

Covers every class, including `Admin\Settings` and `Admin\IncidentsView`. It needs no dependencies, supports PHP 7.4+ / WP 6.0+, mocks all HTTP and uses temporary tables for local incident data. Run it on a disposable test site: it temporarily changes options, transients, and scheduled events.

With WP-CLI (exit code 1 on failure):

    wp plugin activate website-watchdog
    wp eval-file wp-content/plugins/website-watchdog/tests/site/run.php [filter]

Without WP-CLI, add `define('WEBSITE_WATCHDOG_SELFTEST', true);` to `wp-config.php`, then open **Tools > Watchdog Self-Test** and click Run. Remove the constant afterwards.

## 2. Fast stub-based PHPUnit suite (no WordPress needed)

    php tools/phpunit.phar        # or: composer test

`tests/`, `tools/` and CI files are excluded from release packages via `.distignore`.

## 3. Browser collector tests

    node --test tests/frontend.test.js

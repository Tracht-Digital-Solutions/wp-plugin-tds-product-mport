# TDS Product Importer for WooCommerce

A resumable CSV/XML importer for large WooCommerce catalogs with a guided,
autosaving import wizard and an expert preset editor.

The wizard covers source connection, structure detection, mapping suggestions,
import rules, preflight validation, and live background-job progress. Existing
presets are cloned safely and their schedules remain unchanged.

Developed by Julian Tracht of Tracht Digital Solutions.

Repository: https://github.com/Tracht-Digital-Solutions/wp-plugin-tds-product-mport

Current release: **2.0.0**. Tested with WordPress 7.1 and WooCommerce
11.0.1. Minimum requirements remain PHP 8.1, WordPress 6.5, and
WooCommerce 8.2.

## Development

Requirements: PHP 8.1+, Composer 2, Node.js 20+, WordPress 6.5+, WooCommerce 8.2+.

```sh
composer install
npm run build
composer test
npm run test:js
```

Run the WooCommerce integration suite against the pinned target environment:

```sh
npx wp-env start --config=tests/env/wp-target.json
npx wp-env --config=tests/env/wp-target.json run cli sh -- -lc \
  'PLUGIN_DIR=$(dirname "$(find wp-content/plugins -name tds-product-importer.php -print -quit)"); WC_PLUGIN_FILE=$(find /var/www/html/wp-content/plugins -path "*/woocommerce.php" -print -quit); cd "$PLUGIN_DIR" && WC_PLUGIN_FILE="$WC_PLUGIN_FILE" ./vendor/bin/phpunit -c phpunit.integration.xml.dist'
npm run test:e2e
```

The minimum environment is available in `tests/env/wp-min.json`. Build a
release package with `composer install --no-dev --classmap-authoritative`
followed by `npm run package`. The ZIP and SHA-256 checksum are
written to `dist/` using the same packaging logic as CI and GitHub Releases.

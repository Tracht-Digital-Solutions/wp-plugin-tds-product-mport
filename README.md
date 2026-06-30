# TDS Product Importer for WooCommerce

A resumable CSV/XML importer for large WooCommerce catalogs.

Developed by Julian Tracht of Tracht Digital Solutions.

Repository: https://github.com/Tracht-Digital-Solutions/wp-plugin-tds-product-mport

## Development

Requirements: PHP 8.1+, Composer 2, Node.js 20+, WordPress 6.5+, WooCommerce 8.2+.

```sh
composer install
npm run build
composer test
npm run test:js
```

Build a release package with `npm run package`. The ZIP and SHA-256 checksum are written to `dist/`.

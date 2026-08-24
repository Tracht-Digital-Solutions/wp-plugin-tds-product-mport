=== TDS Product Importer for WooCommerce ===
Contributors: tds
Tags: woocommerce, product import, csv, xml, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.2
WC tested up to: 11.0.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and update large WooCommerce product catalogs from CSV, XML, HTTPS, or SFTP with reusable mapping presets.

== Description ==

TDS Product Importer processes large catalogs in resumable background batches. Its guided import wizard covers source setup, automatic structure detection, mapping suggestions, import rules, mandatory preflight validation, and live job progress.

Wizard drafts are autosaved and can be resumed for 30 days. Existing presets are copied safely, so their schedules and running automations remain unchanged. The expert preset editor remains available for advanced configuration.

No telemetry is collected. The plugin only contacts source and media locations explicitly configured by an administrator.

Developed by Julian Tracht of Tracht Digital Solutions.

= Mapping formats =

List targets such as categories, tags, gallery images, upsells, and grouped children accept arrays or values separated by comma, semicolon, or pipe.

Attributes accept JSON, for example:

`{"Color":{"options":["Red","Blue"],"visible":true,"variation":true}}`

Downloads accept JSON entries with `name` and `url`. Generic metadata uses a target beginning with `meta:`. Existing ACF fields are exposed as targets beginning with `acf:`.

Flat variation rows use the mapped `parent` target. XML feeds may instead contain repeated `variation` or `variant` child records below each product.

SFTP presets require a pinned OpenSSH SHA256 or MD5 host-key fingerprint. Password and private-key authentication are supported.

== Installation ==

1. Install and activate WooCommerce 8.2 or newer.
2. Upload and activate the plugin ZIP.
3. Open WooCommerce > TDS Import.
4. Choose "New Import" and follow the guided source, structure, mapping, rules, and preflight steps.

== Frequently Asked Questions ==

= Can an interrupted import continue? =

Yes. Jobs are persisted and processed through Action Scheduler.

= Are credentials stored as plain text? =

No. Source secrets are encrypted with keys derived from the WordPress salts.

= Does the plugin execute PHP from mapping expressions? =

No. Expressions use a small, validated language with a fixed function allowlist.

== Changelog ==

= 2.0.0 =
* Added the TDS-branded step-by-step import wizard with resumable drafts, source detection, mapping suggestions, preflight review, and live job progress.
* Added resumable staging, bounded previews, per-batch lookup caches, normalized media reuse, job rate/ETA metrics, and stronger global job locking.
* Improved mapping confirmation, autosave safety, keyboard navigation, focus handling, mobile layouts, and warning visibility.
* Updated compatibility and development tooling for WordPress 7.1 and WooCommerce 11.0.1.
* Unified local, CI, and GitHub Release packaging for reproducible ZIP and SHA-256 artifacts.

= 1.0.1 =
* Updated repository metadata after the public repository rename.

= 1.0.0 =
* Initial release.

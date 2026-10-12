=== GrabWP Restore ===
Contributors: taicv
Tags: restore, backup, migration, import
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restore a full WordPress site from a GrabWP tenant export ZIP file.

== Description ==

GrabWP Restore is a standalone WordPress plugin that restores a full site from a GrabWP Tenancy Pro export archive. It handles database import with prefix rewriting, file restoration (uploads, plugins, themes), and site URL updates.

**Features:**

* WordPress-validated ZIP upload stored outside the public web root
* Streaming SQL import (handles large databases)
* Automatic table prefix rewriting
* MySQL collation compatibility (MySQL 5.7 / 8.0)
* Safe rename-to-.old strategy for existing files
* Site URL auto-update
* Path traversal protection
* Progress tracking with step-by-step feedback

**Important:** This is a destructive operation. It will REPLACE your entire WordPress site. Back up your current site before using this plugin.

== Installation ==

1. Upload the `grabwp-restore` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Tools > GrabWP Restore
4. Upload your GrabWP export ZIP file
5. Check "I have backed up my website" and confirm the destructive operation
6. Wait for the restore to complete
7. Log in with the credentials from the exported site
8. Remove `.old` directories from wp-content/ (uploads.old, plugins.old, themes.old)
9. Deactivate and delete this plugin

== Frequently Asked Questions ==

= Will this work with any WordPress backup? =
No. This plugin only works with ZIP files exported by GrabWP Tenancy Pro's backup feature.

= Will I lose my current site? =
Yes. This plugin replaces your entire database and renames your existing content directories to .old. You can restore them manually if needed.

= What credentials do I use after restore? =
The admin credentials from the exported site. Your current credentials will no longer work.

= What are the .old directories? =
Before restoring, the plugin renames your existing plugins/, themes/, and uploads/ directories to plugins.old/, themes.old/, and uploads.old/. You can delete them after verifying the restore worked correctly.

= What about large files? =
The archive must fit within the server's upload_max_filesize and post_max_size limits. Chunked uploads were removed so uploads can use WordPress core validation without custom file assembly.

= Where are temporary files stored? =
The uploaded ZIP and restore job state are stored with private permissions in the operating system temporary directory. Restore refuses to start if that directory resolves inside the web root. database.sql is streamed directly from the ZIP and is never extracted to disk.

= Does this support WordPress Multisite? =
No. Restore is intentionally disabled on Multisite because replacing network-wide plugins, themes, and database tables safely requires a separate network-aware workflow.

= Why is a restore step available after my login session changes? =
Importing the database can replace the users and sessions tables. Remaining steps therefore use a high-entropy, short-lived job token. Only a hash of that token is stored, failed attempts are limited, and abandoned temporary state expires after six hours.

== Changelog ==

= 1.0.4 =
* Enhance: Bundled translations for Vietnamese, Spanish, Indonesian, French and Simplified Chinese. The plugin shows in these languages out of the box, no Loco Translate or extra language pack needed.
* Change: Tested up to WordPress 7.1.

= 1.0.3 =
* Security: Store uploaded archives and job state in the private system temporary directory — never under web-accessible uploads, and never protected only by .htaccess.
* Security: Stream database.sql directly from the ZIP without extracting it to disk.
* Security: Reject uploads that resolve inside the web root; verify ZIP integrity with ZipArchive::CHECKCONS.
* Security: Replace client-controlled chunk filenames with WordPress core ZIP upload validation.
* Security: Validate all archive paths and symlink attributes before writing.
* Security: Skip executable and server-configuration entries under uploads while restoring normal media files.
* Security: Preflight SQL statements against site-table and operation allowlists before modifying the database.
* Security: Store only a one-way job-token hash, limit failed token attempts, and purge the archive on abort.
* Security: Disable restore on Multisite to prevent network-wide destructive operations from subsite context.
* Compatibility: Use WP_Filesystem for file writes, moves, copies, and recursive deletion.
* Change: Remove chunked upload support; server upload limits now apply.
* Fix: Stage plugin self-preservation outside the web root; remove uninstall leftovers including legacy uploads/grabwp-restore.

= 1.0.2 =
* Security: Stop extracting plugins/themes into the working directory; restore them directly to their final wp-content destinations.
* Security: Use `$wpdb` `%i` placeholders for all dynamic table and column identifiers instead of string interpolation.
* Security: Validate table/column names from `SHOW TABLES` / `SHOW COLUMNS` with an identifier allowlist before use in SQL.
* Compatibility: Raise minimum WordPress version to 6.2 (required for `%i` support).

= 1.0.1 =
* Fix: Sanitize all `$_POST` and `$_FILES` inputs per WordPress coding standards.
* Fix: Authenticate restore steps with an HMAC job token after database replacement invalidates the session nonce.
* Fix: Use `wp_upload_dir()['basedir']` instead of hardcoded `WP_CONTENT_DIR . '/uploads'` to support custom and multisite upload paths.
* Fix: Replace bulk `ZipArchive::extractTo()` with per-file extraction to prevent symlink-based path escapes.
* Fix: Document indexed URL replacement queries used by the database migration.

= 1.0.0 =
* Initial release: restore a full WordPress site from a GrabWP Tenancy Pro export ZIP (database, uploads, plugins, and themes).
* Admin UI at Tools > GrabWP Restore with step-by-step progress feedback (administrator only; requires explicit backup confirmation).
* Chunked ZIP upload (2 MB per chunk) to bypass PHP upload size limits.
* Archive validation for GrabWP export structure (`database.sql`, `metadata.json`) with path traversal protection during extraction.
* Streaming SQL import for large databases, with automatic table prefix rewriting to match the destination site.
* MySQL collation compatibility for MySQL 5.7 and 8.0.
* Site URL auto-update and database-wide URL search-and-replace (including serialized and theme-encoded data).
* Safe file restore: existing plugins, themes, and uploads directories are renamed to .old before replacement.
* Temporary working files cleaned up on plugin deactivation.

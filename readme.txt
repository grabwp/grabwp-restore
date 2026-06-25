=== GrabWP Restore ===
Contributors: taicv
Tags: restore, backup, migration, import
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restore a full WordPress site from a GrabWP tenant export ZIP file.

== Description ==

GrabWP Restore is a standalone WordPress plugin that restores a full site from a GrabWP Tenancy Pro export archive. It handles database import with prefix rewriting, file restoration (uploads, plugins, themes), and site URL updates.

**Features:**

* 2MB chunked upload (bypasses PHP upload limits)
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
The plugin uses 2MB chunked uploads, so PHP upload limits don't apply.

== Changelog ==

= 1.0.0 =
* Initial release

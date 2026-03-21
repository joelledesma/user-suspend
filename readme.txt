=== User Suspend ===
Contributors: joelledesmajr
Tags: suspend, users, moderation, security, login
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 2.0.5
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Suspend WordPress user accounts with timed or permanent bans, reasons, email notifications, and a full audit log.

== Description ==

User Suspend gives you a straightforward way to suspend WordPress user accounts. Suspended users are blocked at login and their active sessions are ended immediately.

You can set a reason for each suspension, choose a date for it to lift automatically, and track everything through a built-in audit log.

**Features**

* Permanent or timed suspensions — set an expiry date and the account is automatically restored
* Suspension reasons stored and emailed to the user when their account is suspended
* Active sessions are destroyed the moment a suspension is applied
* Audit log tracks every suspend and unsuspend action with timestamps
* Dedicated Suspended Users page under the Users menu
* Status column in the Users list table
* Bulk suspend and unsuspend from the Users list table
* Administrators and the currently logged-in user cannot be suspended
* Object caching for fast suspension status lookups
* Clean uninstall removes all plugin data

== Installation ==

1. Upload the `user-suspend` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Users → Suspended Users** to manage suspensions, or open any user's profile to suspend them individually.

== Frequently Asked Questions ==

= Will suspending a user log them out immediately? =

Yes. The moment you save a suspension, all of that user's active sessions are destroyed.

= Can I set a suspension to expire automatically? =

Yes. When suspending a user from their profile page, set a date and time in the Expiry field. The account will be restored automatically when that time passes.

= Can administrators be suspended? =

No. Administrator accounts and your own account are protected and cannot be suspended.

= What happens to the suspension data if I deactivate the plugin? =

Deactivating the plugin does not remove any data. Uninstalling (deleting) the plugin removes all suspension records, audit log entries, and plugin options from the database.

== Screenshots ==

1. Suspension section on the user profile edit screen.
2. Suspended Users admin page with audit log.
3. Status column in the Users list table.

== Changelog ==

= 2.0.3 =
* Renamed plugin to User Suspend.
* Updated Tested up to: 6.9.
* Added readme.txt for WordPress.org.
* Fixed all WordPress Coding Standards violations.
* Added complete PHPDoc coverage across all files.

= 2.0.1 =
* Fixed activation timeout in WordPress Playground by deferring data migration to admin_init.

= 2.0.0 =
* Complete rewrite with class-based architecture.
* Added timed suspensions, reasons, audit log, email notifications, bulk actions, and session destruction.
* Fixed critical logic bug in v1.0 where saving any user profile could trigger a ban.
* Added automatic migration of v1.0 ban data on activation.

== Upgrade Notice ==

= 2.0.3 =
Rename to User Suspend. No database changes — safe to upgrade from 2.0.x.

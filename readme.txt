=== One-Click Duplicate ===
Contributors: mobiletechspecialists
Donate link: https://mtsuav.com/shop/
Tags: duplicate post, duplicate page, duplicate product, clone, woocommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicate posts, pages, products, and custom post types in one click, with full control over what gets copied.

== Description ==

One-Click Duplicate adds one-click duplication for posts, pages, WooCommerce products, and any custom post type. Clone an item as a draft to safely rework it, or duplicate straight to published.

Three ways to duplicate:

* A "Duplicate" link in the row actions of every content list table
* A "Duplicate" bulk action to clone many items at once
* A "Duplicate this" item in the admin bar when viewing any singular page on the frontend

Everything is configurable under Settings > Duplicate Content:

* Choose which post types can be duplicated
* Set the new item status: draft, pending review, published, or same as the original
* Add a title prefix or suffix, for example "My Post (Copy)"
* Copy taxonomies (categories, tags, custom terms)
* Copy custom fields, with an exclusion list for keys that should never be copied
* Copy the featured image as a new, independent attachment
* Copy comments, keeping reply threads intact
* Choose where to go after duplicating: the edit screen, the list table, or stay put
* Set the minimum capability required, enforced on every trigger with nonce protection

Works with hierarchical post types. Menu order, parent, and page template are preserved. Admin notices confirm each duplication with links to the new item.

== Installation ==

1. Upload the `one-click-duplicate` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > Duplicate Content to choose which content types can be duplicated and what gets copied.
4. Use the "Duplicate" row action, the "Duplicate" bulk action, or the admin bar item to clone content.

== Frequently Asked Questions ==

= Which content types can be duplicated? =

Any public post type you enable under Settings > Duplicate Content: posts, pages, WooCommerce products (when WooCommerce is active), and custom post types from themes or other plugins.

= Will the copy keep my categories and tags? =

Yes, when "Copy taxonomies" is enabled (the default). Categories, tags, and custom taxonomy terms are carried over to the new item.

= What happens to custom fields? =

All post meta is copied when "Copy custom fields" is enabled, except the keys listed in the exclusion textarea. `_edit_lock`, `_edit_last`, and `_wp_old_slug` are excluded by default. The featured image reference is handled separately so the copy gets its own independent attachment.

= Who can duplicate content? =

Anyone with the minimum capability you set (Contributor level by default). Every action is nonce-protected and capability-checked.

= Does it work with pages and hierarchical post types? =

Yes. Parent, menu order, and page template are preserved on the copy.

= How do I get updates? =

The plugin checks the public GitHub releases page for this project about twice a day and offers updates through the normal WordPress Updates screen. No account or license key needed.

== Screenshots ==

1. The "Duplicate" row action on the posts list table.
2. The settings page with copy options.
3. The admin bar "Duplicate this" item on the frontend.

== Changelog ==

= 1.0.0 =
* Initial release.
* Duplicate row action, bulk action, and admin bar trigger.
* Configurable status, title prefix/suffix, taxonomies, meta, featured image, and comments.
* Capability setting enforced on every trigger.
* Hierarchical post type support with menu order and page template preserved.
* Automatic updates from GitHub releases.

== Privacy ==

This plugin does not collect, store, or transmit any personal data.

The automatic updater polls the public GitHub API (`https://api.github.com/repos/opsecfreak/one-click-duplicate/releases/latest`) about twice a day to check for new releases. The request carries a generic updater user-agent (MTSUAV-Updater plus your WordPress version); no site URL, license keys, emails, or other personal data are sent. No other outbound requests are made.

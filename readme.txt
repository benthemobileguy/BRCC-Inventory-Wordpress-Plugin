=== BRCC Inventory Tracker ===
Contributors: Ben, Bright
Tags: woocommerce, inventory, square, eventbrite, ecommerce
Requires at least: 5.0
Tested up to: 6.2
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Sync the WooCommerce inventory with Square and Eventbrite platforms.

== Description ==

BRCC Inventory Tracker provides a seamless integration between WooCommerce, Square, and Eventbrite platforms, allowing you to track and synchronize inventory across all three systems.

= Features =

* **Daily Sales Tracking**: Monitor sales on a daily basis with detailed reporting.
* **Square Integration**: Automatically update inventory in Square when products are sold in WooCommerce.
* **Eventbrite Integration**: Keep ticket availability in sync with the WooCommerce inventory.
* **REST API**: External systems can interact with your inventory through our REST API.
* **Client-Facing Display**: Use shortcodes to display sales data to clients.

= How It Works =

1. When orders are placed in WooCommerce, the plugin automatically tracks sales data.
2. If we set up Square integration, it would update inventory in Square.
3. If we set up Eventbrite integration, it would update ticket availability.
4. The plugin can also sync inventory periodically to ensure everything is up to date.

= Shortcodes =

* `[brcc_sales_data]` - Display sales data for a specified date range.
  * Parameters:
    * `date` - The end date for the data (default: current date)
    * `days` - Number of days to include (default: 7)

= Requirements =

* WordPress 5.0 or higher
* WooCommerce 3.0 or higher
* PHP 7.2 or higher

== Installation ==

1. Upload the `brcc-inventory-tracker` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'BRCC Inventory' in your admin menu to configure the plugin

== Possible Questions, for better clarity ==

= Does this plugin require WooCommerce? =

Yes, the BRCC Inventory Tracker requires WooCommerce to be installed and activated.

= How often does the plugin sync inventory? =

By default, the plugin syncs inventory every 15 minutes. You can adjust this interval in the plugin settings.

= Can I manually trigger a sync? =

Yes, you can manually trigger a sync from the dashboard page by clicking the "Sync Now" button.

= How do I display sales data to clients? =

You can use the `[brcc_sales_data]` shortcode in any post or page to display sales data. For example:

`[brcc_sales_data days="30"]` will display sales data for the last 30 days.

== Screenshots ==

1. Dashboard view showing daily sales data
2. Settings page for API configuration
3. Product mapping interface
4. Front-end display of sales data

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of the BRCC Inventory Tracker plugin.
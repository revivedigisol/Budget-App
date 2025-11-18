<?php
/**
 * Plugin Name: WP ERP — Budgeting
 * Description: Budgeting & Budget Performance module for WP ERP (Accounting). Requires WP ERP to be installed and active.
 * Version: 0.1.0
 * Author: Enle
 * Author URI: https://enle.org
 * Text Domain: erp-budgeting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Ensure plugin.php functions are available
if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Basic constants
if ( ! defined( 'ERP_BUDGETING_PLUGIN_DIR' ) ) {
    define( 'ERP_BUDGETING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ERP_BUDGETING_PLUGIN_URL' ) ) {
    define( 'ERP_BUDGETING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Asset paths for React admin UI
if ( ! defined( 'ERP_BUDGETING_ASSETS' ) ) {
    define( 'ERP_BUDGETING_ASSETS', ERP_BUDGETING_PLUGIN_URL . 'assets' );
}
if ( ! defined( 'ERP_BUDGETING_PATH' ) ) {
    define( 'ERP_BUDGETING_PATH', dirname( __FILE__ ) );
}

// Load PSR-4 autoloader included with the plugin (fallback if composer autoload not present)
if ( file_exists( ERP_BUDGETING_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once ERP_BUDGETING_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    require_once ERP_BUDGETING_PLUGIN_DIR . 'includes/src/Autoloader.php';
    \Enle\ERP\Budgeting\Autoloader::register();
}

// Bootstrap runtime components (PSR-4 namespaced)
require_once ERP_BUDGETING_PLUGIN_DIR . 'includes/src/Bootstrap.php';

use Enle\ERP\Budgeting\Migration;
use Enle\ERP\Budgeting\Cron\Reconciler;

/**
 * Ensure WP ERP is active before loading module features.
 */
function erp_budgeting_is_erp_active() {
    // Check common plugin install locations first (supports different WP ERP folder names)
    if ( function_exists( 'is_plugin_active' ) ) {
        if ( is_plugin_active( 'erp/wp-erp.php' ) || is_plugin_active( 'wp-erp/wp-erp.php' ) ) {
            return true;
        }
    }

    // Runtime checks: WP ERP exposes a global initializer `wperp()` and a main class `WeDevs_ERP`.
    if ( function_exists( 'wperp' ) || class_exists( 'WeDevs_ERP' ) || class_exists( 'WeDevs\\ERP\\ERP' ) ) {
        return true;
    }

    return false;
}

function erp_budgeting_admin_notice_wp_erp_missing() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'WP ERP — Budgeting requires WP ERP to be installed and active. Please install/activate WP ERP plugin.', 'erp-budgeting' ); ?></p>
    </div>
    <?php
}

if ( ! erp_budgeting_is_erp_active() ) {
    add_action( 'admin_notices', 'erp_budgeting_admin_notice_wp_erp_missing' );
    return; // Do not initialize when WP ERP isn't present
}

// Activation
register_activation_hook( __FILE__, function() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    Migration::createTables();

    $role = get_role( 'administrator' );
    if ( $role && ! $role->has_cap( 'manage_erp_budgets' ) ) {
        $role->add_cap( 'manage_erp_budgets' );
    }

    if ( class_exists( Reconciler::class ) ) {
        Reconciler::schedule();
    }
} );

register_deactivation_hook( __FILE__, function() {
    if ( class_exists( Reconciler::class ) ) {
        Reconciler::unschedule();
    }
} );

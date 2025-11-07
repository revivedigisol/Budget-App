<?php

namespace Enle\ERP\Budgeting\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ], 50 );
    }

    public function register_menus() {
        // Detect WP ERP parent slug. WP ERP can be installed in different folder names.
        $parent = null;
        if ( function_exists( 'is_plugin_active' ) ) {
            if ( is_plugin_active( 'erp/wp-erp.php' ) ) {
                $parent = 'erp';
            } elseif ( is_plugin_active( 'wp-erp/wp-erp.php' ) ) {
                $parent = 'wp-erp';
            }
        }
        // If detection failed, we'll fall back to index.php (Tools) when registering submenu pages.

        $capability = 'manage_erp_budgets';

        // Main Budgets page
        $slug = 'erp-budgeting';
        $hook = add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Budgets', 'erp-budgeting' ),
            __( 'Budgets', 'erp-budgeting' ),
            $capability,
            $slug,
            [ $this, 'render_page' ]
        );

        // Create Budget (quick link)
        add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Create Budget', 'erp-budgeting' ),
            __( 'Create Budget', 'erp-budgeting' ),
            $capability,
            'erp-budgeting-new',
            [ $this, 'render_page' ]
        );

        // Reports
        add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Budget Reports', 'erp-budgeting' ),
            __( 'Budget Reports', 'erp-budgeting' ),
            $capability,
            'erp-budgeting-reports',
            [ $this, 'render_page' ]
        );

        // Ensure our Assets class can detect the admin page hook by using the returned hook suffix
        if ( $hook ) {
            // store for later use if needed
            add_action( 'load-' . $hook, function() {
                // nothing for now, but this triggers when the page loads
            } );
        }
    }

    public function render_page() {
        // Capability check
        if ( ! current_user_can( 'manage_erp_budgets' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'ERP Budgeting', 'erp-budgeting' ) . '</h1>';
        // Root for React app
        echo '<div id="erp-budgeting-root"></div>';
        echo '</div>';
    }
}

<?php

namespace Enle\ERP\Budgeting\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ], 50 );
        // Ensure roles have the required capability on admin init
        add_action( 'admin_init', [ $this, 'ensure_budget_roles' ] );
        // Handle cashbook save from React (admin-post)
        add_action( 'admin_post_erp_budgeting_save_cashbook', [ $this, 'handle_cashbook_save' ] );
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

        // Extra routes used by the React app. Registering these slugs
        // prevents WP from blocking direct access to ?page=erp-budgeting-new
        // and ?page=erp-budgeting-reports (they reuse the same React root).
        // Register helper routes using the plugin capability so they are routable
        add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Create Budget', 'erp-budgeting' ),
            __( 'Create Budget', 'erp-budgeting' ),
            $capability,
            'erp-budgeting-new',
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Budget Reports', 'erp-budgeting' ),
            __( 'Budget Reports', 'erp-budgeting' ),
            $capability,
            'erp-budgeting-reports',
            [ $this, 'render_page' ]
        );

        // Cash Book page - visible submenu that uses the same React root
        add_submenu_page(
            $parent ? $parent : 'index.php',
            __( 'Cash Book', 'erp-budgeting' ),
            __( 'Cash Book', 'erp-budgeting' ),
            $capability,
            'erp-budgeting-cashbook',
            [ $this, 'render_page' ]
        );

        // Hide the helper submenu items via admin CSS so they remain routable but are not visible in the admin menu
        add_action( 'admin_head', function() {
            echo '<style>#adminmenu a[href*="page=erp-budgeting-new"], #adminmenu a[href*="page=erp-budgeting-reports"]{display:none!important;}</style>';
        } );

        // Ensure our Assets class can detect the admin page hook by using the returned hook suffix
        if ( $hook ) {
            // store for later use if needed
            add_action( 'load-' . $hook, function() {
                // nothing for now, but this triggers when the page loads
            } );
        }
    }

    /**
     * Ensure the roles we want to allow have the plugin capability.
     * Grants 'manage_erp_budgets' to Administrator, optional 'admin' role if present,
     * and to WP ERP Accounting Manager role (erp_ac_manager) if defined.
     */
    public function ensure_budget_roles() {
        // Roles to grant capability to
        $roles = [ 'administrator', 'admin' ];

        foreach ( $roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( 'manage_erp_budgets' ) ) {
                $role->add_cap( 'manage_erp_budgets' );
            }
        }

        // If WP ERP accounting manager role exists, grant capability
        if ( function_exists( 'erp_ac_get_manager_role' ) ) {
            $ac_role_key = erp_ac_get_manager_role(); // typically 'erp_ac_manager'
            $role = get_role( $ac_role_key );
            if ( $role && ! $role->has_cap( 'manage_erp_budgets' ) ) {
                $role->add_cap( 'manage_erp_budgets' );
            }
        }
    }

    public function render_page() {
        // Capability check: only users with the plugin capability can access
        if ( ! current_user_can( 'manage_erp_budgets' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'ERP Budgeting', 'erp-budgeting' ) . '</h1>';
        // Root for React app
        echo '<div id="erp-budgeting-root"></div>';
        echo '</div>';
    }

    /**
     * Render a simple Cash Book page with a fillable table.
     * This is a lightweight PHP-rendered page (not the React root) so users
     * can quickly enter cashbook lines similar to the provided mockup image.
     */
    public function handle_cashbook_save() {
        // Accept JSON or form-encoded POST to save cashbook rows.
        if ( ! current_user_can( 'manage_erp_budgets' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }

        // Read raw JSON body if present
        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );

        if ( empty( $data ) ) {
            // Fallback to POST data
            $data = $_POST;
        }

        $nonce = $data['nonce'] ?? $data['erp_budgeting_cashbook_nonce'] ?? $data['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'erp_budgeting_cashbook_save' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $rows = $data['rows'] ?? $data['cashbook_rows'] ?? [];

        // Basic persistence: store submitted rows as an option for now.
        // You can replace this with a custom table or other storage later.
        update_option( 'erp_budgeting_cashbook_last', $rows );

        wp_send_json_success( [ 'message' => 'Saved', 'count' => is_array( $rows ) ? count( $rows ) : 0 ] );
    }
}

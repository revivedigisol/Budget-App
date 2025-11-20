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

/*
 * Admin-post AJAX handler for returning transactions used by the CashBook React UI.
 * Expects a JSON POST with: { nonce, status, start, end, type? }
 * Fetches /sales, /purchases, /expenses internally and filters locally.
 */
add_action( 'admin_post_erp_budgeting_get_transactions', 'erp_budgeting_get_transactions_handler' );
function erp_budgeting_get_transactions_handler() {
    if ( ! current_user_can( 'manage_erp_budgets' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
    }

    // Read JSON body
    $raw = file_get_contents( 'php://input' );
    $payload = $raw ? json_decode( $raw, true ) : $_POST;
    if ( ! is_array( $payload ) ) {
        $payload = [];
    }

    $nonce = isset( $payload['nonce'] ) ? sanitize_text_field( $payload['nonce'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'erp_budgeting_cashbook_save' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 400 );
    }

    $status = isset( $payload['status'] ) ? sanitize_text_field( $payload['status'] ) : 'completed';
    $start  = isset( $payload['start'] ) ? sanitize_text_field( $payload['start'] ) : '';
    $end    = isset( $payload['end'] ) ? sanitize_text_field( $payload['end'] ) : '';

    // We'll fetch endpoints without passing filters; local filter will be applied below
    $endpoints = [
        'sales'     => '/erp/v1/accounting/v1/transactions/sales',
        'purchases' => '/erp/v1/accounting/v1/transactions/purchases',
        'expenses'  => '/erp/v1/accounting/v1/transactions/expenses',
    ];

    $all_items = [];

    foreach ( $endpoints as $ep_key => $route ) {
        $data = null;

        // Try internal dispatch first
        if ( function_exists( 'rest_do_request' ) && class_exists( 'WP_REST_Request' ) ) {
            try {
                $request = new WP_REST_Request( 'GET', $route );
                $response = rest_do_request( $request );

                if ( ! is_wp_error( $response ) ) {
                    $status_code = method_exists( $response, 'get_status' ) ? $response->get_status() : 200;
                    $data = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
                    if ( $status_code < 200 || $status_code >= 300 ) {
                        $data = null;
                    }
                }
            } catch ( Exception $e ) {
                $data = null;
            }
        }

        // Fallback to HTTP external request if internal failed
        if ( null === $data ) {
            $site_url = get_site_url();
            $rest_endpoint = untrailingslashit( $site_url ) . '/wp-json' . $route;

            $headers = [
                'Content-Type' => 'application/json',
                'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
            ];

            $args = [ 'method' => 'GET', 'headers' => $headers, 'sslverify' => false, 'timeout' => 15 ];
            $response = wp_remote_get( $rest_endpoint, $args );

            if ( is_wp_error( $response ) ) {
                // skip this endpoint on error
                continue;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( $http_code < 200 || $http_code >= 300 ) {
                // skip on API error
                continue;
            }
        }

        // Extract items from returned payload (recursive extractor)
        $extractor = function ( $d ) use ( & $extractor ) {
            if ( is_null( $d ) ) {
                return [];
            }
            if ( is_object( $d ) ) {
                $d = (array) $d;
            }
            if ( ! is_array( $d ) ) {
                return [];
            }
            if ( isset( $d['data'] ) ) {
                return $extractor( $d['data'] );
            }
            if ( isset( $d['items'] ) ) {
                return $extractor( $d['items'] );
            }

            // detect list of associative items
            $is_list = true;
            $has_candidate = false;
            foreach ( $d as $k => $v ) {
                if ( ! is_array( $v ) && ! is_object( $v ) ) {
                    $is_list = false;
                    break;
                }
                $arr = is_object( $v ) ? (array) $v : $v;
                if ( isset( $arr['id'] ) || isset( $arr['payment_trn_date'] ) || isset( $arr['status'] ) ) {
                    $has_candidate = true;
                }
            }
            if ( $is_list && $has_candidate ) {
                $out = [];
                foreach ( $d as $v ) {
                    $out[] = is_object( $v ) ? (array) $v : $v;
                }
                return $out;
            }

            // else search children
            foreach ( $d as $v ) {
                if ( is_array( $v ) || is_object( $v ) ) {
                    $found = $extractor( $v );
                    if ( ! empty( $found ) ) {
                        return $found;
                    }
                }
            }

            return [];
        };

        $items = $extractor( $data );

        // Annotate items with source endpoint key so we can apply endpoint-specific rules later
        foreach ( $items as $it ) {
            if ( is_object( $it ) ) {
                $it = (array) $it;
            }
            if ( ! is_array( $it ) ) {
                continue;
            }
            $it['_source_endpoint'] = $ep_key;
            $all_items[] = $it;
        }
    }


    // Apply local filtering: type and status from payload (do NOT pass these to the ERP endpoint)
    $filter_type = ['payment', 'purchase', 'expense'];
    $filter_status = "paid";

    // Flatten accumulated items and apply filtering rules
    $flattened = [];
    foreach ( $all_items as $it ) {
        if ( is_object( $it ) ) {
            $it = (array) $it;
        }
        if ( ! is_array( $it ) ) {
            continue;
        }

        // If the item itself contains a nested numeric array (array within array), flatten it one level
        $nested_flattened = [];
        $has_numeric_children = false;
        foreach ( $it as $k => $v ) {
            if ( is_array( $v ) ) {
                $keys = array_keys( $v );
                $is_seq = $keys === array_keys( $keys );
                if ( $is_seq ) {
                    $has_numeric_children = true;
                    foreach ( $v as $sub ) {
                        if ( is_object( $sub ) ) {
                            $nested_flattened[] = (array) $sub;
                        } elseif ( is_array( $sub ) ) {
                            $nested_flattened[] = $sub;
                        }
                    }
                }
            }
        }

        if ( $has_numeric_children ) {
            foreach ( $nested_flattened as $n ) {
                // preserve source endpoint when flattening
                if ( is_array( $n ) ) {
                    $n['_source_endpoint'] = $it['_source_endpoint'] ?? null;
                    $flattened[] = $n;
                }
            }
            continue;
        }

        $flattened[] = $it;
    }

    $filtered = [];
    foreach ( $flattened as $item ) {
        // ensure it's an array
        if ( is_object( $item ) ) {
            $item = (array) $item;
        }
        if ( ! is_array( $item ) ) {
            continue;
        }

        // type detection: common keys
        $item_type = $item['type'] ?? $item['trn_type'] ?? $item['transaction_type'] ?? null;
        $item_status = $item['status'] ?? $item['pay_status'] ?? $item['payment_status'] ?? $item['trn_status'] ?? null;

        // If the caller requested a specific type, honor that and optionally status
        if ( $filter_type ) {
            if ( ! $item_type || ! in_array( strtolower( $item_type ), array_map( 'strtolower', $filter_type ) ) ) {
                continue;
            }
            if ( $filter_status ) {
                if ( ! $item_status || strcasecmp( $item_status, $filter_status ) !== 0 ) {
                    continue;
                }
            }
            $filtered[] = $item;
            continue;
        }

        // No type requested: include items but enforce Paid for purchases & expenses
        $source = $item['_source_endpoint'] ?? '';
        if ( in_array( $source, [ 'purchases', 'expenses' ], true ) ) {
            if ( ! $item_status || strcasecmp( $item_status, 'Paid' ) !== 0 ) {
                continue;
            }
        }

        // include sales and any other items
        $filtered[] = $item;
    }

    // Sort by available date keys (prefer payment_trn_date / pay_bill_trn_date / expense_trn_date), newest first
    usort( $filtered, function( $a, $b ) {
        $dateKeys = [ 'payment_trn_date', 'pay_bill_trn_date', 'expense_trn_date', 'payment_date', 'trn_date', 'date', 'due_date' ];
        $getDate = function( $item ) use ( $dateKeys ) {
            foreach ( $dateKeys as $k ) {
                if ( isset( $item[ $k ] ) && $item[ $k ] ) {
                    return strtotime( $item[ $k ] );
                }
            }
            return 0;
        };

        $da = $getDate( $a );
        $db = $getDate( $b );

        if ( $da === $db ) return 0;
        return ( $da > $db ) ? -1 : 1;
    } );

    wp_send_json_success( $filtered );
}

<?php
namespace Enle\ERP\Budgeting;

use Enle\ERP\Budgeting\Http\RestController;
use Enle\ERP\Budgeting\Listener\TransactionListener;
use Enle\ERP\Budgeting\Cron\Reconciler;
use Enle\ERP\Budgeting\Admin\Assets;
use Enle\ERP\Budgeting\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstrap the plugin components
 */
add_action( 'plugins_loaded', function() {
    if ( class_exists( RestController::class ) ) {
        new RestController();
    }

    if ( class_exists( TransactionListener::class ) ) {
        new TransactionListener();
    }

    if ( class_exists( Reconciler::class ) ) {
        new Reconciler();
    }

    if ( class_exists( Assets::class ) ) {
        new Assets();
    }
    if ( class_exists( Menu::class ) ) {
        new Menu();
    }
});

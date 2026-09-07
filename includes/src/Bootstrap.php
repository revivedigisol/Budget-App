<?php
namespace Enle\ERP\Budgeting;

use Enle\ERP\Budgeting\Http\RestController;
use Enle\ERP\Budgeting\Listener\TransactionListener;
use Enle\ERP\Budgeting\Cron\Reconciler;
use Enle\ERP\Budgeting\Admin\Assets;
use Enle\ERP\Budgeting\Admin\Menu;
use Enle\ERP\Budgeting\Admin\CoaDeleteButton;
use Enle\ERP\Budgeting\Sync\SyncListener;
use Enle\ERP\Budgeting\Sync\SyncCron;
use Enle\ERP\Budgeting\Sync\SyncRestController;
use Enle\ERP\Budgeting\Sync\LedgerDeleteBridge;
use Enle\ERP\Budgeting\Sync\LedgerWriteBridge;

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
    if ( class_exists( CoaDeleteButton::class ) ) {
        new CoaDeleteButton();
    }

    // Multisite consolidation roll-up (subsite books -> Holding site).
    if ( class_exists( SyncRestController::class ) ) {
        new SyncRestController();
    }
    if ( class_exists( SyncListener::class ) ) {
        new SyncListener();
    }
    if ( class_exists( SyncCron::class ) ) {
        new SyncCron();
    }
    if ( class_exists( LedgerDeleteBridge::class ) ) {
        new LedgerDeleteBridge();
    }
    if ( class_exists( LedgerWriteBridge::class ) ) {
        new LedgerWriteBridge();
    }
});

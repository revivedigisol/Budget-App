<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hourly authoritative consolidation sweep across every mapped subsite.
 *
 * Shape mirrors Cron\Reconciler: scheduled on plugin activation, unscheduled
 * on deactivation, with the overlap lock living inside ConsolidationSync. The
 * sweep only runs from the Holding site's cron so the network doesn't fire N
 * parallel full sweeps.
 */
class SyncCron {
    const HOOK = 'erp_budget_consolidation_event';

    public function __construct() {
        add_action( self::HOOK, [ $this, 'run' ] );
        add_action( 'admin_init', [ __CLASS__, 'ensureScheduled' ] );
    }

    public static function ensureScheduled() {
        if ( is_multisite() && ! EntityMap::isHolding( get_current_blog_id() ) ) {
            return;
        }
        self::schedule();
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK );
        }
    }

    public function run() {
        if ( is_multisite() && ! EntityMap::isHolding( get_current_blog_id() ) ) {
            return;
        }

        ( new ConsolidationSync() )->runAll();
    }
}

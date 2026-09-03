<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps a subsite (blog) to its 2-digit consolidation entity code and back.
 *
 * The Holding site (blog 1) carries a chart of accounts where every subsite
 * ledger is duplicated with an "NN-" prefix on its `code` column
 * (Petrol's `1200` becomes `02-1200`). This class owns the blog -> "NN"
 * relationship. It is stored as the network option `erp_budget_entity_map`
 * ( [ blog_id => 'NN', ... ] ); the baked-in default reflects the live
 * unilorinholdings.com network (entity numbers follow site-creation order,
 * NOT blog_id).
 */
class EntityMap {
    const OPTION = 'erp_budget_entity_map';

    /** Holding / consolidation root site. */
    const HOLDING_BLOG_ID = 1;

    /** Default blog_id => entity code. */
    private static $default = [
        1  => '01', // Unilorin Holdings Limited (consolidation root)
        2  => '02', // Unilorin Petrol Station
        6  => '03', // Unilorin e-Market Unit
        7  => '04', // Unilorin Zoological Garden
        8  => '05', // Unilorin Consultancy Services Centre
        9  => '06', // Unilorin Medical Screening Centre
        20 => '07', // Unilorin Plantation
        21 => '08', // Unilorin Press
        22 => '09', // Unilorin Water Enterprises
        29 => '10', // Unilorin Guest Houses & Researcher's Lodge
        30 => '11', // Unilorin Optometry
        31 => '12', // Unilorin Estate Management
    ];

    /**
     * @return array<int,string> blog_id => "NN"
     */
    public static function all() {
        $stored = is_multisite()
            ? get_site_option( self::OPTION, null )
            : get_option( self::OPTION, null );

        $map = ( is_array( $stored ) && $stored ) ? $stored : self::$default;

        $out = [];
        foreach ( $map as $blog_id => $code ) {
            $code = preg_replace( '/\D/', '', (string) $code );
            if ( '' === $code ) {
                continue;
            }
            $out[ (int) $blog_id ] = str_pad( $code, 2, '0', STR_PAD_LEFT );
        }

        return apply_filters( 'erp_budget_entity_map', $out );
    }

    /** Entity code ("NN") for a blog, or null when the blog is not consolidated. */
    public static function codeFor( $blog_id ) {
        $map = self::all();

        return isset( $map[ (int) $blog_id ] ) ? $map[ (int) $blog_id ] : null;
    }

    /** Blog ids feeding the consolidation (everything mapped except the holding root). */
    public static function sourceBlogIds() {
        return array_values( array_diff( array_keys( self::all() ), [ self::HOLDING_BLOG_ID ] ) );
    }

    public static function isHolding( $blog_id ) {
        return (int) $blog_id === self::HOLDING_BLOG_ID;
    }

    /** Persist a map (network-wide on multisite). */
    public static function save( array $map ) {
        $clean = [];
        foreach ( $map as $blog_id => $code ) {
            $code = preg_replace( '/\D/', '', (string) $code );
            if ( '' === $code ) {
                continue;
            }
            $clean[ (int) $blog_id ] = str_pad( $code, 2, '0', STR_PAD_LEFT );
        }

        return is_multisite()
            ? update_site_option( self::OPTION, $clean )
            : update_option( self::OPTION, $clean );
    }
}

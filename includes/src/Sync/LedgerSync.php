<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Chart-of-accounts roll-up: mirror every entity's ledger accounts into the
 * Holding site's consolidation chart as "NN-" twins.
 *
 *   Water (entity 09) creates ledger code 1341  ->  Holding gets "09-1341"
 *   Holding (entity 01) creates ledger code 1211 ->  Holding gets "01-1211"
 *
 * This is the companion to ConsolidationSync (which mirrors GL *postings*).
 * A posting can only be mirrored once its ledger has a twin, so this pass is
 * run first on every sweep — it means a freshly-created subsite ledger no
 * longer leaves its transactions "blocked" until someone hand-builds the twin.
 *
 * WP ERP fires no hook on ledger create/update/delete, so there is nothing to
 * listen to in real time — this is purely a reconcile:
 *
 *   - source ledger with no twin        -> create the twin
 *   - source ledger renamed             -> rename the twin
 *   - source ledger deleted             -> twin is KEPT (it may hold mirrored
 *                                          history) and reported as an orphan
 *   - source ledger archived (unused=1) -> not mirrored; existing twin kept
 *
 * The twin's identity is its `code` (`NN-<plain code>`); existence is the only
 * state, so there is no separate map table. Holding's `erp_acct_ledgers.code`
 * column ships as INT — it is widened to VARCHAR on first run so the prefixed
 * codes fit (mirrors the ad-hoc ALTER pattern in Migration).
 */
class LedgerSync {
    const CODE_COL_FLAG = 'erp_budget_holding_ledger_code_varchar';
    const LABELS_OPTION = 'erp_budget_entity_labels';

    /** Source codes already shaped like a twin ("NN-1234") are never re-prefixed. */
    const TWIN_CODE_RE = '/^\d{2}-/';

    /* ------------------------------------------------------------------ */
    /* Entry points                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Reconcile every mapped entity, Holding included.
     *
     * @return array<int,array>|array{multisite:bool}
     */
    public function runAll() {
        if ( ! is_multisite() ) {
            return [ 'multisite' => false ];
        }

        $out = [];
        foreach ( array_keys( EntityMap::all() ) as $blog_id ) {
            $out[ (int) $blog_id ] = $this->runBlog( (int) $blog_id );
        }

        return $out;
    }

    /**
     * Reconcile one entity's ledgers into the Holding chart.
     *
     * @return array{created:int,renamed:int,orphans:int,skipped:int,errors:int}
     */
    public function runBlog( $blog_id ) {
        $blog_id = (int) $blog_id;
        $stats   = [ 'created' => 0, 'renamed' => 0, 'orphans' => 0, 'skipped' => 0, 'errors' => 0 ];

        if ( ! is_multisite() ) {
            return $stats;
        }

        $entity_code = EntityMap::codeFor( $blog_id );
        if ( null === $entity_code ) {
            return $stats;
        }
        if ( ! $this->blogHasLedgerTable( $blog_id ) ) {
            return $stats;
        }

        // ---- 1. read the source chart ----------------------------------
        switch_to_blog( $blog_id );
        $source = $this->readSourceLedgers();
        restore_current_blog();

        // ---- 2. reconcile against the Holding chart --------------------
        switch_to_blog( EntityMap::HOLDING_BLOG_ID );
        try {
            $this->ensureCodeColumnIsString();
            $twins   = $this->loadTwins( $entity_code );          // plainCode => row
            $label   = $this->entityLabel( $blog_id, $entity_code, $twins );
            $present = [];

            foreach ( $source as $plain => $src ) {
                $present[ $plain ] = true;

                if ( isset( $twins[ $plain ] ) ) {
                    $result = $this->maybeRename( $twins[ $plain ], $label, $src );
                } else {
                    $result = $this->createTwin( $entity_code, $plain, $label, $src );
                }

                $stats[ $result ] = ( isset( $stats[ $result ] ) ? $stats[ $result ] : 0 ) + 1;
            }

            // ---- 3. twins whose source ledger is gone -----------------
            $orphans = [];
            foreach ( $twins as $plain => $row ) {
                if ( ! isset( $present[ $plain ] ) && ! $this->isArchivedInSource( $blog_id, $plain ) ) {
                    $orphans[] = $entity_code . '-' . $plain;
                }
            }
            if ( $orphans ) {
                $stats['orphans'] = count( $orphans );
                error_log(
                    '[erp-budgeting] ledger-sync: orphan twins on Holding (source ledger deleted, twin kept): '
                    . implode( ', ', $orphans )
                );
            }

            // Drop any cached ledger list so the Chart-of-Accounts screen picks
            // up the new/renamed twins (mirrors LedgerDeleteBridge).
            if ( $stats['created'] || $stats['renamed'] ) {
                $this->purgeLedgerCache();
            }
        } catch ( \Throwable $e ) {
            $stats['errors']++;
            error_log( '[erp-budgeting] ledger-sync failed for blog ' . $blog_id . ': ' . $e->getMessage() );
        } finally {
            restore_current_blog();
        }

        return $stats;
    }

    /* ------------------------------------------------------------------ */
    /* Source read (runs while switched into the entity's blog)            */
    /* ------------------------------------------------------------------ */

    /**
     * Active (non-archived) ledgers of the current blog, keyed by plain code.
     *
     * @return array<string,object> plainCode => { chart_id, name, code }
     */
    private function readSourceLedgers() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, chart_id, name, code, unused
               FROM {$wpdb->prefix}erp_acct_ledgers
              WHERE unused IS NULL OR unused = 0"
        );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $code = trim( (string) $r->code );
            if ( '' === $code || '0' === $code ) {
                continue;
            }
            // Already a consolidation twin (e.g. reading Holding's own chart) — skip.
            if ( preg_match( self::TWIN_CODE_RE, $code ) ) {
                continue;
            }
            $out[ $code ] = $r;
        }

        return $out;
    }

    /** Is a given plain code present on the source blog but archived? */
    private function isArchivedInSource( $blog_id, $plain ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $unused = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT unused FROM {$wpdb->prefix}erp_acct_ledgers WHERE code = %s ORDER BY unused DESC LIMIT 1",
                $plain
            )
        );
        restore_current_blog();

        return null !== $unused && (int) $unused === 1;
    }

    /* ------------------------------------------------------------------ */
    /* Holding-side reconcile (runs while switched into the Holding blog)  */
    /* ------------------------------------------------------------------ */

    /**
     * Existing "NN-" twins for one entity.
     *
     * @return array<string,object> plainCode => { id, code, name, chart_id }
     */
    private function loadTwins( $entity_code ) {
        global $wpdb;

        $like = $wpdb->esc_like( $entity_code . '-' ) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, code, name, chart_id FROM {$wpdb->prefix}erp_acct_ledgers WHERE code LIKE %s",
                $like
            )
        );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $plain = substr( (string) $r->code, strlen( $entity_code ) + 1 );
            if ( '' !== $plain ) {
                $out[ $plain ] = $r;
            }
        }

        return $out;
    }

    /**
     * Short entity label used as the twin-name prefix ("Water - Sales").
     *
     * Priority: the erp_budget_entity_labels network option, then the prefix of
     * an existing twin (keeps new twins consistent with the ~40 hand-built
     * ones), then the site name with a leading "Unilorin " stripped.
     */
    private function entityLabel( $blog_id, $entity_code, array $twins ) {
        $labels = get_site_option( self::LABELS_OPTION, [] );
        if ( is_array( $labels ) && ! empty( $labels[ $blog_id ] ) ) {
            return (string) $labels[ $blog_id ];
        }

        foreach ( $twins as $row ) {
            $pos = strpos( (string) $row->name, ' - ' );
            if ( false !== $pos && $pos > 0 ) {
                return substr( (string) $row->name, 0, $pos );
            }
        }

        $name = (string) get_blog_option( $blog_id, 'blogname' );
        $name = preg_replace( '/^Unilorin\s+/i', '', $name );

        return '' !== trim( $name ) ? trim( $name ) : ( 'Entity ' . $entity_code );
    }

    private function twinName( $label, $src ) {
        $src_name = trim( (string) $src->name );

        return trim( $label ) . ' - ' . ( '' !== $src_name ? $src_name : 'Ledger' );
    }

    /** @return string 'created' | 'errors' */
    private function createTwin( $entity_code, $plain, $label, $src ) {
        global $wpdb;

        $code = $entity_code . '-' . $plain;

        // Guard against a race with a concurrent single-blog sweep.
        if ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}erp_acct_ledgers WHERE code = %s",
            $code
        ) ) ) {
            return 'skipped';
        }

        $done = $wpdb->insert(
            "{$wpdb->prefix}erp_acct_ledgers",
            [
                'chart_id'    => null !== $src->chart_id ? (int) $src->chart_id : null,
                'category_id' => null,
                'name'        => $this->clip( $this->twinName( $label, $src ) ),
                'slug'        => $this->uniqueSlug( 'coa_' . $entity_code . '_' . $plain ),
                'code'        => $code,
                // Both columns must be NULL, not 0: WP ERP's ledger list queries
                // filter `WHERE unused IS NULL` (a 0 hides the twin entirely), and
                // its CoA screen shows the row-action menu only when `system IS
                // NULL` (any non-null value renders a locked "System" row).
                'unused'      => null,
                'system'      => null,
                'created_at'  => current_time( 'Y-m-d' ),
                'created_by'  => (string) get_current_user_id(),
            ]
        );

        if ( false === $done ) {
            error_log( '[erp-budgeting] ledger-sync: failed to create twin ' . $code . ': ' . $wpdb->last_error );

            return 'errors';
        }

        return 'created';
    }

    /** @return string 'renamed' | 'skipped' | 'errors' */
    private function maybeRename( $twin, $label, $src ) {
        global $wpdb;

        $want = $this->clip( $this->twinName( $label, $src ) );
        if ( (string) $twin->name === $want ) {
            return 'skipped';
        }

        $done = $wpdb->update(
            "{$wpdb->prefix}erp_acct_ledgers",
            [
                'name'       => $want,
                'updated_at' => current_time( 'Y-m-d' ),
                'updated_by' => (string) get_current_user_id(),
            ],
            [ 'id' => (int) $twin->id ]
        );

        if ( false === $done ) {
            error_log( '[erp-budgeting] ledger-sync: failed to rename twin ' . $twin->code . ': ' . $wpdb->last_error );

            return 'errors';
        }

        return 'renamed';
    }

    /* ------------------------------------------------------------------ */
    /* Schema guard                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * WP ERP ships erp_acct_ledgers.code as INT(11); the prefixed twin codes
     * ("09-1341") need VARCHAR. Widen it once on the Holding site. Widening
     * INT -> VARCHAR is lossless and WP ERP's own ledger code handling keeps
     * working (numeric strings insert fine; reports group by ledger_id).
     */
    private function ensureCodeColumnIsString() {
        global $wpdb;

        if ( get_option( self::CODE_COL_FLAG ) ) {
            return;
        }

        $col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}erp_acct_ledgers LIKE 'code'" );
        if ( $col && 0 === stripos( (string) $col->Type, 'int' ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}erp_acct_ledgers MODIFY `code` VARCHAR(30) DEFAULT NULL" );
            if ( $wpdb->last_error ) {
                error_log( '[erp-budgeting] ledger-sync: could not widen code column: ' . $wpdb->last_error );

                return;
            }
        }

        update_option( self::CODE_COL_FLAG, 1, false );
    }

    /* ------------------------------------------------------------------ */

    private function uniqueSlug( $base ) {
        global $wpdb;

        $base = sanitize_title( $base );
        $slug = $base;
        $n    = 2;

        while ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}erp_acct_ledgers WHERE slug = %s",
            $slug
        ) ) ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    private function clip( $text ) {
        $text = (string) $text;

        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255 ) : substr( $text, 0, 255 );
    }

    /** Drop WP ERP's cached ledger list (best effort). */
    private function purgeLedgerCache() {
        if ( function_exists( 'erp_acct_purge_cache' ) ) {
            erp_acct_purge_cache( [ 'list' => 'ledgers' ] );
        }
    }

    private function blogHasLedgerTable( $blog_id ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $t   = $wpdb->prefix . 'erp_acct_ledgers';
        $has = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
        restore_current_blog();

        return $has;
    }
}

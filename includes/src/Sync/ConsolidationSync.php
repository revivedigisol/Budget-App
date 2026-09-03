<?php
namespace Enle\ERP\Budgeting\Sync;

use Enle\ERP\Budgeting\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-way roll-up: mirror every subsite's GL postings into the Holding site
 * (blog 1) so blog 1's WP ERP books become a live consolidation of all
 * entities.
 *
 * Unit of sync = one source `trn_no` (voucher). Its rows are read straight
 * from `{blog}_erp_acct_ledger_details`, each source ledger is remapped to its
 * Holding "NN-" twin, and the rows are re-written verbatim (debit/credit/date)
 * into `{holding}_erp_acct_ledger_details` under a deterministic synthetic
 * trn_no ( source_blog * 1e6 + source_trn_no ). No journal header is created:
 * WP ERP's trial balance and this plugin's budget-vs-actual reports read
 * ledger balances as SUM(debit - credit) per ledger and do not require a
 * balanced journal — and WP ERP's own ledger_details are not self-balancing
 * per trn anyway (the AR/AP legs of invoices/bills live in separate
 * *_account_details tables and are intentionally out of scope here).
 *
 * State + idempotency live in `{holding}_erp_budget_sync_map`, keyed
 * (source_blog, source_trn_no).
 *
 * Statuses: synced | blocked (a line has no NN- ledger; whole trn is held and
 * retried every sweep) | error (write failure) | voided (source disappeared).
 */
class ConsolidationSync {
    const LOCK_TRANSIENT = 'erp_budget_consolidation_lock';

    /** Voucher types that must NOT be mirrored (they would double-count on blog 1). */
    private function skippedTypes() {
        return (array) apply_filters( 'erp_budget_sync_skipped_types', [ 'opening_balance' ] );
    }

    /* ------------------------------------------------------------------ */
    /* Entry points                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Sweep every mapped subsite.
     *
     * @return array<int|string,mixed> per-blog summary, or [ 'locked' => true ].
     */
    public function runAll() {
        if ( ! is_multisite() ) {
            return [ 'multisite' => false ];
        }

        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return [ 'locked' => true ];
        }
        set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

        $summary = [];
        try {
            foreach ( EntityMap::sourceBlogIds() as $blog_id ) {
                $summary[ $blog_id ] = $this->runBlog( $blog_id );
            }
        } finally {
            delete_transient( self::LOCK_TRANSIENT );
        }

        return $summary;
    }

    /**
     * Sync a single subsite.
     *
     * @return array{synced:int,blocked:int,voided:int,skipped:int,errors:int,unchanged:int}
     */
    public function runBlog( $blog_id ) {
        $blog_id = (int) $blog_id;
        $stats   = [ 'synced' => 0, 'blocked' => 0, 'voided' => 0, 'skipped' => 0, 'errors' => 0, 'unchanged' => 0 ];

        if ( ! is_multisite() ) {
            return $stats;
        }

        $entity_code = EntityMap::codeFor( $blog_id );
        if ( null === $entity_code || EntityMap::isHolding( $blog_id ) ) {
            return $stats;
        }
        if ( ! $this->blogHasErpTables( $blog_id ) ) {
            return $stats;
        }

        $resolver = new LedgerResolver();
        $lookback = max( 0, (int) apply_filters( 'erp_budget_sync_lookback_days', 0 ) );

        // ---- 1. read everything we need FROM the source blog -------------
        switch_to_blog( $blog_id );
        $resolver->loadSourceLedgers( $blog_id );
        $transactions = $this->readSourceTransactions( $lookback );
        restore_current_blog();

        // ---- 2. reconcile against the sync map ON the Holding blog ------
        switch_to_blog( EntityMap::HOLDING_BLOG_ID );
        try {
            Migration::ensureSyncTable();
            $resolver->loadHoldingLedgers( $entity_code );
            $known = $this->loadSyncMap( $blog_id ); // [ trn_no => row ]

            foreach ( $transactions as $trn_no => $trn ) {
                $prev = isset( $known[ $trn_no ] ) ? $known[ $trn_no ] : null;
                unset( $known[ $trn_no ] ); // leftover keys = void candidates

                if ( in_array( $trn['type'], $this->skippedTypes(), true )
                    || apply_filters( 'erp_budget_sync_skip_transaction', false, $trn, $blog_id ) ) {
                    $stats['skipped']++;
                    continue;
                }

                $hash = $this->hashLines( $trn );
                if ( $prev && 'synced' === $prev['status'] && $prev['source_hash'] === $hash ) {
                    $stats['unchanged']++;
                    continue;
                }

                $result = $this->syncTransaction( $blog_id, $entity_code, $trn_no, $trn, $hash, $prev, $resolver );
                $stats[ $result ] = ( isset( $stats[ $result ] ) ? $stats[ $result ] : 0 ) + 1;
            }

            // ---- 3. rows still in $known vanished from source => void ---
            // Only safe on a full scan; a lookback window can't tell "deleted"
            // from "outside the window".
            foreach ( ( 0 === $lookback ? $known : [] ) as $trn_no => $row ) {
                if ( 'voided' === $row['status'] ) {
                    continue;
                }
                $this->deleteMirrored( $this->syntheticTrnNo( $blog_id, $trn_no ) );
                $this->writeSyncMap( $blog_id, $trn_no, [
                    'status'            => 'voided',
                    'target_voucher_no' => null,
                    'message'           => 'Source transaction no longer present.',
                    'synced_at'         => current_time( 'mysql' ),
                ] );
                $stats['voided']++;
            }
        } finally {
            restore_current_blog();
        }

        return $stats;
    }

    /* ------------------------------------------------------------------ */
    /* Source read (runs while switched into the subsite)                  */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{type:string,trn_date:string,lines:array<int,array>}>
     */
    private function readSourceTransactions( $lookback = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $where = '';
        if ( $lookback > 0 ) {
            $where = $wpdb->prepare( 'WHERE d.trn_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)', $lookback );
        }

        $rows = $wpdb->get_results(
            "SELECT d.id, d.trn_no, d.ledger_id, d.particulars, d.debit, d.credit, d.trn_date,
                    d.created_by, v.type AS voucher_type
               FROM {$p}erp_acct_ledger_details d
               LEFT JOIN {$p}erp_acct_voucher_no v ON v.id = d.trn_no
               {$where}
              ORDER BY d.trn_no, d.id",
            ARRAY_A
        );

        $trns = [];
        foreach ( (array) $rows as $r ) {
            $no = (int) $r['trn_no'];
            if ( $no <= 0 ) {
                continue;
            }

            $debit  = round( (float) $r['debit'], 2 );
            $credit = round( (float) $r['credit'], 2 );
            if ( 0.0 === $debit && 0.0 === $credit ) {
                continue;
            }

            if ( ! isset( $trns[ $no ] ) ) {
                $trns[ $no ] = [
                    'type'     => $r['voucher_type'] ? (string) $r['voucher_type'] : 'unknown',
                    'trn_date' => (string) $r['trn_date'],
                    'lines'    => [],
                ];
            }

            $trns[ $no ]['lines'][] = [
                'source_ledger_id' => (int) $r['ledger_id'],
                'particulars'      => (string) $r['particulars'],
                'debit'            => $debit,
                'credit'           => $credit,
                'created_by'       => (int) $r['created_by'],
            ];
        }

        return $trns;
    }

    private function hashLines( array $trn ) {
        $parts = [];
        foreach ( $trn['lines'] as $l ) {
            $parts[] = $l['source_ledger_id'] . ':' . $l['debit'] . ':' . $l['credit'] . ':' . $l['particulars'];
        }
        sort( $parts );

        return sha1( $trn['trn_date'] . '|' . implode( '|', $parts ) );
    }

    /* ------------------------------------------------------------------ */
    /* Per-transaction sync (runs while switched into the Holding blog)    */
    /* ------------------------------------------------------------------ */

    /**
     * @return string one of: synced | blocked | errors
     */
    private function syncTransaction( $blog_id, $entity_code, $trn_no, array $trn, $hash, $prev, LedgerResolver $resolver ) {
        $mapped      = [];
        $missing     = [];
        $total_debit = 0.0;

        foreach ( $trn['lines'] as $line ) {
            $holding_ledger_id = $resolver->resolve( $blog_id, $entity_code, $line['source_ledger_id'] );

            if ( null === $holding_ledger_id ) {
                $code      = $resolver->sourceCode( $blog_id, $line['source_ledger_id'] );
                $missing[] = ( null !== $code )
                    ? ( $entity_code . '-' . $code )
                    : ( 'ledger#' . $line['source_ledger_id'] );
                continue;
            }

            $mapped[] = [
                'ledger_id'   => $holding_ledger_id,
                'particulars' => $this->tag( $entity_code, $trn_no ) . ' ' . $line['particulars'],
                'debit'       => $line['debit'],
                'credit'      => $line['credit'],
            ];
            $total_debit += $line['debit'];
        }

        $synthetic_trn = $this->syntheticTrnNo( $blog_id, $trn_no );

        $base = [
            'source_type'       => $trn['type'],
            'entity_code'       => $entity_code,
            'line_count'        => count( $trn['lines'] ),
            'amount'            => round( $total_debit, 2 ),
            'source_hash'       => $hash,
            'trn_date'          => $trn['trn_date'] ? $trn['trn_date'] : null,
            'target_voucher_no' => $synthetic_trn,
        ];

        if ( $missing ) {
            // Whole transaction is held back — no partial mirror. Retried next sweep.
            $this->deleteMirrored( $synthetic_trn );
            $missing = array_values( array_unique( $missing ) );
            $this->writeSyncMap( $blog_id, $trn_no, $base + [
                'status'        => 'blocked',
                'missing_codes' => substr( implode( ',', $missing ), 0, 255 ),
                'message'       => 'No Holding ledger for: ' . implode( ', ', $missing ),
            ] );

            return 'blocked';
        }

        $ok = $this->mirrorLedgerDetails( $synthetic_trn, $trn, $mapped );

        if ( ! $ok ) {
            $this->writeSyncMap( $blog_id, $trn_no, $base + [
                'status'  => 'error',
                'message' => 'Failed to write mirrored ledger rows (see PHP error log).',
            ] );

            return 'errors';
        }

        $this->writeSyncMap( $blog_id, $trn_no, $base + [
            'status'        => 'synced',
            'line_count'    => count( $mapped ),
            'missing_codes' => null,
            'message'       => null,
            'synced_at'     => current_time( 'mysql' ),
        ] );

        return 'synced';
    }

    /** Deterministic, collision-free trn_no for a mirrored source voucher. */
    private function syntheticTrnNo( $blog_id, $source_trn_no ) {
        return ( (int) $blog_id * 1000000 ) + (int) $source_trn_no;
    }

    private function tag( $entity_code, $trn_no ) {
        return sprintf( '[%s#%d]', $entity_code, (int) $trn_no );
    }

    /* ------------------------------------------------------------------ */
    /* Holding-side writes (run while switched into the Holding blog)      */
    /* ------------------------------------------------------------------ */

    /**
     * Replace the mirrored GL rows for one source voucher. Idempotent:
     * deletes any prior rows under the synthetic trn_no, then re-inserts.
     *
     * @return bool
     */
    private function mirrorLedgerDetails( $synthetic_trn, array $trn, array $lines ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $date = $trn['trn_date'] ? $trn['trn_date'] : current_time( 'Y-m-d' );
        $user = get_current_user_id();

        $wpdb->query( 'START TRANSACTION' );

        try {
            $wpdb->delete( "{$p}erp_acct_ledger_details", [ 'trn_no' => (int) $synthetic_trn ], [ '%d' ] );

            foreach ( $lines as $l ) {
                $done = $wpdb->insert( "{$p}erp_acct_ledger_details", [
                    'ledger_id'   => (int) $l['ledger_id'],
                    'trn_no'      => (int) $synthetic_trn,
                    'particulars' => $this->clip( $l['particulars'] ),
                    'debit'       => $l['debit'],
                    'credit'      => $l['credit'],
                    'trn_date'    => $date,
                    'created_at'  => $date,
                    'created_by'  => $user,
                ] );

                if ( false === $done ) {
                    throw new \RuntimeException( 'ledger_details insert failed: ' . $wpdb->last_error );
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( '[erp-budgeting] consolidation mirror failed: ' . $e->getMessage() );

            return false;
        }

        if ( function_exists( 'erp_acct_purge_cache' ) ) {
            erp_acct_purge_cache( [ 'list' => 'journals' ] );
        }

        return true;
    }

    private function deleteMirrored( $synthetic_trn ) {
        global $wpdb;

        $wpdb->delete(
            "{$wpdb->prefix}erp_acct_ledger_details",
            [ 'trn_no' => (int) $synthetic_trn ],
            [ '%d' ]
        );

        if ( function_exists( 'erp_acct_purge_cache' ) ) {
            erp_acct_purge_cache( [ 'list' => 'journals' ] );
        }
    }

    private function clip( $text ) {
        $text = (string) $text;

        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255 ) : substr( $text, 0, 255 );
    }

    /* ------------------------------------------------------------------ */
    /* Sync-map access (runs while switched into the Holding blog)         */
    /* ------------------------------------------------------------------ */

    private function loadSyncMap( $blog_id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'erp_budget_sync_map';

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$t} WHERE source_blog = %d", (int) $blog_id ),
            ARRAY_A
        );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r['source_trn_no'] ] = $r;
        }

        return $out;
    }

    private function writeSyncMap( $blog_id, $trn_no, array $fields ) {
        global $wpdb;
        $t = $wpdb->prefix . 'erp_budget_sync_map';

        $fields['updated_at'] = current_time( 'mysql' );

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE source_blog = %d AND source_trn_no = %d",
            (int) $blog_id,
            (int) $trn_no
        ) );

        if ( $id ) {
            $wpdb->update( $t, $fields, [ 'id' => (int) $id ] );
        } else {
            $wpdb->insert( $t, $fields + [
                'source_blog'   => (int) $blog_id,
                'source_trn_no' => (int) $trn_no,
                'created_at'    => current_time( 'mysql' ),
            ] );
        }
    }

    /* ------------------------------------------------------------------ */

    private function blogHasErpTables( $blog_id ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $t   = $wpdb->prefix . 'erp_acct_ledger_details';
        $has = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
        restore_current_blog();

        return $has;
    }
}

<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Translates a subsite ledger into the matching Holding ("NN-") ledger.
 *
 * Match rule:  Holding.code === entityCode( sourceBlog ) . '-' . source.code
 *
 * There is deliberately NO fallback / suspense account. An unresolved code
 * makes the whole transaction "blocked" (see ConsolidationSync) so it can be
 * fixed on the Holding chart and retried, rather than silently misfiled.
 */
class LedgerResolver {
    /** @var array<string,array<string,int>> entityCode => [ plainCode => holdingLedgerId ] */
    private $holdingByEntity = [];

    /** @var array<int,array<int,string>> blogId => [ sourceLedgerId => plainCode ] */
    private $sourceCodes = [];

    /**
     * Prime the source-ledger (id => code) table for a blog.
     * MUST be called while switched into that blog.
     */
    public function loadSourceLedgers( $blog_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT id, code FROM {$wpdb->prefix}erp_acct_ledgers", ARRAY_A );

        $map = [];
        foreach ( (array) $rows as $r ) {
            $map[ (int) $r['id'] ] = (string) $r['code'];
        }

        $this->sourceCodes[ (int) $blog_id ] = $map;
    }

    /**
     * Prime the Holding (code => id) table for one entity.
     * MUST be called while switched into the Holding blog.
     */
    public function loadHoldingLedgers( $entity_code ) {
        global $wpdb;

        $like = $wpdb->esc_like( $entity_code . '-' ) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, code FROM {$wpdb->prefix}erp_acct_ledgers WHERE code LIKE %s",
                $like
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( (array) $rows as $r ) {
            $plain = substr( (string) $r['code'], strlen( $entity_code ) + 1 );
            if ( '' !== $plain ) {
                $map[ $plain ] = (int) $r['id'];
            }
        }

        $this->holdingByEntity[ $entity_code ] = $map;
    }

    /** Plain code of a source ledger (no entity prefix), or null. */
    public function sourceCode( $blog_id, $source_ledger_id ) {
        return isset( $this->sourceCodes[ (int) $blog_id ][ (int) $source_ledger_id ] )
            ? $this->sourceCodes[ (int) $blog_id ][ (int) $source_ledger_id ]
            : null;
    }

    /**
     * @return int|null Holding ledger id, or null when there is no NN- twin.
     */
    public function resolve( $blog_id, $entity_code, $source_ledger_id ) {
        $code = $this->sourceCode( $blog_id, $source_ledger_id );
        if ( null === $code || '' === $code ) {
            return null;
        }

        return isset( $this->holdingByEntity[ $entity_code ][ $code ] )
            ? $this->holdingByEntity[ $entity_code ][ $code ]
            : null;
    }
}

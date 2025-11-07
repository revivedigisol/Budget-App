<?php
namespace Enle\ERP\Budgeting\Listener;

use Enle\ERP\Budgeting\Repository\BudgetRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TransactionListener {
    private $repo;

    public function __construct( BudgetRepository $repo = null ) {
        $this->repo = $repo ?: new BudgetRepository();
        add_action( 'erp_ac_accounting_transaction_saved', [ $this, 'onTransactionSaved' ], 10, 1 );
    }

    public function onTransactionSaved( $transaction ) {
        if ( empty( $transaction ) ) {
            return;
        }

        $trx_id = is_array( $transaction ) ? ( isset( $transaction['id'] ) ? $transaction['id'] : null ) : ( isset( $transaction->id ) ? $transaction->id : null );
        $date = is_array( $transaction ) ? ( isset( $transaction['date'] ) ? $transaction['date'] : null ) : ( isset( $transaction->date ) ? $transaction->date : null );

        if ( empty( $date ) ) {
            $date = current_time( 'mysql' );
        }

        $lines = is_array( $transaction ) ? ( isset( $transaction['lines'] ) ? $transaction['lines'] : [] ) : ( isset( $transaction->lines ) ? $transaction->lines : [] );

        foreach ( $lines as $line ) {
            $account_id = is_array( $line ) ? $line['account_id'] : ( isset( $line->account_id ) ? $line->account_id : null );
            $amount = is_array( $line ) ? $line['amount'] : ( isset( $line->amount ) ? $line->amount : 0 );

            if ( empty( $account_id ) ) {
                continue;
            }

            $this->repo->addLog( [
                'transaction_id' => $trx_id,
                'account_id' => $account_id,
                'amount' => $amount,
                'transaction_date' => date( 'Y-m-d', strtotime( $date ) ),
            ] );
        }
    }
}

<?php
namespace Enle\ERP\Budgeting\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BudgetCalculator {
    public static function calculateVariance( $actual, $budgeted ) : array {
        $actual = (float) $actual;
        $budgeted = (float) $budgeted;

        $variance = $actual - $budgeted;

        if ( abs( $budgeted ) < 0.000001 ) {
            $variance_pct = $budgeted === 0.0 ? null : ( $actual / $budgeted ) * 100.0;
        } else {
            $variance_pct = ( $actual / $budgeted ) * 100.0;
        }

        return [
            'variance' => $variance,
            'variance_pct' => $variance_pct,
        ];
    }

    public static function favorability( $account_type, $actual, $budgeted ) : string {
        $variance = $actual - $budgeted;

        if ( abs( $variance ) < 0.000001 ) {
            return 'neutral';
        }

        if ( $account_type === 'expense' ) {
            return $variance > 0 ? 'unfavorable' : 'favorable';
        }

        return $variance < 0 ? 'unfavorable' : 'favorable';
    }
}

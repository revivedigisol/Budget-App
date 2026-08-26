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

        // Variance % = (Variance ÷ Budget) × 100. Null when there's no budget to
        // compare against, since the percentage is meaningless (and undefined) then.
        $variance_pct = abs( $budgeted ) < 0.000001 ? null : ( $variance / $budgeted ) * 100.0;

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

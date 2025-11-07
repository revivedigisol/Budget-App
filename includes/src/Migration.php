<?php
namespace Enle\ERP\Budgeting;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Migration {
    public static function createTables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $tables = [];

        $tables[] = "CREATE TABLE {$prefix}erp_budgets (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  title VARCHAR(191) NOT NULL,\n" .
            "  description TEXT,\n" .
            "  entity_type VARCHAR(32) DEFAULT 'global',\n" .
            "  entity_id BIGINT(20) DEFAULT NULL,\n" .
            "  currency VARCHAR(12) DEFAULT 'NGN',\n" .
            "  start_date DATE NOT NULL,\n" .
            "  end_date DATE NOT NULL,\n" .
            "  created_by BIGINT(20) DEFAULT NULL,\n" .
            "  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            "  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
            "  PRIMARY KEY (id)\n" .
            ") $charset_collate";

        $tables[] = "CREATE TABLE {$prefix}erp_budget_lines (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  budget_id BIGINT(20) NOT NULL,\n" .
            "  account_id BIGINT(20) NOT NULL,\n" .
            "  period_type VARCHAR(16) DEFAULT 'monthly',\n" .
            "  period_key VARCHAR(64) DEFAULT '',\n" .
            "  amount DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  notes TEXT,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY budget_id (budget_id),\n" .
            "  KEY account_id (account_id)\n" .
            ") $charset_collate";

        $tables[] = "CREATE TABLE {$prefix}erp_budget_periods (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  budget_id BIGINT(20) NOT NULL,\n" .
            "  period_start DATE NOT NULL,\n" .
            "  period_end DATE NOT NULL,\n" .
            "  budgeted_amount DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  actual_amount DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY budget_id (budget_id),\n" .
            "  KEY period_start (period_start)\n" .
            ") $charset_collate";

        $tables[] = "CREATE TABLE {$prefix}erp_budget_variances (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  budget_id BIGINT(20) NOT NULL,\n" .
            "  period_start DATE NOT NULL,\n" .
            "  period_end DATE NOT NULL,\n" .
            "  actual_amount DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  budgeted_amount DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  variance DECIMAL(20,4) DEFAULT 0.0000,\n" .
            "  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY budget_id (budget_id),\n" .
            "  KEY period_start (period_start)\n" .
            ") $charset_collate";

        $tables[] = "CREATE TABLE {$prefix}erp_budget_logs (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  transaction_id BIGINT(20) DEFAULT NULL,\n" .
            "  account_id BIGINT(20) NOT NULL,\n" .
            "  amount DECIMAL(20,4) NOT NULL,\n" .
            "  transaction_date DATE NOT NULL,\n" .
            "  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY account_id (account_id),\n" .
            "  KEY transaction_date (transaction_date)\n" .
            ") $charset_collate";

        $tables[] = "CREATE TABLE {$prefix}erp_budget_alerts (\n" .
            "  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            "  budget_id BIGINT(20) NOT NULL,\n" .
            "  threshold_pct DECIMAL(5,2) DEFAULT 90.00,\n" .
            "  email_notify TINYINT(1) DEFAULT 1,\n" .
            "  roles_to_notify TEXT,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY budget_id (budget_id)\n" .
            ") $charset_collate";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        if ( ! get_option( 'erp_budget_db_version' ) ) {
            add_option( 'erp_budget_db_version', '0.1.0' );
        } else {
            update_option( 'erp_budget_db_version', '0.1.0' );
        }
    }
}

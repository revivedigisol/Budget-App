<?php
namespace Enle\ERP\Budgeting;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {
    protected static $prefix = 'Enle\\ERP\\Budgeting\\';
    protected static $base_dir = __DIR__ . '/';

    public static function register() {
        spl_autoload_register( [ __CLASS__, 'loadClass' ] );
    }

    public static function loadClass( $class ) {
        // only handle our namespace
        $prefix = self::$prefix;
        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        $relative_class = substr( $class, $len );
        $file = self::$base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}

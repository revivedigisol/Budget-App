<?php

namespace Enle\ERP\Budgeting\Admin;

class Assets {
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook) {
        // Only load on our plugin's pages
        if (!str_contains($hook, 'erp-budgeting')) {
            return;
        }

        $dist_path = ERP_BUDGETING_PATH . '/assets/dist';
        $assets_url = ERP_BUDGETING_ASSETS . '/dist';

        // Try to get assets from manifest first (Vite puts it in .vite/manifest.json)
        $manifest_path = $dist_path . '/.vite/manifest.json';
        $manifest = file_exists($manifest_path) 
            ? json_decode(file_get_contents($manifest_path), true) 
            : [];

        // Enqueue JS - try manifest first, fallback to direct file
        if (isset($manifest['src/main.tsx']['file'])) {
            $main_js = $manifest['src/main.tsx']['file'];
            $js_path = $dist_path . '/' . $main_js;
        } else {
            // Fallback to direct file search
            $js_files = glob($dist_path . '/js/main-*.js');
            $js_path = !empty($js_files) ? $js_files[0] : null;
            $main_js = $js_path ? 'js/' . basename($js_path) : null;
        }

        if ($main_js && file_exists($dist_path . '/' . $main_js)) {
            // Enqueue WordPress core scripts we depend on
            wp_enqueue_media();
            
            wp_enqueue_script(
                'erp-budgeting-admin',
                $assets_url . '/' . $main_js,
                [
                    'jquery',
                    'wp-api',
                    'wp-i18n',
                    'wp-components',
                    'wp-element',
                    'wp-data'
                ],
                filemtime($dist_path . '/' . $main_js),
                true
            );
        }

        // Enqueue CSS - try manifest first, fallback to direct file
        if (isset($manifest['src/main.tsx']['css'][0])) {
            $css_name = $manifest['src/main.tsx']['css'][0];
            $css_path = $dist_path . '/' . $css_name;
        } else {
            // Fallback to direct file search
            $css_files = glob($dist_path . '/assets/*.css');
            $css_path = !empty($css_files) ? $css_files[0] : null;
            $css_name = $css_path ? 'assets/' . basename($css_path) : null;
        }

        if ($css_name && file_exists($dist_path . '/' . $css_name)) {
            wp_enqueue_style(
                'erp-budgeting-admin',
                $assets_url . '/' . $css_name,
                [],
                filemtime($dist_path . '/' . $css_name)
            );
            // Add a small inline override to prevent other admin plugins' CSS
            // from forcing a constrained height on our React root. This keeps
            // the rules scoped and minimal while using !important where needed
            // to override aggressive admin CSS from other plugins.
            $inline = "
                /* Ensure our app root always expands to a sensible height */
                .wrap #erp-budgeting-root, #erp-budgeting-root {
                    height: auto !important;
                    min-height: calc(100vh - 140px) !important;
                    display: block !important;
                }

                /* Prevent inner panels from inheriting a small fixed height */
                .wrap #erp-budgeting-root * {
                    height: auto !important;
                }

                /* Slightly reduce min-height on small screens to avoid overflow */
                @media (max-width: 600px) {
                    .wrap #erp-budgeting-root { min-height: 0 !important; }
                }
            ";

            wp_add_inline_style( 'erp-budgeting-admin', $inline );
        }

        // Add nonce for REST API and base URL for router
        wp_localize_script('erp-budgeting-admin', 'wpApiSettings', [
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ]);

        // Get current admin page
        $page = $_GET['page'] ?? '';
        
        // Map WordPress admin pages to React routes
        $route = match($page) {
            'erp-budgeting' => '/',
            'erp-budgeting-new' => '/budget/new',
            'erp-budgeting-reports' => '/reports',
            'erp-budgeting-cashbook' => '/cashbook',
            default => '/'
        };

        // Add settings for the React app
        // Retrieve any previously saved cashbook rows so the React app can
        // initialize with the existing data.
        $saved_cashbook = get_option( 'erp_budgeting_cashbook_last', [] );

        wp_localize_script('erp-budgeting-admin', 'erpBudgetingSettings', [
            'adminUrl' => admin_url('admin.php'),
            'adminPostUrl' => admin_url('admin-post.php'),
            'currentPage' => $page,
            'initialRoute' => $route,
            'cashbookNonce' => wp_create_nonce('erp_budgeting_cashbook_save'),
            'cashbookSaved' => $saved_cashbook
        ]);

        // Set up translations
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('erp-budgeting-admin', 'erp-budgeting');
        }

        // Fix for sticky-menu class being removed on resize
        // Ensures the sticky-menu class persists even when window is resized
        wp_add_inline_script('erp-budgeting-admin', "
            (function() {
                // Ensure sticky-menu class is present and maintained
                const ensureStickyMenu = () => {
                    if (document.body && !document.body.classList.contains('sticky-menu')) {
                        document.body.classList.add('sticky-menu');
                    }
                };

                // Run immediately
                ensureStickyMenu();

                // Re-add on window resize (accounts for responsive changes)
                if (window.addEventListener) {
                    let resizeTimer;
                    window.addEventListener('resize', function() {
                        clearTimeout(resizeTimer);
                        resizeTimer = setTimeout(() => {
                            ensureStickyMenu();
                        }, 250);
                    }, false);
                }

                // Also observe if any code removes the class
                if (window.MutationObserver) {
                    const observer = new MutationObserver(() => {
                        ensureStickyMenu();
                    });
                    observer.observe(document.body, { 
                        attributes: true, 
                        attributeFilter: ['class']
                    });
                }
            })();
        ", 'before');
    }
}
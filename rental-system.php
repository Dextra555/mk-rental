<?php
/**
 * Plugin Name: Rental System (UltraSafe)
 * Description: Unified Car & Yacht rentals with safe, deferred loading (no activation fatals).
 * Version: 1.0.4
 * Author: DX Merge
 * Text Domain: rental-system
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RS_DIR', plugin_dir_path( __FILE__ ) );
define( 'RS_URL', plugin_dir_url( __FILE__ ) );
define( 'RS_VER', '1.0.4' );

// Collect boot errors globally
$GLOBALS['rs_boot_errors'] = [];

/**
 * Record a boot-time error safely.
 */
function rs_add_boot_error( $msg ) {
    if ( ! isset( $GLOBALS['rs_boot_errors'] ) || ! is_array( $GLOBALS['rs_boot_errors'] ) ) {
        $GLOBALS['rs_boot_errors'] = [];
    }
    $GLOBALS['rs_boot_errors'][] = $msg;
}

/**
 * Safe file loader with existence check.
 */
function rs_safe_require( $rel ) {
    $path = trailingslashit( RS_DIR ) . ltrim( $rel, '/' );
    if ( file_exists( $path ) ) {
        require_once $path;
        return true;
    } else {
        rs_add_boot_error( sprintf( 'Missing file: %s', esc_html( $rel ) ) );
        return false;
    }
}

/**
 * Activation: environment checks.
 */
register_activation_hook( __FILE__, function() {
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Rental System requires PHP 7.4+. Current: ' . PHP_VERSION );
    }
});

/**
 * Admin notices for boot errors.
 */
add_action( 'admin_notices', function() {
    if ( ! empty( $GLOBALS['rs_boot_errors'] ) ) {
        echo '<div class="notice notice-error"><p><strong>Rental System</strong>: ';
        echo implode( '<br/>', array_map( 'esc_html', $GLOBALS['rs_boot_errors'] ) );
        echo '</p></div>';
    }
});

/**
 * Safe, deferred initialization after all plugins loaded.
 */
add_action( 'plugins_loaded', function() {

    $ok = true;
    // Car modules
    $ok = rs_safe_require( 'modules/car/includes/class-car-rental-cpt.php' ) && $ok;
    $ok = rs_safe_require( 'modules/car/includes/class-car-rental-meta.php' ) && $ok;
    $ok = rs_safe_require( 'modules/car/includes/class-car-rental-shortcodes.php' ) && $ok;
    $ok = rs_safe_require( 'modules/car/includes/class-car-rental-wishlist.php' ) && $ok;

    // Yacht modules
    $ok = rs_safe_require( 'modules/yacht/includes/class-yr-cpt.php' ) && $ok;
    $ok = rs_safe_require( 'modules/yacht/includes/class-yr-meta.php' ) && $ok;
    $ok = rs_safe_require( 'modules/yacht/includes/class-yr-shortcodes.php' ) && $ok;

    if ( ! $ok ) {
        rs_add_boot_error( 'One or more module files are missing. The plugin loaded in safe mode.' );
        return; // Don’t register hooks to avoid fatals
    }

    /**
     * Initialize CPTs, Meta, Shortcodes.
     */
    add_action( 'init', function() {
        if ( class_exists( 'CR_CPT' ) ) ( new CR_CPT )->init();
        if ( class_exists( 'CR_Meta' ) ) ( new CR_Meta )->init();
        if ( class_exists( 'CR_Shortcodes' ) ) ( new CR_Shortcodes )->init();
        if ( class_exists( 'CR_Wishlist' ) ) ( new CR_Wishlist )->init();
        if ( class_exists( 'YR_CPT' ) ) ( new YR_CPT )->init();
        if ( class_exists( 'YR_Meta' ) ) ( new YR_Meta )->init();
        if ( class_exists( 'YR_Shortcodes' ) ) ( new YR_Shortcodes )->init();
    }, 9 );

    /**
     * Front-end assets & localization
     */
    add_action( 'wp_enqueue_scripts', function() {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'rs-style', RS_URL . 'assets/css/style.css', [], RS_VER );
        wp_enqueue_script( 'rs-main', RS_URL . 'assets/js/main.js', ['jquery'], RS_VER, true );

        // Optional Yacht assets
        if ( file_exists( RS_DIR . 'assets/yacht-style.css' ) ) {
            wp_enqueue_style( 'rs-yacht', RS_URL . 'assets/yacht-style.css', [], RS_VER );
        }
        if ( file_exists( RS_DIR . 'assets/rs-yacht-bridge.js' ) ) {
            wp_enqueue_script( 'rs-yacht-bridge', RS_URL . 'assets/rs-yacht-bridge.js', ['jquery'], RS_VER, true );
        }

        // Localize both namespaces on rs-main (shared file)
        wp_localize_script( 'rs-main', 'CR_Ajax', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'cr_wishlist_nonce' ),
            'is_logged_in'  => is_user_logged_in() ? 1 : 0,
        ] );

        wp_localize_script( 'rs-main', 'YR_Ajax', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'yr_nonce' ),
            'is_logged_in'  => is_user_logged_in() ? 1 : 0,
        ] );
    }, 20 );

    /**
     * --- AJAX HANDLERS ---
     */

    // Car filtering
    add_action( 'wp_ajax_cr_filter_cars', function() {
        if ( method_exists( 'CR_Shortcodes', 'ajax_filter' ) )
            return CR_Shortcodes::ajax_filter();
        wp_send_json_error( [ 'msg' => 'CR ajax filter unavailable' ] );
    });
    add_action( 'wp_ajax_nopriv_cr_filter_cars', function() {
        if ( method_exists( 'CR_Shortcodes', 'ajax_filter' ) )
            return CR_Shortcodes::ajax_filter();
        wp_send_json_error( [ 'msg' => 'CR ajax filter unavailable' ] );
    });

    // Yacht filtering
    add_action( 'wp_ajax_yr_filter', ['YR_Shortcodes', 'ajax_filter'] );
    add_action( 'wp_ajax_nopriv_yr_filter', ['YR_Shortcodes', 'ajax_filter'] );

    // Yacht "Load More" button
    add_action( 'wp_ajax_yr_load_more', 'rental_system_yacht_load_more' );
    add_action( 'wp_ajax_nopriv_yr_load_more', 'rental_system_yacht_load_more' );

    function rental_system_yacht_load_more() {
        check_ajax_referer( 'yr_nonce', 'nonce' );

        $paged = isset( $_POST['paged'] ) ? max( 1, intval( $_POST['paged'] ) ) : 1;
        $per   = isset( $_POST['per'] ) ? intval( $_POST['per'] ) : 9;

        $args = [
            'post_type'      => 'yacht_rental',
            'posts_per_page' => $per,
            'paged'          => $paged,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $q = new WP_Query( $args );

        ob_start();
        if ( $q->have_posts() ) {
            while ( $q->have_posts() ) {
                $q->the_post();
                echo YR_Shortcodes::render_card( get_the_ID() );
            }
        }

        $html = ob_get_clean();
        wp_send_json_success( [
            'html'  => $html,
            'max'   => $q->max_num_pages,
            'found' => $q->found_posts,
        ] );

        wp_reset_postdata();
    }
}, 5 );

/**
 * Flush rewrite rules on deactivation.
 */
register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules( false );
});

function enqueue_slick_carousel_styles() {
    // Enqueue Slick CSS styles
    wp_enqueue_style( 'slick-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css' );
    wp_enqueue_style( 'slick-theme-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css' );
}
add_action( 'wp_enqueue_scripts', 'enqueue_slick_carousel_styles' );
function enqueue_slick_carousel_scripts() {
    // Enqueue Slick JS
    wp_enqueue_script( 'slick-js', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js', array('jquery'), null, true );
}
add_action( 'wp_enqueue_scripts', 'enqueue_slick_carousel_scripts' );



/* Plugin injected modal for login/wishlist fallback and yacht wishlist init */
add_action('wp_footer', function() {
    if ( is_admin() ) return;
    ?>
    <div id="rs-auth-modal" class="cr-auth-modal" style="display:none;">
        <div class="cr-auth-modal-inner" role="dialog" aria-modal="true" aria-hidden="true">
            <button class="cr-auth-close" aria-label="<?php esc_attr_e('Close','rental-system'); ?>">×</button>
            <div class="cr-auth-content">
                <h2><?php esc_html_e('Please log in to add to wishlist','rental-system'); ?></h2>
                <p><?php esc_html_e('You need to be logged in to use the wishlist.','rental-system'); ?></p>
                <a class="cr-login-redirect" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e('Log in','rental-system'); ?></a>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var modal = document.getElementById('rs-auth-modal');
        if(!modal) return;
        document.addEventListener('click', function(e){
            if(e.target && (e.target.classList && e.target.classList.contains('cr-auth-close'))) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden','true');
            }
        });
        window.rsShowAuthModal = function(){
            modal.style.display = 'block';
            modal.setAttribute('aria-hidden','false');
        };
    })();
    </script>
    <?php
});



// ---- CAR LOAD MORE (JSON) ----
add_action( 'wp_ajax_cr_load_more', 'rs_cr_ajax_load_more' );
add_action( 'wp_ajax_nopriv_cr_load_more', 'rs_cr_ajax_load_more' );
function rs_cr_ajax_load_more(){
    if( ! isset($_POST['nonce']) || ! wp_verify_nonce( $_POST['nonce'], 'cr_wishlist_nonce' ) ){
        wp_send_json_error(['msg'=>'bad nonce']);
    }
    $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
    $per   = isset($_POST['per'])   ? max(1, intval($_POST['per']))   : 12;

    $args = array(
        'post_type'      => 'car_rental',
        'post_status'    => 'publish',
        'posts_per_page' => $per,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    $q = new WP_Query($args);
    ob_start();
    echo '<div class="cr-grid" data-page="'.intval($paged).'" data-per-page="'.intval($per).'">';
    if($q->have_posts()){
        while($q->have_posts()){ $q->the_post();
            if(class_exists('CR_Shortcodes') && method_exists('CR_Shortcodes','render_card')){
                echo CR_Shortcodes::render_card(get_the_ID());
            }else{
                echo '<article class="cr-card"><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></article>';
            }
        }
    }
    echo '</div>';
    $html = ob_get_clean();
    $has_more = ($q->max_num_pages > $paged);
    wp_reset_postdata();
    wp_send_json_success(array('html'=>$html,'has_more'=>$has_more));
}

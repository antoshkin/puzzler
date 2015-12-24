<?php
/*
Plugin Name: Puzzler
Plugin URI: http://github.com/antoshkin/puzzler
Description: Smart simple auto aggregator CSS and JS scripts for more fast load pages of site.
Version: 1.0
Author: Igor Antoshkin
Author URI: http://github.com/antoshkin/
*/

global $v_ok;
if ( $v_ok = version_compare( phpversion(),  "5.4" , ">=") ) require_once( dirname(__FILE__) .'/classes-puzzler.php' );

// -- in admin settings
if ( is_admin() ) {

    // -- add admin menu item
    add_action('admin_menu', 'puzzler_admin_add_menu');
    function puzzler_admin_add_menu(){
        add_menu_page('Puzzler', 'Puzzler', 'administrator', 'puzzler', 'puzzler_admin_show');
    }

    function puzzler_admin_show() {

        echo "<div class='wrap'>";
        echo "<h2>Puzzler</h2><hr>";

        // -- processing submit form
        if ( isset( $_REQUEST['settings'] ) ) {
            check_ajax_referer( 'puzzler_nonce' );
            $settings = array_merge( puzzler_get_default_settings() , $_REQUEST['settings'] );
            update_option( 'puzzler_settings' , $settings );
        }

        // -- get puzzler settings
        $settings = get_option( 'puzzler_settings' , puzzler_get_default_settings() );

        // -- check errors
        $errors = puzzler_is_permissions_settings();
        if ( ! empty( $errors ) ) {
            foreach ( $errors as $e ) {
                echo "<div class='error'>  <p> {$e} </p> </div>";
            }
        }

        echo "<form id='form-puzzler' method='post' onSubmit='document.getElementById(\"save_btn\").disabled=true;'>";

            echo "<input type='hidden' name='settings[HStylesLazy]' value='0' />";
            echo "<input id='hsl' type='checkbox' name='settings[HStylesLazy]' value='1' " . checked( $settings['HStylesLazy'] , true, false ) . " />";
            echo "<label for='hsl'>" . __( 'Lazy load styles in Header', 'puzzler' ) . "&nbsp;</label>";

            echo "<br>";

            echo "<input type='hidden' name='settings[HScriptsAsync]' value='0' />";
            echo "<input id='hsa' type='checkbox' name='settings[HScriptsAsync]' value='1' " . checked( $settings['HScriptsAsync'] , true, false ) . " />";
            echo "<label for='hsa'>" . __( 'Async load scripts in Header', 'puzzler' ) . "&nbsp;</label>";

            echo "<br>";

            echo "<input type='hidden' name='settings[FStylesLazy]' value='0' />";
            echo "<input id='fsl' type='checkbox' name='settings[FStylesLazy]' value='1' " . checked( $settings['FStylesLazy'] , true, false ) . " />";
            echo "<label for='fsl'>" . __( 'Lazy load styles in Footer', 'puzzler' ) . "&nbsp;</label>";

            echo "<br>";

            echo "<input type='hidden' name='settings[FScriptsAsync]' value='0' />";
            echo "<input id='fsa' type='checkbox' name='settings[FScriptsAsync]' value='1' " . checked( $settings['FScriptsAsync'] , true, false ) . " />";
            echo "<label for='fsa'>" . __( 'Async load scripts in Footer', 'puzzler' ) . "&nbsp;</label>";

            echo "<br><br>";

            wp_nonce_field( 'puzzler_nonce' );

        echo "<button id='save_btn'>" .__( 'Save' , 'puzzler' ). "</button>";

        echo "</form>";
        echo "</div>";
    }

}

// -- declare default settings for plugin
function puzzler_get_default_settings() {
    return array(
        'HStylesLazy'       => true ,
        'HScriptsAsync'     => true,
        'FStylesLazy'       => true ,
        'FScriptsAsync'     => true
    );
}

// -- on activate Puzzler plugin
register_activation_hook( __FILE__, 'puzzler_plugin_activate' );
function puzzler_plugin_activate() {
    global $v_ok;

    if ( $v_ok ) {
        $cacheDir = WP_CONTENT_DIR . '/' . PUZZLER_Trait::$cacheDir;

        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
    }

    $settings = get_option( 'puzzler_settings' , array() );
    if ( empty( $settings ) ) {
        update_option( 'puzzler_settings' , puzzler_get_default_settings() );
    }

}

// -- check permissions on frontend
function puzzler_is_permissions_front() {

    // -- we are use traits
    if ( version_compare( phpversion(),  "5.4" , "<") ) return false;

    // -- check on writable cache dir
    if ( ! is_writable( WP_CONTENT_DIR . '/' . PUZZLER_Trait::$cacheDir ) ) return false;

    return true;

}

// -- check permissions in plugin settings
function puzzler_is_permissions_settings() {
    global $v_ok;

    $errors = array();

    if ( version_compare( phpversion(),  "5.4" , "<") ) {
        $errors[] = __( 'Hey, Puzzler plugin requires PHP 5.4 or greater to run. Please, fix it problem :)' , 'puzzler');
    }

    if ( $v_ok ) {

        if ( ! is_writable( WP_CONTENT_DIR . '/' . PUZZLER_Trait::$cacheDir ) ) {
            $errors[] = sprintf(__('Please, create %s folder with 0777 permissions', 'puzzler'), WP_CONTENT_DIR . '/' . PUZZLER_Trait::$cacheDir);
        };

    }

    return $errors;

}

// -- check is login page
function is_login_page() {
    return in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) );
}

// -- run on frontend
if ( ! is_admin() && ! is_login_page() && puzzler_is_permissions_front() ) {

// Remove standard behavior
    remove_action('wp_print_footer_scripts', '_wp_footer_scripts');
    remove_action('wp_head', 'wp_print_styles', 8 );
    remove_action('wp_head', 'wp_print_head_scripts', 9 );

// Add puzzler behavior for header styles
    add_action('wp_head', 'puzzler_header_styles', 8 );

// Add puzzler behavior for header scripts
    add_action('wp_head', 'puzzler_header_scripts', 9 );

// Add puzzler behavior for footer scripts
    add_action('wp_print_footer_scripts', 'puzzler_footer_scripts');

// Off print footer scripts by default
    add_filter('print_footer_scripts', 'puzzler_off_footer_scripts');

// Off print header scripts by default
    add_filter('print_head_scripts', 'puzzler_off_header_scripts');

// Off print late styles by default
    add_filter('print_late_styles', 'puzzler_off_late_styles');

}

// -- off spec rules for footer scripts in admin
function puzzler_off_footer_scripts() {
    return false;
}

// -- off spec rules for header scripts in admin
function puzzler_off_header_scripts() {
    return false;
}

function puzzler_off_late_styles() {
    return false;
}

/**
 * -- override WP_Scripts/WP_Styles by Puzzler classes through @puzzler_class_changer()
 */
function puzzler_footer_scripts() {

    puzzler_class_changer();

    print_late_styles();
    print_footer_scripts();
}

/**
 * -- override WP_Scripts/WP_Styles by Puzzler classes through @puzzler_class_changer()
 */
function puzzler_header_styles( $handles = false ) {
    if ( '' === $handles ) { // for wp_head
        $handles = false;
    }
    /**
     * Fires before styles in the $handles queue are printed.
     *
     * @since 2.6.0
     */
    if ( ! $handles ) {
        do_action( 'wp_print_styles' );
    }

    _puzzler_scripts_maybe_doing_it_wrong( __FUNCTION__ );
    puzzler_class_changer();

    global $wp_styles;
    if ( ! ( $wp_styles instanceof WP_Styles ) ) {
        if ( ! $handles ) {
            return array(); // No need to instantiate if nothing is there.
        }
    }

    return $wp_styles->do_items( $handles );
}

/**
 * -- override WP_Scripts/WP_Styles by Puzzler classes through @puzzler_class_changer()
 */
function puzzler_header_scripts() {
    if ( ! did_action('wp_print_scripts') ) {
        do_action( 'wp_print_scripts' );
    }

    puzzler_class_changer();

    global $wp_scripts;
    if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
        return array(); // no need to run if nothing is queued
    }
    return print_head_scripts();
}

/**
 * Func for high compatibility with early WP versions
 * @param $function
 */
function _puzzler_scripts_maybe_doing_it_wrong( $function ) {
    if ( did_action( 'init' ) ) {
        return;
    }

    _doing_it_wrong( $function, sprintf(
        __( 'Scripts and styles should not be registered or enqueued until the %1$s, %2$s, or %3$s hooks.' ),
        '<code>wp_enqueue_scripts</code>',
        '<code>admin_enqueue_scripts</code>',
        '<code>login_enqueue_scripts</code>'
    ), '3.3' );
}

/**
 * Core plugin function for override WP_Scripts/WP_Styles
 * @throws Exception
 */
function puzzler_class_changer() {
    global $wp_scripts, $wp_styles;

    if ( ! empty( $wp_scripts ) ) {
        $scripts = new PUZZLER_Scripts;
        $scripts->import( $wp_scripts );
        $wp_scripts = $scripts;
    }

    if ( ! empty( $wp_styles ) ) {
        $styles = new PUZZLER_Styles;
        $styles->import( $wp_styles );
        $wp_styles = $styles;
    }
}

?>

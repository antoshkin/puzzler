<?php
/*
Plugin Name: Puzzler
Plugin URI: http://buckler.dp.ua/~~~/
Description: Simple auto combiner CSS and JS scripts for more fast load pages of site.
Version: 1.0
Author: Igor Antoshkin
Author URI: http://buckler.dp.ua
*/

// Off print footer scripts by default
add_filter('print_footer_scripts' , 'puzzler_off_footer_scripts');
function puzzler_off_footer_scripts() {
    return false;
}

// Off print header scripts by default
add_filter('print_head_scripts' , 'puzzler_off_header_scripts');
function puzzler_off_header_scripts() {
    return false;
}

// Remove standard behavior
remove_action('wp_print_footer_scripts', '_wp_footer_scripts');
remove_action('wp_head', 'wp_print_head_scripts' , 9);


// Add puzzler behavior for footer scripts
add_action('wp_print_footer_scripts' , 'puzzler_footer_scripts' );
function puzzler_footer_scripts() {

    puzzler_class_changer();

    print_late_styles();
    print_footer_scripts();
}

// Add puzzler behavior for header scripts
add_action('wp_head', 'puzzler_header_scripts' ,9);
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


function puzzler_class_changer() {
    global $wp_scripts, $wp_styles;

    $scripts = new PUZZLER_Scripts;
    $scripts->import( $wp_scripts );
    $wp_scripts = $scripts;

    $styles = new PUZZLER_Styles;
    $styles->import( $wp_styles );
    $wp_styles = $styles;

}


class PUZZLER_Scripts extends WP_Scripts {

    public $scriptNameHeader      = 'all-header.js';
    public $scriptNameFooter      = 'all-footer.js';
    public $cacheDir              = 'cache';

    private $digest               = "/** ## %s ## **/\n";
    private $mapState             = '';
    private $scriptFullPath       = '';

    public function import( $object ) {

        if ( ! $object instanceof WP_Dependencies ) {
            throw new Exception('You must import only WP_Scripts/WP_Styles object!');
        }

        $props = get_object_vars( $object );
        if ( ! is_array( $props ) ) throw new Exception('No props to import into the object!');

        foreach ( $props as $key => $value) {
            if ( property_exists( $this, $key ) ) {
                $this->$key = $value;
            }
        }

    }

    public function do_items( $handles = false, $group = false ) {

        $handles = false === $handles ? $this->queue : (array) $handles;
        $this->all_deps( $handles );

        /**
         * Set map states of scripts.
         * It for efficient detecting of change in puzzler_check_change()
         *
         */
        if ( 0 === $group ) {
            $this->puzzler_set_map_state();
        }

        // -- prepare full script name by depending of group
        $this->puzzler_prepare_script_name( $group );

        // -- processing of external scripts (not local)
        $this->puzzler_process_external( $group );

        // -- print all extra data of group
        $this->puzzler_print_extra( $group );

        // -- check changes of scripts and combine
        if ( $this->puzzler_check_change( $group ) ) {
            $this->puzzler_combine( $group );
        }

        if ( in_array( $group, $this->groups ) ) {
            $this->puzzler_print_script_tag();
        }

        return $this->done;
    }

    protected function puzzler_prepare_script_name( $group ) {

        $this->scriptFullPath = WP_CONTENT_DIR . '/' . $this->cacheDir . '/';
        $this->scriptFullPath .= ( 0 === $group ) ? $this->scriptNameHeader : $this->scriptNameFooter;

    }

    /**
     * Processing external script handles.
     * Probably handle is external..e.g. from http://another-resource.com/js/external.js...
     *
     * @param $group (0 - header , 1 - footer)
     */
    protected function puzzler_process_external( $group ) {

        foreach( $this->to_do as $key => $handle ) {
            if ( ! in_array($handle, $this->done, true) && isset($this->registered[$handle]) ) {

                // -- processing items only by current group
                if ( $this->groups[$handle] !== $group ) {
                    continue;
                }

                if ( false === $group && in_array($handle, $this->in_footer, true) )
                    $this->in_footer = array_diff( $this->in_footer, (array) $handle );

                // -- processing items without source
                if ( ! $this->registered[$handle]->src ) {
                    $this->done[] = $handle;
                    unset( $this->to_do[$key] );
                    continue;
                }

                // -- do behavior by default
                if ( ! $this->puzzler_get_src_local( $this->registered[$handle]->src ) ) {
                    if ( $this->do_item( $handle, $group ) ) {
                        $this->done[] = $handle;
                        unset( $this->to_do[$key] );
                    }
                }
            }
        }

    }

    protected function puzzler_print_extra ( $group ) {

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group  ) {
                continue;
            }

            $obj = $this->registered[$handle];
            $cond_before = $cond_after = '';

            $conditional = isset( $obj->extra['conditional'] ) ? $obj->extra['conditional'] : false;

            if ( $conditional ) {
                $cond_before = "<!--[if {$conditional}]>\n";
                $cond_after = "<![endif]-->\n";
            }

            $has_conditional_data = $conditional && $this->get_data( $handle, 'data' );

            if ( $has_conditional_data ) {
                echo $cond_before;
            }

            $this->print_extra_script( $handle );

            if ( $has_conditional_data ) {
                echo $cond_after;
            }

        }

    }

    protected function puzzler_get_src_local ( $src ) {

        $src = str_replace( "\\" , "/" , $src );

        $src_root_wp = ABSPATH . $src;
        $src_content = WP_CONTENT_DIR . preg_replace( '/^.*wp-content/i', '', $src );

        if ( file_exists( $src_content ) ) {
            return $src_content;
        }

        if ( file_exists( $src_root_wp ) ) {
            return $src_root_wp;
        }

        return false;

    }


    protected function puzzler_combine( $group ) {

        $this->concat = "/** Combined by WP Puzzler plugin at " . current_time( 'mysql' ) . " **/\n";
        $this->concat .= $this->mapState;

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            $obj = $this->registered[$handle];
            $src = $obj->src;

            $this->concat .= "\n" . file_get_contents( $this->puzzler_get_src_local( $src ) ) . "\n";

            $this->done[] = $handle;
            unset( $this->to_do[$key] );
        }

        if ( ! file_exists( dirname( $this->scriptFullPath ) ) && ! $this->puzzler_create_cache_dir() ) throw new Exception('Please, create folder ' . dirname( $this->scriptFullPath ) . ' with 0777 file mode (Permissions problem)');

        file_put_contents( $this->scriptFullPath, $this->concat );

    }

    protected function puzzler_set_map_state() {

        $hash = 'NONE';

        if ( ! empty( $this->to_do ) ) {
            $data = array();

            foreach( $this->to_do as $key => $handle ) {
                $data[$handle] = array( 'group' => $this->groups[$handle], 'src' => $this->registered[$handle]->src );
            }
            $hash = md5( serialize( $data ) );
        }

        $this->mapState = sprintf( $this->digest , $hash );

    }

    protected function puzzler_check_change( $group ) {

        if ( empty ( $this->to_do ) ) return false;

        // -- check change by time
        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            if ( filemtime( $this->puzzler_get_src_local( $this->registered[$handle]->src ) ) > (int)@filemtime( $this->scriptFullPath ) ) {
                return true;
            }

        }

        // -- check change by map state
        $script = @fopen( $this->scriptFullPath , "r");
        $line = '';
        if ( $script ) {
            for( $i=0; $i < 2; $i++) {
                $line = fgets( $script );
            }
            fclose( $script );
        }

        list( $new_map ) = sscanf( $this->mapState , $this->digest );
        list( $old_map ) = sscanf( $line , $this->digest );


        return ( $new_map !== $old_map ) ? true : false;

    }

    protected function puzzler_create_cache_dir() {
        return mkdir( WP_CONTENT_DIR . '/' . $this->cacheDir , 0777 , true);
    }

    protected function puzzler_print_script_tag( $ver = null ) {

        $ver = ( empty ( $ver ) ) ? md5_file( $this->scriptFullPath ) : $ver;
        $src_half = strstr($this->scriptFullPath, 'wp-content');

        $src = $this->base_url .'/'. $src_half;

        echo "<script type='text/javascript' src='$src?$ver'></script>\n";
    }

}

class PUZZLER_Styles extends WP_Styles {

    public $ololo;

    public function import( $object )
    {
        $vars = is_object( $object ) ? get_object_vars( $object ) : $object;
        if ( ! is_array( $vars ) ) throw Exception('no props to import into the object!');
        foreach ( $vars as $key => $value) {
            $this->$key = $value;
        }
    }

}


?>

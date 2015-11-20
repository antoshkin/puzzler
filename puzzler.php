<?php
/*
Plugin Name: Puzzler
Plugin URI: http://buckler.dp.ua/~~~/
Description: Simple auto combiner CSS and JS scripts for more fast load pages of site.
Version: 1.0
Author: Igor Antoshkin
Author URI: http://buckler.dp.ua
*/

if ( ! is_admin() && puzzler_is_permissions() ) {

// Off print footer scripts by default
    add_filter('print_footer_scripts', 'puzzler_off_footer_scripts');

// Off print header scripts by default
    add_filter('print_head_scripts', 'puzzler_off_header_scripts');

// Off print late styles by default
    add_filter('print_late_styles', 'puzzler_off_late_styles');

// Remove standard behavior
    remove_action('wp_print_footer_scripts', '_wp_footer_scripts');
    remove_action('wp_head', 'wp_print_styles', 8);
    remove_action('wp_head', 'wp_print_head_scripts', 9);

// Add puzzler behavior for header styles
    add_action('wp_head', 'puzzler_header_styles', 8);

// Add puzzler behavior for header scripts
    add_action('wp_head', 'puzzler_header_scripts', 9);

// Add puzzler behavior for footer scripts
    add_action('wp_print_footer_scripts', 'puzzler_footer_scripts');

}

function puzzler_is_permissions() {
    //TODO: check create cache folder and files ( in after_activate plugin )
    // Only PHP 5.4 (traits)

    /*
     *
     *     protected function puzzler_create_cache_dir() {
        return mkdir( WP_CONTENT_DIR . '/' . $this->cacheDir , 0777 , true);
    }
     * is_writable() ?
     *
     * */

    return true;
}



function puzzler_off_footer_scripts() {
    return false;
}

function puzzler_off_header_scripts() {
    return false;
}

function puzzler_off_late_styles() {
    return false;
}

function puzzler_footer_scripts() {

    puzzler_class_changer();

    print_late_styles();
    print_footer_scripts();
}

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

    _wp_scripts_maybe_doing_it_wrong( __FUNCTION__ );

    puzzler_class_changer();

    global $wp_styles;
    if ( ! ( $wp_styles instanceof WP_Styles ) ) {
        if ( ! $handles ) {
            return array(); // No need to instantiate if nothing is there.
        }
    }

    return wp_styles()->do_items( $handles );
}

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

trait PUZZLER_Trait {

    public $cacheDir            = 'cache';

    private $fileFullPath       = '';

    private $_mapStateTemplate  = "/** ## %s ## **/\n";
    private $_mapStateDigest    = '';

    public function import( $object ) {

        if ( ! $object instanceof WP_Dependencies ) {
            throw new Exception('You must import only WP_Scripts/WP_Styles object!');
        }

        $props = get_object_vars( $object );
        if ( empty( $props ) ) throw new Exception( 'The ' . get_class( $object ) . ' object is empty!');

        foreach ( $props as $key => $value) {
            if ( property_exists( $this, $key ) ) {
                $this->$key = $value;
            }
        }

    }

    public function do_items( $handles = false, $group = false ) {

        $group = (int)$group;
        $handles = false === $handles ? $this->queue : (array) $handles;
        $this->all_deps( $handles );

        /**
         * Convert late styles in fair footer group ( By default WP consider it as head group )
         * and
         * Print footer styles by default ( without combining )
         */
        if ( $this instanceof WP_Styles ) {
            $this->puzzler_styles_late2foot( $group );
            if ( $this->puzzler_styles_foot_do_default( $group ) ) return $this->done;
        }

        /**
         * Set map states of handles.
         * It for efficient detecting of changes in puzzler_check_change()
         *
         */
        if ( 0 === $group ) {
            $this->puzzler_set_map_state();
        }

        // -- prepare full file name by depending of group
        $this->puzzler_prepare_file_name( $group );

        // -- processing of external handles (not local)
        $this->puzzler_process_external( $group );

        // -- print all extra data of group
        $this->puzzler_print_extra( $group );

        // -- check changes of handles and combine
        if ( $this->puzzler_check_change( $group ) ) {
            $this->puzzler_combine( $group );
        }

        $this->puzzler_print_tag( $group );


        return $this->done;
    }

    protected function puzzler_set_map_state() {

        $hash = 'NONE';

        if ( ! empty( $this->to_do ) ) {
            $data = array();

            foreach( $this->to_do as $key => $handle ) {
                $data[$handle] = array(
                    'group' => $this->groups[$handle],
                    'src'   => $this->registered[$handle]->src,
                    'args'  => $this->registered[$handle]->args,
                    'extra' => $this->registered[$handle]->extra
                );
            }
            $hash = md5( serialize( $data ) );
        }

        $this->_mapStateDigest = sprintf( $this->_mapStateTemplate , $hash );

    }

    protected function puzzler_prepare_file_name( $group ) {

        $this->fileFullPath = WP_CONTENT_DIR . '/' . $this->cacheDir . '/';
        $this->fileFullPath .= ( 0 === $group ) ? $this->fileNameHeader : $this->fileNameFooter;

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

    protected function puzzler_check_change( $group ) {

        if ( empty ( $this->to_do ) ) return false;

        // -- check change by time
        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            if ( filemtime( $this->puzzler_get_src_local( $this->registered[$handle]->src ) ) > (int)@filemtime( $this->fileFullPath ) ) {
                return true;
            }

        }

        // -- check change by map state
        $script = @fopen( $this->fileFullPath , "r");
        $line = '';
        if ( $script ) {
            for( $i=0; $i < 2; $i++) {
                $line = fgets( $script );
            }
            fclose( $script );
        }

        list( $new_map ) = sscanf( $this->_mapStateDigest , $this->_mapStateTemplate );
        list( $old_map ) = sscanf( $line , $this->_mapStateTemplate );


        return ( $new_map !== $old_map ) ? true : false;

    }


    protected function puzzler_combine( $group ) {

        $this->concat = "/** Combined by WP Puzzler plugin at " . current_time( 'mysql' ) . " **/\n";
        $this->concat .= $this->_mapStateDigest;

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            $this->puzzler_add_handle_content( $handle );

            $this->done[] = $handle;
            unset( $this->to_do[$key] );
        }

        file_put_contents( $this->fileFullPath, $this->concat );

    }

    protected function puzzler_get_src_tag() {

        $ver = md5_file( $this->fileFullPath );
        $src_half = strstr($this->fileFullPath, 'wp-content');

        return $this->base_url .'/'. $src_half . '?' . $ver;

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

}

class PUZZLER_Scripts extends WP_Scripts {

    use PUZZLER_Trait;

    public $fileNameHeader      = 'all-header.js';
    public $fileNameFooter      = 'all-footer.js';

    protected function puzzler_print_extra ( $group ) {

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
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

    protected function puzzler_add_handle_content ( $handle ) {

        $obj = $this->registered[$handle];
        $src = $obj->src;

        $this->concat .= "\n" . file_get_contents( $this->puzzler_get_src_local( $src ) ) . "\n";
    }

    protected function puzzler_print_tag( $group ) {

        if ( ! in_array( $group, $this->groups ) ) {
            return;
        }

        $src = $this->puzzler_get_src_tag();
        echo "<script type='text/javascript' src='$src'></script>\n";

    }

}

class PUZZLER_Styles extends WP_Styles {

    use PUZZLER_Trait;

    public $fileNameHeader      = 'all-header.css';
    public $fileNameFooter      = 'all-footer.css';

    private $_headStyles;

    protected function puzzler_print_extra ( $group ) {

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            $obj = $this->registered[$handle];

            /**
             * Processing only styles without alternative stylesheets, titles, conditionals             *
             * and
             * with media = 'all'
             *
             */
            $media  = ( isset($obj->args) && 'all' == $obj->args ) ? true : false;
            $alt    = ( isset($obj->extra['alt']) && $obj->extra['alt'] ) ? false : true;
            $title  = ( isset($obj->extra['title']) ) ? false : true;
            $cond   = ( isset($obj->extra['conditional'] ) && $obj->extra['conditional'] ) ? false : true;

            if ( ! $media || ! $alt || ! $title || ! $cond) {
                if ( parent::do_item( $handle ) ) {

                    $this->done[] = $handle;
                    unset( $this->to_do[$key] );

                }
            }


        }

    }

    protected function puzzler_styles_late2foot ( $group ) {

        if ( 0 === $group ) {
            $this->_headStyles = $this->to_do;
        }

        if ( 1 === $group ) {
            $diff = array_diff( $this->to_do , $this->_headStyles);

            foreach( $diff as $key => $handle ) {
                $this->groups[$handle] = 1;
            }
        }

    }

    protected function puzzler_styles_foot_do_default ( $group ) {

        if ( 1 !== $group  ) {
            return false;
        }

        if ( $is_lazy_foot_styles = true ) {
            $lazy_starter = "<script>var cb=function(){for(var e=document.getElementsByTagName('lazyfootcss'),a=0;a<e.length;a++)e[a].outerHTML=e[a].outerHTML.replace(/lazyfootcss/g,'link')},raf=requestAnimationFrame||mozRequestAnimationFrame||webkitRequestAnimationFrame||msRequestAnimationFrame;raf?raf(cb):window.addEventListener('load',cb);</script>\n";
            echo $lazy_starter;
        }

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            // -- change link tag, for lazy load
            if ( $is_lazy_foot_styles ) {
                add_filter('style_loader_tag', array( $this, 'puzzler_styles_lazy_footer' ) );
            }

            if ( parent::do_item( $handle ) ) {

                $this->done[] = $handle;
                unset( $this->to_do[$key] );

            }
        }

        return true;

    }

    public function puzzler_styles_lazy_footer ( $tag ) {

        $lazy_style = str_replace('link' , 'lazyfootcss', $tag);
        return $lazy_style;

    }

    protected function puzzler_add_handle_content ( $handle ) {

        global $src_dir;

        $obj = $this->registered[$handle];
        $src = $obj->src;
        $src_local = $this->puzzler_get_src_local( $src );
        $src_dir = dirname( $src_local );

        $raw_content = file_get_contents( $src_local );

        // -- change internal stylesheet links url-based
        $half_content = preg_replace_callback('/url\s*\([\'\"]?([^)\'\"]+)[\'\"]?\)/iu', array( $this, 'puzzler_cb_internal_links_url') , $raw_content);
        // -- change internal stylesheet links src-based
        $content = preg_replace_callback('/src\s*=\s*[\'\"]([^\'\"]+)[\'\"]/iu', array( $this, 'puzzler_cb_internal_links_src') , $half_content);

        $this->concat .= "\n" . $content . "\n";

        // -- check exist inline styles
        $inline = $this->get_data( $handle, 'after' );
        if ( empty( $inline ) ) return;

        $inline_style = implode( "\n", $inline );
        $this->concat .= $inline_style;
        $this->concat .= "\n";

    }

    protected function puzzler_print_tag( $group ) {

        if ( ! in_array( $group, $this->groups ) ) {
            return;
        }

        $src = $this->puzzler_get_src_tag();
        echo "<link rel='stylesheet' href='$src' type='text/css' media='all' />\n";

    }

    private function puzzler_cb_internal_links_url( $matches ) {

        global $src_dir;

        if ( 0 === strpos( $matches[1] , 'http') || 0 === strpos( $matches[1] , 'data') ) return $matches[0];

        $dirty_path = $src_dir .'/'. $matches[1];
        preg_match('/[?#].+$/iu', $dirty_path, $params);
        $params = ( empty($params) ) ? '' : $params[0];
        $clear_path = str_replace( $params, '' , $dirty_path );

        $real_path = realpath( $clear_path );

        $replace = $this->getRelativePath( $this->fileFullPath , $real_path ) . $params;
        return "url({$replace})";

    }

    private function puzzler_cb_internal_links_src( $matches ) {

        global $src_dir;

        $dirty_path = $src_dir .'/'. $matches[1];
        preg_match('/[?#].+$/iu', $dirty_path, $params);
        $params = ( empty($params) ) ? '' : $params[0];
        $clear_path = str_replace( $params, '' , $dirty_path );

        $real_path = realpath( $clear_path );

        $replace = $this->getRelativePath( $this->fileFullPath , $real_path ) . $params;
        return "src='{$replace}'";

    }

    /**
     * This func is taken
     * from Symfony URL generator https://github.com/symfony/Routing/blob/master/Generator/UrlGenerator.php
     *
     * @param $basePath
     * @param $targetPath
     * @return string
     */
    private function getRelativePath( $basePath, $targetPath )
    {
        if ($basePath === $targetPath) {
            return '';
        }
        $sourceDirs = explode('/', isset($basePath[0]) && '/' === $basePath[0] ? substr($basePath, 1) : $basePath);
        $targetDirs = explode('/', isset($targetPath[0]) && '/' === $targetPath[0] ? substr($targetPath, 1) : $targetPath);
        array_pop($sourceDirs);
        $targetFile = array_pop($targetDirs);
        foreach ($sourceDirs as $i => $dir) {
            if (isset($targetDirs[$i]) && $dir === $targetDirs[$i]) {
                unset($sourceDirs[$i], $targetDirs[$i]);
            } else {
                break;
            }
        }
        $targetDirs[] = $targetFile;
        $path = str_repeat('../', count($sourceDirs)).implode('/', $targetDirs);

        return '' === $path || '/' === $path[0]
        || false !== ($colonPos = strpos($path, ':')) && ($colonPos < ($slashPos = strpos($path, '/')) || false === $slashPos)
            ? "./$path" : $path;
    }


}


?>

<?php

trait PUZZLER_Trait {

    public static $cacheDir     = 'cache';

    private $fileFullPath       = '';

    private $_mapStateTemplate  = "/** ## %s ## **/\n";
    private $_mapStateDigest    = '';

    /**
     * Import data from WP_Scripts/WP_Styles in Puzzler object
     * @param $object
     * @throws Exception
     */
    public function import( $object ) {

        if ( ! $object instanceof WP_Dependencies ) {
            throw new Exception( __( 'You must import only WP_Scripts/WP_Styles object!' , 'puzzler' ) );
        }

        $props = get_object_vars( $object );
        if ( empty( $props ) ) throw new Exception( 'The ' . get_class( $object ) . ' object is empty!');

        foreach ( $props as $key => $value) {
            if ( property_exists( $this, $key ) ) {
                $this->$key = $value;
            }
        }

    }

    /**
     * Override do_items of WP_Scripts/WP_Styles
     *
     * @param bool|array $handles
     * @param bool $group (0 - header , 1 - footer)
     * @return array
     */
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
            $this->puzzler_lazyload_starter( $group );
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

    /**
     * Set state (hash) for map ( order, group, src, some extra data ) of scripts/styles enqueued by wp_enqueue_,,,().
     * It for efficient auto detect changes
     */
    protected function puzzler_set_map_state() {

        $hash = 'NONE';

        if ( ! empty( $this->to_do ) ) {
            $data = array();

            foreach( $this->to_do as $key => $handle ) {
                $data[$handle] = array(
                    'group' => $this->groups[$handle],
                    'src'   => $this->registered[$handle]->src
                );

                if ( $this instanceof WP_Styles ) {
                    $data[$handle]['args']  = $this->registered[$handle]->args;
                    $data[$handle]['extra'] = $this->registered[$handle]->extra;
                }

            }
            $hash = md5( serialize( $data ) );
        }

        $this->_mapStateDigest = sprintf( $this->_mapStateTemplate , $hash );

    }

    /**
     * Preparing full path aggregate file by depending of group (header/footer)
     * @param $group (0 - header , 1 - footer)
     *
     */
    protected function puzzler_prepare_file_name( $group ) {

        $this->fileFullPath =  WP_CONTENT_DIR . '/' . static::$cacheDir . '/' ;
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

    /**
     * Checks (content handles and map states) changes within group
     * @param $group (0 - header , 1 - footer)
     * @return bool : true - changes happened, false - no.
     */
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

    /**
     * Combine handles and save aggregate file
     * @param $group (0 - header , 1 - footer)
     */
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

    /**
     * Get full src ( include version ) for script/link tags
     * @return string
     */
    protected function puzzler_get_src_tag() {

        $fix_win = str_replace("\\", "/", $this->fileFullPath);
        $ver = md5_file( $fix_win );
        $src_half = str_replace( WP_CONTENT_DIR , '', $this->fileFullPath );

        return $this->content_url . $src_half . '?' . $ver;

    }

    /**
     * Detect local exist handle by src ( from wp_enqueue...() )
     *
     * @param $src
     * @return bool|string - false for not exist | full local path
     */
    protected function puzzler_get_src_local ( $src ) {

        $src = str_replace( "\\" , "/" , $src );

        $src_root_wp = ABSPATH . $src;
        $src_other = ABSPATH . str_replace( $this->base_url, '' , $src );

        if ( file_exists( $src_other ) ) {
            return $src_other;
        }

        if ( file_exists( $src_root_wp ) ) {
            return $src_root_wp;
        }

        if ( file_exists( $src ) ) {
            return $src;
        }

        return false;

    }

}

class PUZZLER_Scripts extends WP_Scripts {

    use PUZZLER_Trait;

    public $fileNameHeader      = 'all-header.js';
    public $fileNameFooter      = 'all-footer.js';

    private $_asyncHead;
    private $_asyncFoot;


    /**
     * Set settings of async (defer) load scripts
     */
    public function __construct() {

        $settings = get_option( 'puzzler_settings' , puzzler_get_default_settings() );
        $this->_asyncHead = $settings['HScriptsAsync'];
        $this->_asyncFoot = $settings['FScriptsAsync'];

    }

    /**
     * Print some extra data before aggregate script tag depending by group
     * @param $group (0 - header , 1 - footer)
     */
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

    /**
     * Add handle content to aggregate string
     * @param $handle
     */
    protected function puzzler_add_handle_content ( $handle ) {

        $obj = $this->registered[$handle];
        $src = $obj->src;

        $this->concat .= "\n" . file_get_contents( $this->puzzler_get_src_local( $src ) ) . "\n";
    }

    /**
     * Print script tag depending by group
     * @param $group (0 - header , 1 - footer)
     */
    protected function puzzler_print_tag( $group ) {

        if ( ! in_array( $group, $this->groups ) ) {
            return;
        }

        $async = '';
        if ( ( 0 === $group && $this->_asyncHead ) || ( 1 === $group && $this->_asyncFoot ) ) {
            $async = "defer='defer'";
        }

        $src = $this->puzzler_get_src_tag();
        echo "<script {$async} type='text/javascript' src='$src'></script>\n";

    }

}

class PUZZLER_Styles extends WP_Styles {

    use PUZZLER_Trait;

    public $fileNameHeader      = 'all-header.css';
    public $fileNameFooter      = 'all-footer.css';

    private $_lazyHead;
    private $_lazyFoot;

    // -- temporarily storage head styles for separate late styles
    private $_headStyles;

    /**
     * Set settings of lazy load styles
     */
    public function __construct() {

        $settings = get_option( 'puzzler_settings' , puzzler_get_default_settings() );
        $this->_lazyHead = $settings['HStylesLazy'];
        $this->_lazyFoot = $settings['FStylesLazy'];

    }

    /**
     * Pass styles only with media='all' and without extra data, else do behavior by default.
     * For increased compatibility.
     * @param $group (0 - header , 1 - footer)
     */
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

    /**
     * Convert late styles to fair footer group. WP by default considers it as header group.
     * @param $group (0 - header , 1 - footer)
     */
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

    /**
     * For all late styles (now footer group) do default behavior with lazy load opportunity
     * @param $group (0 - header , 1 - footer)
     * @return bool
     */
    protected function puzzler_styles_foot_do_default ( $group ) {

        if ( 1 !== $group  ) {
            return false;
        }

        foreach( $this->to_do as $key => $handle ) {

            // -- processing items only by current group
            if ( $this->groups[$handle] !== $group ) {
                continue;
            }

            if ( parent::do_item( $handle ) ) {

                $this->done[] = $handle;
                unset( $this->to_do[$key] );

            }
        }

        return true;
    }

    /**
     * Insert JS starter for lazy load css
     * @param $group (0 - header , 1 - footer)
     */
    protected function puzzler_lazyload_starter( $group ) {

        $lazy_starter = "\n";

        remove_all_filters( 'style_loader_tag' );

        if ( $this->_lazyHead && 0 === $group && in_array( $group , $this->groups ) ) {
            add_filter('style_loader_tag', array( $this, 'puzzler_styles_lazy_tag' ) );
            $lazy_starter="<script>var lazyHead=function(){for(var e=document.getElementsByTagName('head')[0],a=e.getElementsByTagName('link'),t=0;t<a.length;t++)a[t].outerHTML=a[t].outerHTML.replace(/lazy/g,'href')};window.addEventListener('load',lazyHead);</script>\n";
        }

        if ( $this->_lazyFoot && 1 === $group &&  in_array( $group , $this->groups ) ) {
            add_filter('style_loader_tag', array( $this, 'puzzler_styles_lazy_tag' ) );
            $lazy_starter="<script>var lazyFoot=function(){for(var e=document.getElementsByTagName('body')[0],a=e.getElementsByTagName('link'),t=0;t<a.length;t++)a[t].outerHTML=a[t].outerHTML.replace(/lazy/g,'href')};var raf=requestAnimationFrame||mozRequestAnimationFrame||webkitRequestAnimationFrame||msRequestAnimationFrame;raf?raf(lazyFoot):window.addEventListener('load',lazyFoot);</script>\n";
        }

        echo $lazy_starter;
    }

    /**
     * Hook for replace 'href' attr in 'link' tag for lazy load styles
     * @param $tag
     * @return mixed
     */
    public function puzzler_styles_lazy_tag ( $tag ) {

        $lazy_style = str_replace( 'href' , 'lazy', $tag );
        return $lazy_style;

    }

    /**
     * Add handle content to aggregate string and fix internal stylesheet links ( url, src )
     * @param $handle
     */
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

    /**
     * Print link tag depending by group
     * @param $group (0 - header , 1 - footer)
     */
    protected function puzzler_print_tag( $group ) {

        if ( ! in_array( $group, $this->groups ) ) {
            return;
        }

        $src = $this->puzzler_get_src_tag();

        $href = 'href';
        if ( ( 0 === $group && $this->_lazyHead ) || ( 1 === $group && $this->_lazyFoot ) ) {
            $href = 'lazy';
        }

        echo "<link rel='stylesheet' {$href}='$src' type='text/css' media='all' />\n";

    }

    /**
     * Callback from @puzzler_add_handle_content() for fix internal stylesheet links ( url )
     * @param $matches
     * @return string
     */
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

    /**
     * Callback from @puzzler_add_handle_content() for fix internal stylesheet links ( src )
     * @param $matches
     * @return string
     */
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

        // -- windows paths fix
        $basePath   = str_replace( "\\" , "/" , $basePath );
        $targetPath = str_replace( "\\" , "/" , $targetPath );
        
        $pattern     = '/(\D):/iu';
        $replacement = "/$1";

        $targetPath = preg_replace($pattern, $replacement, $targetPath);
        $basePath   = preg_replace($pattern, $replacement, $basePath);
        // -- 

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
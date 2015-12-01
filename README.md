# Puzzler plugin for WP (beta)
Puzzler plugin - it smart simple and fast auto aggregator (combiner) CSS and JS scripts for Wordpress.

Require: PHP 5.4.

### Key rule 1
All scripts and styles must include ONLY 1 time and ONLY in 1 place, e.g. in wp_enqueue_scripts hook.

### Key rule 2
Styles(css) aggregation perform only for media='all'.
( without alternative stylesheets, titles, conditionals )

### Key rule 3
Avoid register/enqueue scripts/styles in conditional expressions, i.e.:
```php
add_action('wp_enqueue_scripts', 'my_enqueue_scripts');
function my_enqueue_scripts() {
  
  // -- don't do it !
  if ( is_single() || is_page() ) { 
      wp_enqueue_script('myscript');
  }
  
  // -- correct !
  wp_enqueue_script('myscript');
  
}
```

### Features
- Auto detect files change
- Autocorrect internal links in the CSS after aggregation. ( url/src )
- Async/lazy load aggregated scripts/styles

-
Author: igor.antoshkin@gmail.com

Please, you can donate me to paypal.

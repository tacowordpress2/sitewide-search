<?php
/*
 * Plugin Name: Site-wide Search
 * Description: Provides fulltext search of custom post types
 * Author: Vermilion
 * Version: 1.0
 * Plugin URI: vermilion.com
 */

require_once __DIR__ . '/SiteWideSearchInstance.php';

register_activation_hook(__FILE__, ['SiteWideSearchInstance', 'activatePlugin']);
register_deactivation_hook(__FILE__, ['SiteWideSearchInstance', 'deactivatePlugin']);

add_action('init', ['SiteWideSearchInstance', 'addRewrite']);
add_action('save_post', ['SiteWideSearchInstance', 'postModified']);

add_action('template_redirect', ['SiteWideSearchInstance', 'catchRedirect']);
add_filter('query_vars', ['SiteWideSearchInstance', 'addQueryVars']);
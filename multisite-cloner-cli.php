<?php
/**
 * Plugin Name: Multisite Cloner CLI
 * Description: WP-CLI only plugin for cloning sites within multisite WordPress
 * Version: 1.0
 * Author: Gregory Morozov
 * Author URI: https://github.com/negrusti
 */

// Check if WP-CLI is active
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

class WP_CLI_Clone_Command {

    /**
    * Clone one site to another.
    *
    * ## OPTIONS
    *
    * <source_ID>
    * : Source site ID
    *
    * <target_ID>
    * : Target site ID
    *
    * [--force-https]
    * : Force https on the target URLs
    *
    * [--skip-replace]
    * : Skip search/replace on the target URLs. Useful for cross-linked sites
    *
    * [--dry-run]
    * : Run the command without actually doing anything.
    */
    
    public function __invoke( $args, $assoc_args ) {
        
        if ( ! is_multisite() ) {
            WP_CLI::error( 'This is not a multisite installation.' );
        }
        
        if ( count( $args ) !== 2 || ! ctype_digit( $args[0] ) || ! ctype_digit( $args[1] ) ) {
            WP_CLI::error( 'Please provide two integer arguments.' );
        }        

        if ( $args[0] == $args[1] ) {
            WP_CLI::error( "Can't clone the site to itself." );
        }

        if ( $args[1] == "1" ) {
            WP_CLI::error( "Target site ID = 1 is not supported yet." );
        }
        
        global $wpdb;
        
        // tables for site ID = 1 do not have a numeric part of the prefix
        $source_prefix = ( $args[0] == "1" ) ? $wpdb->prefix : $wpdb->prefix . $args[0] . "_";
        $target_prefix = ( $args[1] == "1" ) ? $wpdb->prefix : $wpdb->prefix . $args[1] . "_";

        $source_site_details = get_blog_details($args[0]);
        $target_site_details = get_blog_details($args[1]);

        if ( isset( $assoc_args['force-https'] ) ) {
            $target_site_details->siteurl = str_replace("http:", "https:", $target_site_details->siteurl);
        }
        
        if(!$source_site_details || !$target_site_details) {
            WP_CLI::error("Site does not exist");
        }

        WP_CLI::log("Cloning tables: " . $source_site_details->siteurl . " => " . $target_site_details->siteurl);
        
        $sql = $wpdb->prepare("SHOW TABLES LIKE %s", $source_prefix . "%");
        $source_tables = $wpdb->get_results($sql, ARRAY_N);

        if (!empty($source_tables)) {
            foreach($source_tables as $source_table) {

                // Skipping global tables of a multisite
                if ( $args[0] == "1" && preg_match("/_(blogs|blog_versions|registration_log|site|sitemeta|signups|users|usermeta)$/", $source_table[0] )) continue;
                if ( $args[0] == "1" && preg_match("/^" . $source_prefix . "[0-9].*/", $source_table[0] )) continue;
                
                $destination_table = str_replace($source_prefix, $target_prefix, $source_table[0]);
                WP_CLI::log("Source table: " . $source_table[0] . " => Destination table: " . $destination_table);

                if ( isset( $assoc_args['dry-run'] ) ) continue;
                $wpdb->query( "DROP TABLE IF EXISTS $destination_table" );
                $wpdb->query( "CREATE TABLE $destination_table LIKE $source_table[0]" );
                $wpdb->query( "INSERT INTO $destination_table SELECT * FROM $source_table[0]" );
            }
        } else {
            WP_CLI::error("No tables found");
        }

        if ( isset( $assoc_args['dry-run'] ) ) WP_CLI::error("Dry run completed!");
        // Fix user roles option name
        $wpdb->query("UPDATE " . $target_prefix . "options SET option_name = '" . $target_prefix . "user_roles' WHERE option_name = '" . $source_prefix . "user_roles'");

        $wpdb->query("UPDATE " . $target_prefix . "options SET option_value = '" . $target_site_details->siteurl . "' WHERE option_name = 'home' OR option_name = 'siteurl'");
        
        if ( !isset( $assoc_args['skip-replace'] ) ) {
            WP_CLI::log("Replacing URLs in the target site tables: " . $source_site_details->siteurl . " => " . $target_site_details->siteurl);
            WP_CLI::runcommand("search-replace $source_site_details->siteurl $target_site_details->siteurl $target_prefix* --network");
        }
            
        $upload_data = wp_get_upload_dir();
        WP_CLI::log("Copying site files");
        
        self::recurseCopy($upload_data['basedir'] . (($args[0] == "1") ? "" : "/sites/" . $args[0]),
                          $upload_data['basedir'] . (($args[1] == "1") ? "" : "/sites/" . $args[1]));

        WP_CLI::runcommand("cache flush");        
        WP_CLI::success("Clone completed!");
    }
    
    function recurseCopy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    // sites directory is a special case for site ID = 1 and must be skipped
                    if ($file == 'sites') continue;
                    self::recurseCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }
}

WP_CLI::add_command( 'site clone', 'WP_CLI_Clone_Command' );

<?php

/*
    Author: Jim Westergren & Jeedo Aquino
    File: index-with-redis.php
    Updated: 2012-10-25

    This is a redis caching system for wordpress.
    see more here: www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/

    Originally written by Jim Westergren but improved by Jeedo Aquino.

    some caching mechanics are different from jim's script which is summarized below:

    - cached pages do not expire not unless explicitly deleted or reset
    - appending a ?c=y to a url deletes the entire cache of the domain, only works when you are logged in
    - appending a ?r=y to a url deletes the cache of that url
    - submitting a comment deletes the cache of that page
    - refreshing (f5) a page deletes the cache of that page
    - includes a debug mode, stats are displayed at the bottom most part after </html>

    for setup and configuration see more here:

    www.jeedo.net/lightning-fast-wordpress-with-nginx-redis/

    use this script at your own risk. i currently use this albeit a slightly modified version
    to display a redis badge whenever a cache is displayed.

    Hacked up by Nick Fuller from functional into an object.

*/
include_once( 'predis.php' );
define( 'WP_USE_THEMES', true );

$redis = new Predis\Client('');
$redis_cache = new TF_Redis_Page_Cache( false, $redis );
$redis_cache->cache();

class TF_Redis_Page_Cache {

    public function __construct( $is_cloudflare_active, $redis ) {
        $this->redis = $redis;
        $this->domain = $_SERVER['HTTP_HOST'];
        $this->url = $this->url();
        $this->domain_key = md5( $this->domain );
        $this->url_key = md5( $this->url );
        if ( $is_cloudflare_active ) $this->set_cloudflare_headers();
    }

    public function cache() {

        if ( $this->does_cache_exist_for_this_page() ) {

            echo $this->redis->hget( $this->domain_key, $this->url_key );
            exit( 0 );

        } else if ( $this->is_comment_submission() ) {

            require_once( './wp-blog-header.php' );
            $this->redis->hdel( $this->domain_key );

        } else if ( $this->is_user_logged_in() ) {

            require_once( './wp-blog-header.php' );
            if ( $this->redis->exists( $this->domain_key ) ) { $this->redis->del( $this->domain_key ); }

        } else {

            $this->cache_page();

        }

    }

    private function cache_page() {

        ob_start(); // turn on output buffering

        require('./wp-blog-header.php');

        $html = ob_get_contents();

        // clean output buffer
        ob_end_clean();
        echo $html;

        if ( ! is_404() && ! is_search() ) {
            $this->redis->hset( $this->domain_key, $this->url_key, $html) ;
        }

    }

    private function url() {

        return $this->protocol_string() . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    }

    private function set_cloudflare_headers() {

        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

    }

    private function is_comment_submission() {

        return ( isset( $_SERVER['HTTP_CACHE_CONTROL'] ) && $_SERVER['HTTP_CACHE_CONTROL'] === 'max-age=0' );

    }

    private function is_user_logged_in() {

        $cookie = var_export( $_COOKIE, true );
        return preg_match( '/wordpress_logged_in/', $cookie );

    }

    private function protocol_string() {

        if ( ! $this->is_secure() ) {
            return 'http://';
        } else {
            return 'https://';
        }

    }

    private function is_secure() {

        return isset( $_SERVER['HTTPS'] );

    }

    private function does_cache_exist_for_this_page() {

        return (
            $this->redis->hexists( $this->domain_key, $this->url_key )
            && ! $this->is_user_logged_in()
            && ! $this->is_comment_submission()
            && ! strpos( $this->url, '/feed/' )
        );

    }

}


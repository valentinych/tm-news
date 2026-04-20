<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI команды:
 *
 *   wp tm-news fetch
 *   wp tm-news cluster
 *   wp tm-news score
 *   wp tm-news publish [--dry-run]
 *   wp tm-news run     [--dry-run]
 *   wp tm-news status
 */
final class CLI {

    public function fetch(): void {
        $n = Fetcher::fetch_all();
        \WP_CLI::success( "fetched new items: {$n}" );
    }

    public function cluster(): void {
        $n = Clusterer::cluster_unassigned();
        \WP_CLI::success( "clustered items: {$n}" );
    }

    public function score(): void {
        $n = Scorer::score_all();
        \WP_CLI::success( "clusters scored: {$n}" );
    }

    public function publish( $args, $assoc ): void {
        $dry = ! empty( $assoc['dry-run'] );
        $res = Publisher::publish_top( $dry );
        \WP_CLI::log( wp_json_encode( $res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        \WP_CLI::success( sprintf( 'created=%d skipped=%d errors=%d', count( $res['created'] ), $res['skipped'], count( $res['errors'] ) ) );
    }

    public function run( $args, $assoc ): void {
        $dry = ! empty( $assoc['dry-run'] );
        $res = Plugin::instance()->run_pipeline( $dry );
        \WP_CLI::log( wp_json_encode( $res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        \WP_CLI::success( 'pipeline done' );
    }

    public function status(): void {
        global $wpdb;
        $items_t = Installer::items_table();
        $clust_t = Installer::clusters_table();
        $items   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_t}" );
        $clusters = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clust_t}" );
        $new     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clust_t} WHERE status='new'" );
        $drafted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clust_t} WHERE status='drafted'" );
        \WP_CLI::log( "items: {$items} | clusters: {$clusters} | new: {$new} | drafted: {$drafted}" );
        $sources = Sources::enabled();
        \WP_CLI::log( 'active sources: ' . count( $sources ) );
    }
}

<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Создаёт/мигрирует таблицы и раскладывает дефолтные опции.
 */
final class Installer {

    private const DB_VERSION_OPTION = 'tm_news_db_version';
    private const DB_VERSION        = '4';

    public static function items_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_news_items';
    }

    public static function clusters_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_news_clusters';
    }

    public static function activate(): void {
        self::install_schema();
        Sources::seed_defaults();
        CPT::register();
        flush_rewrite_rules();
        if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
            wp_schedule_event( time() + 120, 'hourly', Plugin::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( Plugin::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, Plugin::CRON_HOOK );
        }
    }

    public static function maybe_upgrade(): void {
        $current = get_option( self::DB_VERSION_OPTION );
        if ( $current !== self::DB_VERSION ) {
            self::install_schema();
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        }
    }

    private static function install_schema(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $items   = self::items_table();
        $clust   = self::clusters_table();

        $sql_items = "CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_key VARCHAR(64) NOT NULL,
            url VARCHAR(500) NOT NULL,
            url_hash CHAR(40) NOT NULL,
            title TEXT NOT NULL,
            title_norm TEXT NULL,
            excerpt TEXT NULL,
            lang VARCHAR(8) NOT NULL DEFAULT 'pl',
            pub_ts BIGINT UNSIGNED NOT NULL,
            fetched_ts BIGINT UNSIGNED NOT NULL,
            cluster_id BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY source_key (source_key),
            KEY pub_ts (pub_ts),
            KEY cluster_id (cluster_id)
        ) {$charset};";

        $sql_clust = "CREATE TABLE {$clust} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_seen BIGINT UNSIGNED NOT NULL,
            last_seen  BIGINT UNSIGNED NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 1,
            score FLOAT NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'new',
            canonical_item_id BIGINT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY score (score),
            KEY last_seen (last_seen)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_items );
        dbDelta( $sql_clust );

        // v2: добавить новые дефолтные источники к существующей опции.
        // Идемпотентно, пользовательские настройки не трогаем.
        $added = Sources::merge_new_defaults();
        if ( $added > 0 ) {
            Logger::info( 'sources: merged new defaults', [ 'added' => $added ] );
        }

        // v3: часть URL из v2 оказалась битой (TVN24 блокируется WAF,
        // Polskie Radio и PAP — нет публичного фида, Onet/Polsat — опечатка).
        // Чиним/удаляем только там, где пользователь их не включал.
        [ $fixed, $removed ] = Sources::cleanup_broken_v2_defaults();
        if ( $fixed || $removed ) {
            Logger::info( 'sources: cleanup v2 defaults', [ 'fixed' => $fixed, 'removed' => $removed ] );
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
    }
}

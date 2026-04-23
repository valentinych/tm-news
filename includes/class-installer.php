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
    private const DB_VERSION        = '5';

    public static function items_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_news_items';
    }

    public static function clusters_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_news_clusters';
    }

    public static function social_accounts_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_social_accounts';
    }

    public static function social_items_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_social_items';
    }

    public static function social_snapshots_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tm_social_snapshots';
    }

    public static function activate(): void {
        self::install_schema();
        Sources::seed_defaults();
        Social::seed_defaults();
        CPT::register();
        flush_rewrite_rules();
        if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
            wp_schedule_event( time() + 120, 'hourly', Plugin::CRON_HOOK );
        }
        if ( ! wp_next_scheduled( Social::CRON_HOOK ) ) {
            wp_schedule_event( time() + 180, 'tm_social_30min', Social::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        foreach ( [ Plugin::CRON_HOOK, Social::CRON_HOOK ] as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook );
            }
        }
    }

    public static function maybe_upgrade(): void {
        $current = get_option( self::DB_VERSION_OPTION );
        if ( $current !== self::DB_VERSION ) {
            self::install_schema();
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
            // CPT rewrite-правила могли поменяться между версиями (slug, archive,
            // query_var). register_activation_hook не срабатывает при rsync-деплое
            // без переактивации плагина, поэтому принудительно сбрасываем здесь.
            flush_rewrite_rules( false );
        }
    }

    private static function install_schema(): void {
        global $wpdb;

        $charset     = $wpdb->get_charset_collate();
        $items       = self::items_table();
        $clust       = self::clusters_table();
        $soc_accts   = self::social_accounts_table();
        $soc_items   = self::social_items_table();
        $soc_snaps   = self::social_snapshots_table();

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

        // --- v5: Social (YouTube/Telegram) ---
        // accounts: список отслеживаемых публичных каналов. platform+handle уникальны.
        // external_id заполняется драйвером после первого успешного resolve
        // (для YT это channelId UC…; для TG совпадает с handle).
        $sql_soc_accts = "CREATE TABLE {$soc_accts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            platform VARCHAR(32) NOT NULL,
            handle VARCHAR(190) NOT NULL,
            external_id VARCHAR(190) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            url VARCHAR(500) NOT NULL DEFAULT '',
            tags VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            last_checked_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_platform_handle (platform, handle),
            KEY idx_platform (platform),
            KEY idx_enabled (enabled)
        ) {$charset};";

        // items: пост/видео в ленте канала, последние актуальные метрики.
        $sql_soc_items = "CREATE TABLE {$soc_items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(32) NOT NULL,
            external_id VARCHAR(190) NOT NULL,
            url VARCHAR(500) NOT NULL,
            title TEXT NOT NULL,
            excerpt TEXT NULL,
            published_ts BIGINT UNSIGNED NOT NULL,
            fetched_ts BIGINT UNSIGNED NOT NULL,
            updated_ts BIGINT UNSIGNED NOT NULL,
            views_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comments_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shares_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_platform_ext (platform, external_id),
            KEY idx_account (account_id),
            KEY idx_published (published_ts),
            KEY idx_fetched (fetched_ts)
        ) {$charset};";

        // snapshots: исторические точки для расчёта velocity (Δmetric/Δt).
        $sql_soc_snaps = "CREATE TABLE {$soc_snaps} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            snapshot_ts BIGINT UNSIGNED NOT NULL,
            views_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comments_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shares_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_item_ts (item_id, snapshot_ts)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_items );
        dbDelta( $sql_clust );
        dbDelta( $sql_soc_accts );
        dbDelta( $sql_soc_items );
        dbDelta( $sql_soc_snaps );

        // v5: seed дефолтных social-аккаунтов. Идемпотентно:
        // UNIQUE (platform, handle) гарантирует, что повтор INSERT IGNORE
        // ничего не перетрёт.
        Social::seed_defaults();

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

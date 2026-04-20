<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Тянет RSS каждого включённого источника, парсит через SimplePie (встроен в WP),
 * сохраняет новые айтемы в tm_news_items.
 */
final class Fetcher {

    /** Максимум айтемов с одного фида за один проход. */
    private const PER_FEED_LIMIT = 25;

    /** Сколько по давности нас интересует (секунд). */
    private const MAX_AGE = 48 * 3600;

    public static function fetch_all(): int {
        $total = 0;
        foreach ( Sources::enabled() as $key => $src ) {
            try {
                $total += self::fetch_one( $key, $src );
            } catch ( \Throwable $e ) {
                Logger::error( 'fetch failed', [ 'src' => $key, 'err' => $e->getMessage() ] );
            }
        }
        return $total;
    }

    public static function fetch_one( string $key, array $src ): int {
        if ( ! function_exists( 'fetch_feed' ) ) {
            require_once ABSPATH . WPINC . '/feed.php';
        }
        $feed = fetch_feed( $src['url'] );
        if ( is_wp_error( $feed ) ) {
            Logger::warn( 'fetch_feed error', [ 'src' => $key, 'err' => $feed->get_error_message() ] );
            return 0;
        }

        $feed->set_cache_duration( 300 );
        $max  = $feed->get_item_quantity( self::PER_FEED_LIMIT );
        $now  = time();
        $cutoff = $now - self::MAX_AGE;
        $saved  = 0;

        /** @var \SimplePie_Item[] $items */
        $items = $feed->get_items( 0, $max );
        foreach ( $items as $item ) {
            $url   = (string) $item->get_permalink();
            $title = Normalizer::clean_text( (string) $item->get_title() );
            if ( $url === '' || $title === '' ) {
                continue;
            }
            $pub_ts = (int) ( $item->get_date( 'U' ) ?: $now );
            if ( $pub_ts < $cutoff ) {
                continue;
            }
            $desc = (string) $item->get_description();
            $excerpt = Normalizer::clean_text( $desc );
            if ( mb_strlen( $excerpt ) > 800 ) {
                $excerpt = mb_substr( $excerpt, 0, 800 ) . '…';
            }

            if ( self::upsert_item( $key, $url, $title, $excerpt, $pub_ts, $now ) ) {
                $saved++;
            }
        }

        Logger::info( 'fetched', [ 'src' => $key, 'new' => $saved, 'total' => $max ] );
        return $saved;
    }

    private static function upsert_item( string $source_key, string $url, string $title, string $excerpt, int $pub_ts, int $now ): bool {
        global $wpdb;
        $table = Installer::items_table();

        $url_hash   = Normalizer::url_hash( $url );
        $title_norm = Normalizer::normalize_title( $title );

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE url_hash = %s",
            $url_hash
        ) );
        if ( $exists ) {
            return false;
        }

        $ok = $wpdb->insert( $table, [
            'source_key' => $source_key,
            'url'        => $url,
            'url_hash'   => $url_hash,
            'title'      => $title,
            'title_norm' => $title_norm,
            'excerpt'    => $excerpt,
            'lang'       => 'pl',
            'pub_ts'     => $pub_ts,
            'fetched_ts' => $now,
        ], [ '%s','%s','%s','%s','%s','%s','%s','%d','%d' ] );

        return (bool) $ok;
    }
}

<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Оценка "горячести" кластера:
 *   score = Σ source_weight(src_i) * exp(-(now - pub_ts_i)/τ) * topic_match(title)
 *
 * τ (recency tau, ч) и topic-ключи настраиваются в опциях.
 */
final class Scorer {

    public const OPTION_KEYS = 'tm_news_topic_keywords';
    public const OPTION_TAU  = 'tm_news_recency_tau_hours';

    public static function default_keywords(): array {
        return [
            'gdansk','gdańsk','gdynia','sopot','trojmiasto','trójmiasto',
            'pomorski','pomorskie','pomorze','westerplatte','oliwa','wrzeszcz','brzeźno','brzezno',
            'orłowo','orlowo','kamienna góra','kamienna gora','mevo','pkm','skm','ztm',
            'ecs','forum gdansk','stocznia','lotnisko rębiechowo','lotnisko rebiechowo',
            'amber','ergo arena','polsat plus arena',
        ];
    }

    public static function keywords(): array {
        $stored = get_option( self::OPTION_KEYS );
        if ( is_array( $stored ) && $stored ) {
            return $stored;
        }
        return self::default_keywords();
    }

    public static function tau_hours(): float {
        $v = get_option( self::OPTION_TAU, 6 );
        return (float) ( is_numeric( $v ) ? $v : 6 );
    }

    /**
     * Оценка «горячести» одного айтема по той же формуле, что даёт вклад
     * в score кластера: weight × exp(-age/τ) × topic_match.
     *
     * Возвращает число в диапазоне [0, 1]. Умножайте на 100 для UI-бейджа.
     *
     * @param array<string,mixed> $item         Row с полями source_key, title, excerpt, pub_ts.
     * @param array<string,array<string,mixed>> $sources     Sources::all().
     * @param string[]            $keywords_lc  Уже приведённые к lower-case keywords.
     * @param float               $tau_seconds  τ в секундах.
     */
    public static function score_item( array $item, array $sources, array $keywords_lc, float $tau_seconds, int $now ): float {
        $src   = $sources[ $item['source_key'] ?? '' ] ?? null;
        $w     = $src ? (float) ( $src['weight'] ?? 0.5 ) : 0.5;
        $age   = max( 0, $now - (int) ( $item['pub_ts'] ?? 0 ) );
        $rec   = $tau_seconds > 0 ? exp( -$age / $tau_seconds ) : 0.0;
        $text  = mb_strtolower( (string) ( $item['title'] ?? '' ) . ' ' . (string) ( $item['excerpt'] ?? '' ) );
        $match = 0.3;
        foreach ( $keywords_lc as $k ) {
            if ( $k !== '' && str_contains( $text, $k ) ) {
                $match = 1.0;
                break;
            }
        }
        return $w * $rec * $match;
    }

    public static function score_all(): int {
        global $wpdb;
        $items_t = Installer::items_table();
        $clust_t = Installer::clusters_table();

        $sources = Sources::all();
        $kw      = array_map( 'mb_strtolower', self::keywords() );
        $tau     = self::tau_hours() * 3600.0;
        $now     = time();

        $clusters = $wpdb->get_results(
            "SELECT id FROM {$clust_t} WHERE status IN ('new','drafted') AND last_seen >= UNIX_TIMESTAMP() - 172800",
            ARRAY_A
        );

        $updated = 0;
        foreach ( $clusters as $c ) {
            $cid = (int) $c['id'];
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_key, title, excerpt, pub_ts FROM {$items_t} WHERE cluster_id = %d",
                $cid
            ), ARRAY_A );
            if ( ! $items ) {
                continue;
            }

            $score = 0.0;
            $best_canonical = null;
            $best_w = -1.0;
            foreach ( $items as $it ) {
                $src  = $sources[ $it['source_key'] ] ?? null;
                $w    = $src ? (float) $src['weight'] : 0.5;
                $age  = max( 0, $now - (int) $it['pub_ts'] );
                $rec  = exp( -$age / $tau );
                $text = mb_strtolower( ( $it['title'] ?? '' ) . ' ' . ( $it['excerpt'] ?? '' ) );
                $match = 0.3;
                foreach ( $kw as $k ) {
                    if ( $k !== '' && str_contains( $text, $k ) ) {
                        $match = 1.0;
                        break;
                    }
                }
                $score += $w * $rec * $match;

                if ( $w > $best_w ) {
                    $best_w = $w;
                    $best_canonical = $it;
                }
            }

            $canonical_id = null;
            if ( $best_canonical ) {
                $canonical_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$items_t}
                     WHERE cluster_id = %d AND source_key = %s AND pub_ts = %d
                     LIMIT 1",
                    $cid, $best_canonical['source_key'], (int) $best_canonical['pub_ts']
                ) );
            }

            $data = [ 'score' => $score ];
            $fmt  = [ '%f' ];
            if ( $canonical_id ) {
                $data['canonical_item_id'] = (int) $canonical_id;
                $fmt[]                     = '%d';
            }
            $wpdb->update( $clust_t, $data, [ 'id' => $cid ], $fmt, [ '%d' ] );
            $updated++;
        }

        Logger::info( 'scored', [ 'clusters' => $updated ] );
        return $updated;
    }
}

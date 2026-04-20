<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Кластеризация: для каждого нового айтема ищет подходящий открытый кластер
 * (по Jaccard нормализованных заголовков), иначе создаёт новый.
 *
 * Окно сравнения — последние 48 часов активных кластеров.
 */
final class Clusterer {

    private const JACCARD_THRESHOLD = 0.35;
    private const WINDOW            = 48 * 3600;

    public static function cluster_unassigned(): int {
        global $wpdb;
        $items_t = Installer::items_table();
        $clust_t = Installer::clusters_table();
        $now     = time();
        $cutoff  = $now - self::WINDOW;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title_norm, pub_ts
             FROM {$items_t}
             WHERE cluster_id IS NULL AND pub_ts >= %d
             ORDER BY pub_ts ASC
             LIMIT 500",
            $cutoff
        ), ARRAY_A );

        if ( ! $rows ) {
            return 0;
        }

        $open = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, last_seen FROM {$clust_t}
             WHERE status IN ('new','drafted') AND last_seen >= %d",
            $cutoff
        ), ARRAY_A );

        $cluster_tokens = [];
        foreach ( $open as $c ) {
            $cid = (int) $c['id'];
            $titles = $wpdb->get_col( $wpdb->prepare(
                "SELECT title_norm FROM {$items_t} WHERE cluster_id = %d",
                $cid
            ) );
            $set = [];
            foreach ( $titles as $t ) {
                $set = array_merge( $set, Normalizer::token_set( (string) $t ) );
            }
            $cluster_tokens[ $cid ] = array_values( array_unique( $set ) );
        }

        $assigned = 0;
        foreach ( $rows as $r ) {
            $item_id = (int) $r['id'];
            $tokens  = Normalizer::token_set( (string) $r['title_norm'] );

            $best_cid   = 0;
            $best_score = 0.0;
            foreach ( $cluster_tokens as $cid => $ctoks ) {
                $j = Normalizer::jaccard( $tokens, $ctoks );
                if ( $j >= self::JACCARD_THRESHOLD && $j > $best_score ) {
                    $best_score = $j;
                    $best_cid   = $cid;
                }
            }

            if ( $best_cid ) {
                $wpdb->update( $items_t, [ 'cluster_id' => $best_cid ], [ 'id' => $item_id ], [ '%d' ], [ '%d' ] );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$clust_t}
                     SET item_count = item_count + 1, last_seen = GREATEST(last_seen, %d)
                     WHERE id = %d",
                    (int) $r['pub_ts'], $best_cid
                ) );
                $cluster_tokens[ $best_cid ] = array_values( array_unique( array_merge( $cluster_tokens[ $best_cid ], $tokens ) ) );
            } else {
                $wpdb->insert( $clust_t, [
                    'first_seen'        => (int) $r['pub_ts'],
                    'last_seen'         => (int) $r['pub_ts'],
                    'item_count'        => 1,
                    'score'             => 0,
                    'status'            => 'new',
                    'canonical_item_id' => $item_id,
                ], [ '%d','%d','%d','%f','%s','%d' ] );
                $new_cid = (int) $wpdb->insert_id;
                $wpdb->update( $items_t, [ 'cluster_id' => $new_cid ], [ 'id' => $item_id ], [ '%d' ], [ '%d' ] );
                $cluster_tokens[ $new_cid ] = $tokens;
            }
            $assigned++;
        }

        Logger::info( 'clustered', [ 'assigned' => $assigned ] );
        return $assigned;
    }
}

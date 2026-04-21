<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Превращает топ-K горячих кластеров в черновики постов CPT tm_news_digest.
 * Идемпотентно: пропускает кластеры, у которых уже есть post_id.
 */
final class Publisher {

    public const OPTION_TOPK         = 'tm_news_top_k';
    public const OPTION_MIN_SCORE    = 'tm_news_min_score';
    public const OPTION_AUTOPUBLISH  = 'tm_news_autopublish';
    public const OPTION_CATEGORY_ID  = 'tm_news_category_id';

    public static function publish_top( bool $dry_run = false ): array {
        global $wpdb;
        $clust_t = Installer::clusters_table();
        $items_t = Installer::items_table();

        $top_k     = (int) get_option( self::OPTION_TOPK, 8 );
        $min_score = (float) get_option( self::OPTION_MIN_SCORE, 0.6 );
        $auto      = (bool) get_option( self::OPTION_AUTOPUBLISH, 0 );
        $cat_id    = (int) get_option( self::OPTION_CATEGORY_ID, 0 );

        $clusters = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, score, item_count, canonical_item_id
             FROM {$clust_t}
             WHERE status = 'new'
               AND post_id IS NULL
               AND score >= %f
             ORDER BY score DESC, last_seen DESC
             LIMIT %d",
            $min_score, $top_k
        ), ARRAY_A );

        $translator = Translator_OpenAI::from_options() ?? new Translator_Null();
        $is_null    = $translator instanceof Translator_Null;

        // Безопасность: без настроенного LLM мы НЕ создаём реальные посты —
        // иначе в черновики попадёт нетронутый польский текст.
        // Dry-run остаётся разрешённым, чтобы в админке можно было увидеть,
        // какие кластеры поднялись бы наверх.
        if ( $is_null && ! $dry_run ) {
            Logger::warn( 'publish skipped: OpenAI key not configured' );
            return [
                'created' => [],
                'skipped' => count( $clusters ),
                'errors'  => [ [ 'cluster' => 0, 'err' => 'OpenAI API key is not configured; posts not created' ] ],
                'dry_run' => false,
            ];
        }

        $created = [];
        $skipped = 0;
        $errors  = [];

        foreach ( $clusters as $c ) {
            $cid          = (int) $c['id'];
            $canonical_id = (int) ( $c['canonical_item_id'] ?? 0 );
            if ( ! $canonical_id ) {
                $skipped++;
                continue;
            }

            $canonical = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, source_key, url, title, excerpt FROM {$items_t} WHERE id = %d",
                $canonical_id
            ), ARRAY_A );
            if ( ! $canonical ) {
                $skipped++;
                continue;
            }

            $also = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_key, url, title FROM {$items_t}
                 WHERE cluster_id = %d AND id != %d
                 ORDER BY pub_ts ASC
                 LIMIT 6",
                $cid, $canonical_id
            ), ARRAY_A );

            try {
                $rewrite = $translator->rewrite(
                    (string) $canonical['title'],
                    (string) ( $canonical['excerpt'] ?? '' ),
                    (string) $canonical['url']
                );
            } catch ( \Throwable $e ) {
                Logger::error( 'translator failed', [ 'cluster' => $cid, 'err' => $e->getMessage() ] );
                $errors[] = [ 'cluster' => $cid, 'err' => $e->getMessage() ];
                continue;
            }

            if ( $dry_run ) {
                $created[] = [
                    'cluster' => $cid,
                    'score'   => (float) $c['score'],
                    'title'   => $rewrite['title_ru'],
                    'summary' => $rewrite['summary_ru'],
                    'source'  => $canonical['url'],
                    'is_null' => $is_null,
                ];
                continue;
            }

            $sources       = Sources::all();
            $src_meta      = $sources[ $canonical['source_key'] ] ?? [ 'name' => $canonical['source_key'] ];
            $body          = self::render_body( $rewrite, $canonical, $also, $src_meta['name'] );

            $postarr = [
                'post_type'    => CPT::POST_TYPE,
                'post_status'  => $auto ? 'publish' : 'draft',
                'post_title'   => $rewrite['title_ru'],
                'post_excerpt' => $rewrite['summary_ru'],
                'post_content' => $body,
                'meta_input'   => [
                    'tm_news_source_name' => (string) $src_meta['name'],
                    'tm_news_source_url'  => (string) $canonical['url'],
                    'tm_news_orig_title'  => (string) $canonical['title'],
                    'tm_news_summary_ru'  => $rewrite['summary_ru'],
                    'tm_news_summary_uk'  => $rewrite['summary_uk'],
                    'tm_news_cluster_id'  => $cid,
                ],
            ];
            if ( $cat_id > 0 ) {
                $postarr['post_category'] = [ $cat_id ];
            }

            $post_id = wp_insert_post( $postarr, true );
            if ( is_wp_error( $post_id ) ) {
                Logger::error( 'wp_insert_post failed', [ 'cluster' => $cid, 'err' => $post_id->get_error_message() ] );
                $errors[] = [ 'cluster' => $cid, 'err' => $post_id->get_error_message() ];
                continue;
            }

            $wpdb->update( $clust_t, [
                'status'  => $auto ? 'published' : 'drafted',
                'post_id' => (int) $post_id,
            ], [ 'id' => $cid ], [ '%s', '%d' ], [ '%d' ] );

            $created[] = [ 'cluster' => $cid, 'post_id' => $post_id, 'title' => $rewrite['title_ru'] ];
            Logger::info( 'post created', [ 'cluster' => $cid, 'post_id' => $post_id ] );
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
            'dry_run' => $dry_run,
        ];
    }

    /**
     * Принудительно сделать черновик из одного item (в обход логики score/top-K).
     * Вызывается со страницы «Забранные новости» через bulk action.
     *
     * Защита от дубля: если по tm_news_source_url уже есть пост — возвращаем
     * ok=false с ссылкой на существующий черновик.
     */
    public static function publish_item( int $item_id, bool $dry_run = false ): array {
        global $wpdb;
        $items_t = Installer::items_table();
        $clust_t = Installer::clusters_table();

        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, source_key, url, title, excerpt, cluster_id FROM {$items_t} WHERE id = %d",
            $item_id
        ), ARRAY_A );
        if ( ! $item ) {
            return [ 'ok' => false, 'err' => 'item not found' ];
        }

        $translator = Translator_OpenAI::from_options() ?? new Translator_Null();
        $is_null    = $translator instanceof Translator_Null;
        if ( $is_null && ! $dry_run ) {
            return [ 'ok' => false, 'err' => 'OpenAI API key is not configured' ];
        }

        $dup = get_posts( [
            'post_type'      => CPT::POST_TYPE,
            'post_status'    => 'any',
            'meta_key'       => 'tm_news_source_url',
            'meta_value'     => (string) $item['url'],
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ] );
        if ( ! empty( $dup ) ) {
            return [ 'ok' => false, 'err' => 'draft already exists for this URL', 'post_id' => (int) $dup[0] ];
        }

        $cluster_id = (int) ( $item['cluster_id'] ?? 0 );
        $also       = [];
        if ( $cluster_id ) {
            $also = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_key, url, title FROM {$items_t}
                 WHERE cluster_id = %d AND id != %d
                 ORDER BY pub_ts ASC
                 LIMIT 6",
                $cluster_id, $item_id
            ), ARRAY_A ) ?: [];
        }

        try {
            $rewrite = $translator->rewrite(
                (string) $item['title'],
                (string) ( $item['excerpt'] ?? '' ),
                (string) $item['url']
            );
        } catch ( \Throwable $e ) {
            Logger::error( 'translator failed (item)', [ 'item' => $item_id, 'err' => $e->getMessage() ] );
            return [ 'ok' => false, 'err' => $e->getMessage() ];
        }

        if ( $dry_run ) {
            return [ 'ok' => true, 'dry_run' => true, 'title' => $rewrite['title_ru'] ];
        }

        $auto     = (bool) get_option( self::OPTION_AUTOPUBLISH, 0 );
        $cat_id   = (int) get_option( self::OPTION_CATEGORY_ID, 0 );
        $sources  = Sources::all();
        $src_meta = $sources[ $item['source_key'] ] ?? [ 'name' => $item['source_key'] ];
        $body     = self::render_body( $rewrite, $item, $also, (string) $src_meta['name'] );

        $postarr = [
            'post_type'    => CPT::POST_TYPE,
            'post_status'  => $auto ? 'publish' : 'draft',
            'post_title'   => $rewrite['title_ru'],
            'post_excerpt' => $rewrite['summary_ru'],
            'post_content' => $body,
            'meta_input'   => [
                'tm_news_source_name' => (string) $src_meta['name'],
                'tm_news_source_url'  => (string) $item['url'],
                'tm_news_orig_title'  => (string) $item['title'],
                'tm_news_summary_ru'  => $rewrite['summary_ru'],
                'tm_news_summary_uk'  => $rewrite['summary_uk'],
                'tm_news_cluster_id'  => $cluster_id,
            ],
        ];
        if ( $cat_id > 0 ) {
            $postarr['post_category'] = [ $cat_id ];
        }

        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            Logger::error( 'wp_insert_post failed (item)', [ 'item' => $item_id, 'err' => $post_id->get_error_message() ] );
            return [ 'ok' => false, 'err' => $post_id->get_error_message() ];
        }

        if ( $cluster_id ) {
            $wpdb->update( $clust_t, [
                'status'  => $auto ? 'published' : 'drafted',
                'post_id' => (int) $post_id,
            ], [ 'id' => $cluster_id ], [ '%s', '%d' ], [ '%d' ] );
        }

        Logger::info( 'post created from item', [ 'item' => $item_id, 'post_id' => $post_id ] );
        return [ 'ok' => true, 'post_id' => (int) $post_id, 'title' => $rewrite['title_ru'] ];
    }

    private static function render_body( array $rewrite, array $canonical, array $also, string $source_name ): string {
        $src_url   = esc_url( (string) $canonical['url'] );
        $src_label = esc_html( $source_name );
        $orig_t    = esc_html( (string) $canonical['title'] );

        $ru_summary = esc_html( $rewrite['summary_ru'] );
        $uk_title   = esc_html( $rewrite['title_uk'] );
        $uk_summary = esc_html( $rewrite['summary_uk'] );

        $also_html = '';
        if ( $also ) {
            $sources = Sources::all();
            $li      = '';
            foreach ( $also as $a ) {
                $name = $sources[ $a['source_key'] ]['name'] ?? $a['source_key'];
                $li  .= sprintf(
                    '<li><a href="%s" rel="nofollow noopener" target="_blank">%s</a> — %s</li>',
                    esc_url( $a['url'] ), esc_html( $a['title'] ), esc_html( $name )
                );
            }
            $also_html = "<h3>Также писали</h3><ul>{$li}</ul>";
        }

        return <<<HTML
<!-- wp:paragraph --><p>{$ru_summary}</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p><strong>Источник:</strong> <a href="{$src_url}" rel="nofollow noopener" target="_blank">{$src_label}</a> — {$orig_t}</p><!-- /wp:paragraph -->

<!-- wp:heading {"level":3} --><h3>{$uk_title}</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>{$uk_summary}</p><!-- /wp:paragraph -->

<!-- wp:html -->
{$also_html}
<!-- /wp:html -->
HTML;
    }
}

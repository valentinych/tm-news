<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CPT для дайджеста новостей. Сюда плагин создаёт черновики;
 * редактор вручную утверждает публикацию.
 */
final class CPT {

    public const POST_TYPE = 'tm_news_digest';

    public static function register(): void {
        register_post_type( self::POST_TYPE, [
            'label'         => __( 'News digest', 'tm-news' ),
            'labels'        => [
                'name'          => __( 'News digest', 'tm-news' ),
                'singular_name' => __( 'News item', 'tm-news' ),
                'add_new_item'  => __( 'Add news item', 'tm-news' ),
                'edit_item'     => __( 'Edit news item', 'tm-news' ),
                'all_items'     => __( 'All news', 'tm-news' ),
                'menu_name'     => __( 'News digest', 'tm-news' ),
            ],
            'public'        => true,
            'show_in_rest'  => true,
            'has_archive'   => true,
            // ВАЖНО: не использовать slug 'novosti' — он занят slug'ом
            // категории «Новости», через которую публикуются обычные post-записи.
            // Иначе CPT-rewrite перехватывает все /novosti/<...>/ и даёт 404.
            'rewrite'       => [ 'slug' => 'news-digest' ],
            'menu_icon'     => 'dashicons-rss',
            'menu_position' => 22,
            'supports'      => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
            'taxonomies'    => [ 'category', 'post_tag' ],
        ] );

        register_post_meta( self::POST_TYPE, 'tm_news_source_name', [
            'single' => true, 'type' => 'string', 'show_in_rest' => true,
        ] );
        register_post_meta( self::POST_TYPE, 'tm_news_source_url', [
            'single' => true, 'type' => 'string', 'show_in_rest' => true,
        ] );
        register_post_meta( self::POST_TYPE, 'tm_news_orig_title', [
            'single' => true, 'type' => 'string', 'show_in_rest' => true,
        ] );
        register_post_meta( self::POST_TYPE, 'tm_news_summary_ru', [
            'single' => true, 'type' => 'string', 'show_in_rest' => true,
        ] );
        register_post_meta( self::POST_TYPE, 'tm_news_summary_uk', [
            'single' => true, 'type' => 'string', 'show_in_rest' => true,
        ] );
        register_post_meta( self::POST_TYPE, 'tm_news_cluster_id', [
            'single' => true, 'type' => 'integer', 'show_in_rest' => true,
        ] );
    }
}

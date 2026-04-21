<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Админ-таблица забранных RSS-items.
 * Данные — из tm_news_items. Bulk action «Сделать черновиком» делегируется
 * в Publisher::publish_item() из страницы Admin::render_items_page().
 */
final class Items_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'tm_news_item',
            'plural'   => 'tm_news_items',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'title'      => __( 'Заголовок', 'tm-news' ),
            'source'     => __( 'Источник', 'tm-news' ),
            'pub_ts'     => __( 'Опубликовано', 'tm-news' ),
            'fetched_ts' => __( 'Забрано', 'tm-news' ),
            'status'     => __( 'Статус', 'tm-news' ),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'pub_ts'     => [ 'pub_ts', true ],
            'fetched_ts' => [ 'fetched_ts', false ],
            'source'     => [ 'source_key', false ],
        ];
    }

    public function get_bulk_actions(): array {
        return [
            'tm_news_make_draft' => __( 'Сделать черновиком', 'tm-news' ),
        ];
    }

    public function prepare_items(): void {
        global $wpdb;
        $items_t = Installer::items_table();

        $per_page = 25;
        $paged    = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $orderby_map = [
            'pub_ts'     => 'pub_ts',
            'fetched_ts' => 'fetched_ts',
            'source_key' => 'source_key',
            'source'     => 'source_key',
        ];
        $orderby_in = (string) ( $_REQUEST['orderby'] ?? 'fetched_ts' );
        $orderby    = $orderby_map[ $orderby_in ] ?? 'fetched_ts';
        $order      = strtolower( (string) ( $_REQUEST['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';

        $search = trim( (string) ( $_REQUEST['s'] ?? '' ) );
        if ( $search !== '' ) {
            $like        = '%' . $wpdb->esc_like( $search ) . '%';
            $total       = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_t} WHERE title LIKE %s OR url LIKE %s",
                $like, $like
            ) );
            $this->items = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$items_t}
                 WHERE title LIKE %s OR url LIKE %s
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $like, $like, $per_page, $offset
            ), ARRAY_A );
        } else {
            $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_t}" );
            $this->items = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$items_t} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A );
        }

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
        ] );
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="item_ids[]" value="%d" />', (int) $item['id'] );
    }

    public function column_title( $item ): string {
        return sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener">%s</a></strong>',
            esc_url( (string) $item['url'] ),
            esc_html( (string) $item['title'] )
        );
    }

    public function column_source( $item ): string {
        $sources = Sources::all();
        $key     = (string) $item['source_key'];
        $name    = $sources[ $key ]['name'] ?? $key;
        return esc_html( (string) $name );
    }

    public function column_pub_ts( $item ): string {
        return esc_html( $this->fmt_ts( (int) $item['pub_ts'] ) );
    }

    public function column_fetched_ts( $item ): string {
        return esc_html( $this->fmt_ts( (int) $item['fetched_ts'] ) );
    }

    public function column_status( $item ): string {
        global $wpdb;
        $cluster_id = (int) ( $item['cluster_id'] ?? 0 );
        if ( ! $cluster_id ) {
            return '<span style="color:#888;">—</span>';
        }
        $clust_t = Installer::clusters_table();
        $row     = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, post_id FROM {$clust_t} WHERE id = %d",
            $cluster_id
        ), ARRAY_A );
        if ( $row && ! empty( $row['post_id'] ) ) {
            $edit = get_edit_post_link( (int) $row['post_id'] );
            return sprintf(
                '%s <a href="%s">#%d</a>',
                esc_html__( 'черновик', 'tm-news' ),
                esc_url( (string) $edit ),
                (int) $row['post_id']
            );
        }
        return sprintf( esc_html__( 'в кластере #%d', 'tm-news' ), $cluster_id );
    }

    public function column_default( $item, $column_name ): string {
        return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
    }

    public function no_items(): void {
        esc_html_e( 'Пока ничего не забрано. Нажмите «Забрать новости».', 'tm-news' );
    }

    private function fmt_ts( int $ts ): string {
        if ( $ts <= 0 ) {
            return '—';
        }
        return wp_date( 'Y-m-d H:i', $ts );
    }
}

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
            'score'      => __( 'Score', 'tm-news' ),
            'title'      => __( 'Заголовок', 'tm-news' ),
            'source'     => __( 'Источник', 'tm-news' ),
            'pub_ts'     => __( 'Опубликовано', 'tm-news' ),
            'fetched_ts' => __( 'Забрано', 'tm-news' ),
            'status'     => __( 'Статус', 'tm-news' ),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'score'      => [ 'score', true ],
            'pub_ts'     => [ 'pub_ts', false ],
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

        $per_page   = 25;
        $paged      = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );
        $offset     = ( $paged - 1 ) * $per_page;
        $orderby_in = (string) ( $_REQUEST['orderby'] ?? 'score' );
        $order_asc  = strtolower( (string) ( $_REQUEST['order'] ?? 'desc' ) ) === 'asc';

        $search = trim( (string) ( $_REQUEST['s'] ?? '' ) );
        $like   = $search !== '' ? '%' . $wpdb->esc_like( $search ) . '%' : null;

        if ( $orderby_in === 'score' ) {
            // Score — вычислимое поле, сортировка делается в PHP по всему
            // отфильтрованному набору, затем слайс под страницу.
            if ( $like !== null ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$items_t}
                     WHERE title LIKE %s OR url LIKE %s
                     ORDER BY fetched_ts DESC
                     LIMIT 5000",
                    $like, $like
                ), ARRAY_A );
            } else {
                $rows = $wpdb->get_results(
                    "SELECT * FROM {$items_t} ORDER BY fetched_ts DESC LIMIT 5000",
                    ARRAY_A
                );
            }
            $rows = self::annotate_score( $rows );
            usort( $rows, static function ( $a, $b ) use ( $order_asc ) {
                $cmp = $a['score_100'] <=> $b['score_100'];
                return $order_asc ? $cmp : -$cmp;
            } );
            $total       = count( $rows );
            $this->items = array_slice( $rows, $offset, $per_page );
        } else {
            $orderby_sql_map = [
                'pub_ts'     => 'pub_ts',
                'fetched_ts' => 'fetched_ts',
                'source_key' => 'source_key',
                'source'     => 'source_key',
            ];
            $orderby = $orderby_sql_map[ $orderby_in ] ?? 'fetched_ts';
            $order   = $order_asc ? 'ASC' : 'DESC';

            if ( $like !== null ) {
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
            $this->items = self::annotate_score( $this->items );
        }

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
        ] );
    }

    /**
     * Добавляет поле score_100 (int 0..100) к каждому row.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function annotate_score( array $rows ): array {
        if ( ! $rows ) {
            return $rows;
        }
        $sources  = Sources::all();
        $kw_lc    = array_map( 'mb_strtolower', Scorer::keywords() );
        $tau_s    = Scorer::tau_hours() * 3600.0;
        $now      = time();
        foreach ( $rows as &$r ) {
            $r['score_100'] = (int) round( Scorer::score_item( $r, $sources, $kw_lc, $tau_s, $now ) * 100 );
        }
        unset( $r );
        return $rows;
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="item_ids[]" value="%d" />', (int) $item['id'] );
    }

    public function column_score( $item ): string {
        $s = max( 0, min( 100, (int) ( $item['score_100'] ?? 0 ) ) );
        return self::render_score_badge( $s );
    }

    /**
     * HSL от красного (0) через жёлтый (60) к зелёному (120) — hue = score * 1.2.
     */
    public static function render_score_badge( int $score ): string {
        $score = max( 0, min( 100, $score ) );
        $hue   = (int) round( $score * 1.2 );
        $style = sprintf(
            'background:hsl(%d,70%%,45%%);color:#fff;padding:2px 10px;border-radius:10px;font-weight:600;display:inline-block;min-width:34px;text-align:center;',
            $hue
        );
        return sprintf( '<span style="%s">%d</span>', esc_attr( $style ), $score );
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
        return sprintf( esc_html__( 'сгруппирован #%d', 'tm-news' ), $cluster_id );
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

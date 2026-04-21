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
            'tags'       => __( 'Теги', 'tm-news' ),
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

        [ $where, $args ] = $this->build_filters_where();

        if ( $orderby_in === 'score' ) {
            // Score — вычислимое поле, сортировка делается в PHP по всей
            // отфильтрованной выборке, затем слайс под страницу.
            $sql  = "SELECT * FROM {$items_t} WHERE {$where} ORDER BY fetched_ts DESC LIMIT 5000";
            $rows = $args
                ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
                : $wpdb->get_results( $sql, ARRAY_A );

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

            $count_sql = "SELECT COUNT(*) FROM {$items_t} WHERE {$where}";
            $total     = (int) ( $args
                ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) )
                : $wpdb->get_var( $count_sql ) );

            $page_sql = "SELECT * FROM {$items_t} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
            $this->items = $wpdb->get_results( $wpdb->prepare(
                $page_sql,
                array_merge( $args, [ $per_page, $offset ] )
            ), ARRAY_A );
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
     * Собирает кусок WHERE и позиционные аргументы для wpdb->prepare()
     * из $_REQUEST: s, source[], date_from, date_to.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function build_filters_where(): array {
        global $wpdb;
        $parts = [];
        $args  = [];

        $search = trim( (string) ( $_REQUEST['s'] ?? '' ) );
        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $parts[] = '(title LIKE %s OR url LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        $sources = self::parse_sources_filter();
        if ( $sources ) {
            $ph      = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
            $parts[] = "source_key IN ({$ph})";
            foreach ( $sources as $s ) {
                $args[] = $s;
            }
        }

        [ $from_ts, $to_ts ] = self::parse_date_range();
        if ( $from_ts !== null ) {
            $parts[] = 'pub_ts >= %d';
            $args[]  = $from_ts;
        }
        if ( $to_ts !== null ) {
            $parts[] = 'pub_ts < %d';
            $args[]  = $to_ts;
        }

        return [ $parts ? implode( ' AND ', $parts ) : '1=1', $args ];
    }

    /**
     * @return string[] Валидные ключи источников из $_REQUEST['source'].
     */
    public static function parse_sources_filter(): array {
        $raw = $_REQUEST['source'] ?? [];
        if ( is_string( $raw ) ) {
            $raw = [ $raw ];
        }
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $known = array_keys( Sources::all() );
        $out   = [];
        foreach ( $raw as $s ) {
            $s = sanitize_key( (string) $s );
            if ( $s !== '' && in_array( $s, $known, true ) ) {
                $out[] = $s;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * @return array{0:?int,1:?int} from_ts (≥), to_ts (<). Оба опциональны.
     */
    public static function parse_date_range(): array {
        $tz = wp_timezone();
        $from_ts = null;
        $to_ts   = null;
        $from_in = trim( (string) ( $_REQUEST['date_from'] ?? '' ) );
        $to_in   = trim( (string) ( $_REQUEST['date_to'] ?? '' ) );
        if ( $from_in !== '' ) {
            try {
                $from_ts = ( new \DateTimeImmutable( $from_in . ' 00:00:00', $tz ) )->getTimestamp();
            } catch ( \Throwable $e ) {
                $from_ts = null;
            }
        }
        if ( $to_in !== '' ) {
            try {
                // Верхняя граница включительно по дню: < следующий день 00:00.
                $to_ts = ( new \DateTimeImmutable( $to_in . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp();
            } catch ( \Throwable $e ) {
                $to_ts = null;
            }
        }
        return [ $from_ts, $to_ts ];
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

    public function column_tags( $item ): string {
        $tags = Tagger::detect( (string) ( $item['title'] ?? '' ), (string) ( $item['excerpt'] ?? '' ) );
        if ( ! $tags ) {
            return '<span style="color:#999;">—</span>';
        }
        $style = 'display:inline-block;background:#f0f0f1;color:#2c3338;border:1px solid #dcdcde;padding:1px 7px;border-radius:10px;font-size:11px;margin:0 3px 3px 0;';
        $out   = '';
        foreach ( $tags as $t ) {
            $out .= sprintf( '<span style="%s">%s</span>', esc_attr( $style ), esc_html( $t ) );
        }
        return $out;
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

<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Таблица постов/видео из tm_social_items со счётчиками и velocity
 * (скорость прироста лайков/комментов/просмотров за последний час).
 */
final class Social_Items_Table extends \WP_List_Table {

    private const PER_PAGE = 25;

    public function __construct() {
        parent::__construct( [
            'singular' => 'tm_social_item',
            'plural'   => 'tm_social_items',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'platform'     => __( 'Платформа', 'tm-news' ),
            'account'      => __( 'Аккаунт', 'tm-news' ),
            'title'        => __( 'Заголовок', 'tm-news' ),
            'tags'         => __( 'Теги', 'tm-news' ),
            'views'        => __( 'Просмотры', 'tm-news' ),
            'likes'        => __( 'Лайки', 'tm-news' ),
            'comments'     => __( 'Комменты', 'tm-news' ),
            'vel_likes'    => __( 'Лайки/ч', 'tm-news' ),
            'vel_views'    => __( 'Просмотры/ч', 'tm-news' ),
            'published_ts' => __( 'Опубликовано', 'tm-news' ),
            'updated_ts'   => __( 'Обновлено', 'tm-news' ),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'views'        => [ 'views', true ],
            'likes'        => [ 'likes', true ],
            'comments'     => [ 'comments', true ],
            'vel_likes'    => [ 'vel_likes', true ],
            'vel_views'    => [ 'vel_views', true ],
            'published_ts' => [ 'published_ts', true ],
            'updated_ts'   => [ 'updated_ts', true ],
        ];
    }

    public function prepare_items(): void {
        global $wpdb;
        $items_t = Installer::social_items_table();
        $accts_t = Installer::social_accounts_table();

        $per_page   = self::PER_PAGE;
        $paged      = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );
        $orderby_in = (string) ( $_REQUEST['orderby'] ?? 'vel_likes' );
        $order_asc  = strtolower( (string) ( $_REQUEST['order'] ?? 'desc' ) ) === 'asc';

        [ $where, $args ] = $this->build_filters_where();

        // Выбираем до 1000 недавних items под фильтры, velocity считаем в PHP
        // и сортируем там же (проще и быстрее, чем JOIN двух snapshots в SQL).
        $sql  = "SELECT i.*, a.title AS account_title, a.handle AS account_handle, a.tags AS account_tags
                 FROM {$items_t} i
                 LEFT JOIN {$accts_t} a ON a.id = i.account_id
                 WHERE {$where}
                 ORDER BY i.published_ts DESC
                 LIMIT 1000";
        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        $rows = self::annotate_velocity( $rows );

        $by = [
            'views'        => 'views_count',
            'likes'        => 'likes_count',
            'comments'     => 'comments_count',
            'vel_likes'    => 'velocity_likes',
            'vel_views'    => 'velocity_views',
            'published_ts' => 'published_ts',
            'updated_ts'   => 'updated_ts',
        ];
        $key = $by[ $orderby_in ] ?? 'velocity_likes';
        usort( $rows, static function ( $a, $b ) use ( $key, $order_asc ) {
            $cmp = ( (float) ( $a[ $key ] ?? 0 ) ) <=> ( (float) ( $b[ $key ] ?? 0 ) );
            return $order_asc ? $cmp : -$cmp;
        } );

        $total = count( $rows );
        $this->items = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /**
     * Забирает velocity пачкой одним SQL и прибивает к rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function annotate_velocity( array $rows ): array {
        if ( ! $rows ) {
            return $rows;
        }
        global $wpdb;
        $t_snaps = Installer::social_snapshots_table();
        $ids     = array_map( static fn( $r ) => (int) $r['id'], $rows );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Для каждого item — два последних snapshot'а. MySQL 8 был бы красивее
        // через window functions, но WP поддерживает и 5.7, поэтому делаем
        // эффективно через GROUP BY с условной агрегацией по времени.
        $sql = "SELECT item_id, snapshot_ts, views_count, likes_count, comments_count
                FROM {$t_snaps}
                WHERE item_id IN ($placeholders)
                ORDER BY item_id, snapshot_ts DESC";
        $all = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

        $pairs = []; // item_id => [ latest, prev ]
        foreach ( (array) $all as $s ) {
            $iid = (int) $s['item_id'];
            if ( ! isset( $pairs[ $iid ] ) ) {
                $pairs[ $iid ] = [ $s, null ];
            } elseif ( $pairs[ $iid ][1] === null ) {
                $pairs[ $iid ][1] = $s;
            }
        }

        foreach ( $rows as &$r ) {
            $iid = (int) $r['id'];
            $r['velocity_likes']    = 0.0;
            $r['velocity_views']    = 0.0;
            $r['velocity_comments'] = 0.0;
            if ( ! isset( $pairs[ $iid ] ) || $pairs[ $iid ][1] === null ) {
                continue;
            }
            [ $latest, $prev ] = $pairs[ $iid ];
            $dt_sec = max( 1, (int) $latest['snapshot_ts'] - (int) $prev['snapshot_ts'] );
            $dt_h   = $dt_sec / 3600;
            $r['velocity_likes']    = max( 0.0, ( (int) $latest['likes_count']    - (int) $prev['likes_count'] )    / $dt_h );
            $r['velocity_views']    = max( 0.0, ( (int) $latest['views_count']    - (int) $prev['views_count'] )    / $dt_h );
            $r['velocity_comments'] = max( 0.0, ( (int) $latest['comments_count'] - (int) $prev['comments_count'] ) / $dt_h );
        }
        unset( $r );
        return $rows;
    }

    /**
     * Собирает WHERE под платформу/аккаунты/тег/даты из GET.
     *
     * @return array{0:string,1:array}
     */
    private function build_filters_where(): array {
        global $wpdb;
        $where = [ '1=1' ];
        $args  = [];

        $platform = (string) ( $_REQUEST['platform'] ?? '' );
        if ( in_array( $platform, [ Social::PLATFORM_YT, Social::PLATFORM_TG ], true ) ) {
            $where[] = 'i.platform = %s';
            $args[]  = $platform;
        }

        $accounts_raw = (array) ( $_REQUEST['account_id'] ?? [] );
        $accounts = array_values( array_filter( array_map( 'intval', $accounts_raw ) ) );
        if ( $accounts ) {
            $in = implode( ',', array_fill( 0, count( $accounts ), '%d' ) );
            $where[] = "i.account_id IN ($in)";
            foreach ( $accounts as $aid ) {
                $args[] = $aid;
            }
        }

        $tag = sanitize_text_field( (string) ( $_REQUEST['tag'] ?? '' ) );
        if ( $tag !== '' ) {
            $where[] = 'a.tags LIKE %s';
            $args[]  = '%' . $wpdb->esc_like( $tag ) . '%';
        }

        $date_from = self::parse_date_boundary( (string) ( $_REQUEST['date_from'] ?? '' ), false );
        $date_to   = self::parse_date_boundary( (string) ( $_REQUEST['date_to']   ?? '' ), true );
        if ( $date_from !== null ) {
            $where[] = 'i.published_ts >= %d';
            $args[]  = $date_from;
        }
        if ( $date_to !== null ) {
            $where[] = 'i.published_ts <= %d';
            $args[]  = $date_to;
        }

        $s = trim( (string) ( $_REQUEST['s'] ?? '' ) );
        if ( $s !== '' ) {
            $where[] = '(i.title LIKE %s OR i.excerpt LIKE %s)';
            $args[]  = '%' . $wpdb->esc_like( $s ) . '%';
            $args[]  = '%' . $wpdb->esc_like( $s ) . '%';
        }

        return [ implode( ' AND ', $where ), $args ];
    }

    private static function parse_date_boundary( string $raw, bool $end_of_day ): ?int {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable( $raw, new \DateTimeZone( wp_timezone_string() ) );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( $end_of_day ) {
            $dt = $dt->setTime( 23, 59, 59 );
        } else {
            $dt = $dt->setTime( 0, 0, 0 );
        }
        return $dt->getTimestamp();
    }

    // --- рендеры колонок -------------------------------------------------

    protected function column_platform( array $it ): string {
        $p    = (string) $it['platform'];
        $icon = $p === Social::PLATFORM_YT ? '▶' : ( $p === Social::PLATFORM_TG ? '✈' : '•' );
        return '<span title="' . esc_attr( $p ) . '" style="font-weight:600;">' . esc_html( $icon . ' ' . $p ) . '</span>';
    }

    protected function column_account( array $it ): string {
        $title  = (string) ( $it['account_title']  ?? '' );
        $handle = (string) ( $it['account_handle'] ?? '' );
        if ( $title === '' ) {
            $title = $handle;
        }
        return esc_html( $title ) . '<br /><code style="color:#666;font-size:11px;">' . esc_html( $handle ) . '</code>';
    }

    protected function column_title( array $it ): string {
        $url   = (string) $it['url'];
        $title = (string) $it['title'];
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url( $url ),
            esc_html( $title )
        );
    }

    protected function column_tags( array $it ): string {
        $tags = array_filter( array_map( 'trim', explode( ',', (string) ( $it['account_tags'] ?? '' ) ) ) );
        if ( ! $tags ) {
            return '<span style="color:#999;">—</span>';
        }
        $out = '';
        foreach ( $tags as $tg ) {
            $out .= '<span style="display:inline-block;background:#eef;border:1px solid #ccd;border-radius:3px;padding:1px 6px;margin:1px 2px;font-size:11px;">'
                 . esc_html( $tg ) . '</span>';
        }
        return $out;
    }

    protected function column_views( array $it ): string {
        return self::fmt_int( (int) $it['views_count'] );
    }
    protected function column_likes( array $it ): string {
        return self::fmt_int( (int) $it['likes_count'] );
    }
    protected function column_comments( array $it ): string {
        return self::fmt_int( (int) $it['comments_count'] );
    }
    protected function column_vel_likes( array $it ): string {
        return self::fmt_velocity( (float) ( $it['velocity_likes'] ?? 0 ) );
    }
    protected function column_vel_views( array $it ): string {
        return self::fmt_velocity( (float) ( $it['velocity_views'] ?? 0 ) );
    }

    protected function column_published_ts( array $it ): string {
        $ts = (int) $it['published_ts'];
        return $ts > 0 ? esc_html( wp_date( 'Y-m-d H:i', $ts ) ) : '—';
    }
    protected function column_updated_ts( array $it ): string {
        $ts = (int) $it['updated_ts'];
        return $ts > 0 ? esc_html( wp_date( 'Y-m-d H:i', $ts ) ) : '—';
    }

    protected function column_default( $item, $column_name ) {
        return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
    }

    // --- helpers ---------------------------------------------------------

    private static function fmt_int( int $n ): string {
        if ( $n >= 1_000_000 ) {
            return number_format( $n / 1_000_000, 1, '.', '' ) . 'M';
        }
        if ( $n >= 1_000 ) {
            return number_format( $n / 1_000, 1, '.', '' ) . 'K';
        }
        return (string) $n;
    }

    private static function fmt_velocity( float $v ): string {
        if ( $v <= 0 ) {
            return '<span style="color:#999;">0</span>';
        }
        $label = $v >= 10 ? (string) (int) round( $v ) : number_format( $v, 1, '.', '' );
        // Цветовой акцент: >100/ч — жирный красный, >10 — оранжевый, >1 — нормальный.
        if ( $v >= 100 ) {
            $color = '#b32d2e';
            $weight = '700';
        } elseif ( $v >= 10 ) {
            $color = '#c77700';
            $weight = '600';
        } else {
            $color = '#1d2327';
            $weight = '500';
        }
        return '<span style="color:' . $color . ';font-weight:' . $weight . ';">' . esc_html( $label ) . '/ч</span>';
    }
}

<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Social: мониторинг публичных каналов YouTube и Telegram.
 *
 * Архитектура:
 *  - accounts (таблица tm_social_accounts) — список отслеживаемых каналов.
 *  - items    (таблица tm_social_items)    — посты/видео с текущими метриками.
 *  - snaps    (таблица tm_social_snapshots)— исторические точки для velocity.
 *  - cron tm_social_cron_tick каждые 30 мин:
 *      * YouTube Data API v3 (нужен API key, бесплатно в рамках квоты 10k/сут).
 *      * Telegram — публичные превью t.me/s/<channel> (без авторизации).
 *  - velocity (likes/hour, views/hour) считается из разницы двух последних snapshot'ов.
 *
 * Ни один драйвер не обращается к закрытым API и не скрейпит интерфейсы,
 * требующие логина. TG-парсер работает только с публичными каналами,
 * которые Telegram сам отдаёт в виде HTML-превью без auth.
 */
final class Social {

    public const CRON_HOOK       = 'tm_social_cron_tick';
    public const CRON_SCHEDULE   = 'tm_social_30min';

    public const OPTION_YT_KEY   = 'tm_social_youtube_api_key';

    public const PLATFORM_YT     = 'youtube';
    public const PLATFORM_TG     = 'telegram';

    /** Сколько новых постов забирать из каждого канала за один тик. */
    private const PER_ACCOUNT_LIMIT = 20;

    /** Окно, внутри которого у существующих items освежаем метрики. */
    private const REFRESH_WINDOW = 48 * 3600;

    /** HTTP timeout для внешних запросов. */
    private const HTTP_TIMEOUT = 15;

    // ---------------------------------------------------------------
    // Seed / defaults
    // ---------------------------------------------------------------

    /**
     * Кандидаты, которые засеваются при первой установке. Все — disabled.
     * Редакция включает то, что реально существует и нужно. Если какой-то
     * handle окажется несуществующим, драйвер просто залогирует ошибку и
     * двинется дальше — ничего страшного.
     *
     * @return list<array{platform:string,handle:string,title:string,url:string,tags:string}>
     */
    public static function default_accounts(): array {
        return [
            // --- YouTube: трёхгородские ---
            [ 'platform' => 'youtube', 'handle' => '@RadioGdansk',    'title' => 'Radio Gdańsk',     'url' => 'https://www.youtube.com/@RadioGdansk',    'tags' => 'trojmiasto,local,news' ],
            [ 'platform' => 'youtube', 'handle' => '@trojmiastopl',   'title' => 'Trojmiasto.pl',    'url' => 'https://www.youtube.com/@trojmiastopl',   'tags' => 'trojmiasto,local' ],
            [ 'platform' => 'youtube', 'handle' => '@GdanskOficjalny','title' => 'Gdańsk (oficjalny)','url' => 'https://www.youtube.com/@GdanskOficjalny','tags' => 'trojmiasto,city,gdansk' ],
            [ 'platform' => 'youtube', 'handle' => '@GdyniaOficjalna','title' => 'Gdynia (oficjalna)','url' => 'https://www.youtube.com/@GdyniaOficjalna','tags' => 'trojmiasto,city,gdynia' ],
            [ 'platform' => 'youtube', 'handle' => '@MiastoSopot',    'title' => 'Sopot (oficjalny)','url' => 'https://www.youtube.com/@MiastoSopot',    'tags' => 'trojmiasto,city,sopot' ],
            [ 'platform' => 'youtube', 'handle' => '@LechiaGdansk',   'title' => 'Lechia Gdańsk',    'url' => 'https://www.youtube.com/@LechiaGdansk',   'tags' => 'trojmiasto,sport,football' ],
            [ 'platform' => 'youtube', 'handle' => '@ArkaGdyniaOfficial','title' => 'Arka Gdynia',   'url' => 'https://www.youtube.com/@ArkaGdyniaOfficial','tags' => 'trojmiasto,sport,football' ],
            [ 'platform' => 'youtube', 'handle' => '@TVP3Gdansk',     'title' => 'TVP3 Gdańsk',      'url' => 'https://www.youtube.com/@TVP3Gdansk',     'tags' => 'trojmiasto,local,news,tv' ],
            // --- YouTube: общепольские ---
            [ 'platform' => 'youtube', 'handle' => '@tvn24',          'title' => 'TVN24',            'url' => 'https://www.youtube.com/@tvn24',          'tags' => 'national,news' ],
            [ 'platform' => 'youtube', 'handle' => '@OnetNews',       'title' => 'Onet News',        'url' => 'https://www.youtube.com/@OnetNews',       'tags' => 'national,news' ],
            [ 'platform' => 'youtube', 'handle' => '@RMF24',          'title' => 'RMF24',            'url' => 'https://www.youtube.com/@RMF24',          'tags' => 'national,news,radio' ],
            [ 'platform' => 'youtube', 'handle' => '@PolsatNews',     'title' => 'Polsat News',      'url' => 'https://www.youtube.com/@PolsatNews',     'tags' => 'national,news,tv' ],
            [ 'platform' => 'youtube', 'handle' => '@WyborczaTV',     'title' => 'Gazeta Wyborcza',  'url' => 'https://www.youtube.com/@WyborczaTV',     'tags' => 'national,news' ],
            [ 'platform' => 'youtube', 'handle' => '@interiapl',      'title' => 'Interia',          'url' => 'https://www.youtube.com/@interiapl',      'tags' => 'national,news' ],
            [ 'platform' => 'youtube', 'handle' => '@WirtualnaPolska','title' => 'Wirtualna Polska', 'url' => 'https://www.youtube.com/@WirtualnaPolska','tags' => 'national,news' ],

            // --- Telegram: активные каналы о жизни в Польше на ru/uk.
            //
            // Список отфильтрован по реальной активности (по данным прод-базы):
            //   * >=7 постов за последние 7 дней,
            //   * средний охват последних 7 постов > 700 просмотров.
            //
            // Польские СМИ (TVN24_PL, RzeczpospolitaPL, Wiadomosci24, bankier_pl,
            // wyborczapl, rmf24_pl) в TG ведут себя как кладбище: последние
            // посты 2022–2024 годов или единичные просмотры — поэтому в seed
            // их не добавляем. То же для большинства русскоязычных каналов
            // (polandin, polandforus, repatriationeu, rabotawarszawa,
            // partyzanka_rb_pl, ukrinpoland) — активность или охват ниже порога.
            [ 'platform' => 'telegram', 'handle' => 'trojmiast',           'title' => 'Aktualno Trójmiasto (RU)', 'url' => 'https://t.me/s/trojmiast',           'tags' => 'trojmiasto,ru,local,news' ],
            [ 'platform' => 'telegram', 'handle' => 'thewwarsaw',          'title' => 'The Warsaw (RU)',          'url' => 'https://t.me/s/thewwarsaw',          'tags' => 'diaspora,ru,warszawa,news' ],
            [ 'platform' => 'telegram', 'handle' => 'ukrainianinpolandpl', 'title' => 'Ukrainian in Poland (UA)', 'url' => 'https://t.me/s/ukrainianinpolandpl', 'tags' => 'diaspora,ua,news,life' ],
            [ 'platform' => 'telegram', 'handle' => 'yavpolshi',           'title' => 'Я в Польщі / yavp.pl (UA)','url' => 'https://t.me/s/yavpolshi',           'tags' => 'diaspora,ua,news,life' ],
        ];
    }

    /**
     * Идемпотентный seed. Повторный вызов ничего не перетирает благодаря
     * UNIQUE (platform, handle) + INSERT IGNORE.
     */
    public static function seed_defaults(): void {
        global $wpdb;
        $table = Installer::social_accounts_table();
        $now   = time();
        foreach ( self::default_accounts() as $row ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                    (platform, handle, external_id, title, url, tags, enabled, meta, last_checked_ts, created_ts)
                 VALUES (%s, %s, '', %s, %s, %s, 0, NULL, 0, %d)",
                $row['platform'], $row['handle'], $row['title'], $row['url'], $row['tags'], $now
            ) );
        }
    }

    // ---------------------------------------------------------------
    // Accounts CRUD
    // ---------------------------------------------------------------

    /** @return array<int, array<string,mixed>> */
    public static function all_accounts(): array {
        global $wpdb;
        $t = Installer::social_accounts_table();
        $rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY platform, title, handle", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<int, array<string,mixed>> */
    public static function enabled_accounts(): array {
        global $wpdb;
        $t = Installer::social_accounts_table();
        $rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE enabled = 1 ORDER BY platform, handle", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function find_account( int $id ): ?array {
        global $wpdb;
        $t = Installer::social_accounts_table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Добавить новый аккаунт. Возвращает id или WP_Error.
     * @return int|\WP_Error
     */
    public static function add_account( string $platform, string $handle, string $title = '', string $url = '', string $tags = '', bool $enabled = false ) {
        global $wpdb;
        $platform = self::normalize_platform( $platform );
        $handle   = self::normalize_handle( $platform, $handle );
        if ( $platform === '' || $handle === '' ) {
            return new \WP_Error( 'invalid', __( 'Нужно указать платформу и handle.', 'tm-news' ) );
        }
        if ( $url === '' ) {
            $url = self::guess_account_url( $platform, $handle );
        }
        if ( $title === '' ) {
            $title = $handle;
        }
        $t  = Installer::social_accounts_table();
        $ok = $wpdb->insert( $t, [
            'platform'    => $platform,
            'handle'      => $handle,
            'external_id' => '',
            'title'       => $title,
            'url'         => $url,
            'tags'        => $tags,
            'enabled'     => $enabled ? 1 : 0,
            'meta'        => null,
            'last_checked_ts' => 0,
            'created_ts'  => time(),
        ], [ '%s','%s','%s','%s','%s','%s','%d','%s','%d','%d' ] );
        if ( ! $ok ) {
            return new \WP_Error( 'db', __( 'Не удалось сохранить (возможно дубликат).', 'tm-news' ) );
        }
        return (int) $wpdb->insert_id;
    }

    public static function update_account( int $id, array $fields ): bool {
        global $wpdb;
        $t = Installer::social_accounts_table();
        $allowed = [ 'title' => '%s', 'url' => '%s', 'tags' => '%s', 'enabled' => '%d' ];
        $data    = [];
        $fmt     = [];
        foreach ( $allowed as $k => $f ) {
            if ( array_key_exists( $k, $fields ) ) {
                $data[ $k ] = $fields[ $k ];
                $fmt[]      = $f;
            }
        }
        if ( ! $data ) {
            return false;
        }
        return (bool) $wpdb->update( $t, $data, [ 'id' => $id ], $fmt, [ '%d' ] );
    }

    public static function delete_account( int $id ): bool {
        global $wpdb;
        $t = Installer::social_accounts_table();
        // Items/snapshots не удаляем: пусть остаются как архив, но без enabled
        // аккаунта новые fetch не приходят.
        return (bool) $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
    }

    public static function normalize_platform( string $p ): string {
        $p = strtolower( trim( $p ) );
        return in_array( $p, [ self::PLATFORM_YT, self::PLATFORM_TG ], true ) ? $p : '';
    }

    public static function normalize_handle( string $platform, string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return '';
        }
        // Если вставили URL — вытащим username.
        if ( preg_match( '#(?:youtube\.com|youtu\.be)/(@[\w\.\-]+)#i', $raw, $m ) ) {
            return $m[1];
        }
        if ( preg_match( '#(?:t\.me|telegram\.me)/(?:s/)?([A-Za-z0-9_]{3,})#i', $raw, $m ) ) {
            return $m[1];
        }
        if ( $platform === self::PLATFORM_YT ) {
            return ( $raw[0] === '@' ) ? $raw : '@' . ltrim( $raw, '@' );
        }
        if ( $platform === self::PLATFORM_TG ) {
            return ltrim( $raw, '@' );
        }
        return $raw;
    }

    public static function guess_account_url( string $platform, string $handle ): string {
        if ( $handle === '' ) {
            return '';
        }
        if ( $platform === self::PLATFORM_YT ) {
            return 'https://www.youtube.com/' . $handle;
        }
        if ( $platform === self::PLATFORM_TG ) {
            return 'https://t.me/s/' . ltrim( $handle, '@' );
        }
        return '';
    }

    // ---------------------------------------------------------------
    // Pipeline
    // ---------------------------------------------------------------

    /**
     * Один тик сбора: идём по всем enabled аккаунтам, тянем новые посты
     * и обновляем метрики для постов в окне REFRESH_WINDOW.
     *
     * @return array{accounts:int,new_items:int,refreshed:int,errors:int,messages:array<int,string>}
     */
    public static function run_once(): array {
        $stats = [ 'accounts' => 0, 'new_items' => 0, 'refreshed' => 0, 'errors' => 0, 'messages' => [] ];
        $now   = time();
        foreach ( self::enabled_accounts() as $acc ) {
            $stats['accounts']++;
            try {
                if ( $acc['platform'] === self::PLATFORM_YT ) {
                    [ $new, $ref ] = self::fetch_youtube_account( $acc, $now );
                } elseif ( $acc['platform'] === self::PLATFORM_TG ) {
                    [ $new, $ref ] = self::fetch_telegram_account( $acc, $now );
                } else {
                    continue;
                }
                $stats['new_items'] += $new;
                $stats['refreshed'] += $ref;
                self::touch_account( (int) $acc['id'], $now );
            } catch ( \Throwable $e ) {
                $stats['errors']++;
                $stats['messages'][] = sprintf(
                    '[%s %s] %s',
                    $acc['platform'],
                    $acc['handle'],
                    $e->getMessage()
                );
                Logger::warn( 'social fetch failed', [
                    'platform' => $acc['platform'],
                    'handle'   => $acc['handle'],
                    'err'      => $e->getMessage(),
                ] );
            }
        }
        return $stats;
    }

    private static function touch_account( int $id, int $ts ): void {
        global $wpdb;
        $wpdb->update( Installer::social_accounts_table(), [ 'last_checked_ts' => $ts ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
    }

    // ---------------------------------------------------------------
    // YouTube driver
    // ---------------------------------------------------------------

    /**
     * @return array{0:int,1:int}  [new_items, refreshed_items]
     */
    private static function fetch_youtube_account( array $acc, int $now ): array {
        $api_key = (string) get_option( self::OPTION_YT_KEY, '' );
        if ( $api_key === '' ) {
            throw new \RuntimeException( 'YouTube API key not set' );
        }

        $meta = self::decode_meta( $acc['meta'] ?? null );

        // 1) Resolve handle → channelId + uploads playlist (один раз, потом из meta).
        if ( empty( $meta['channel_id'] ) || empty( $meta['uploads_id'] ) ) {
            $resolved = self::yt_resolve_handle( $acc['handle'], $api_key );
            $meta['channel_id'] = $resolved['channel_id'];
            $meta['uploads_id'] = $resolved['uploads_id'];
            if ( $acc['title'] === '' && ! empty( $resolved['title'] ) ) {
                self::update_account( (int) $acc['id'], [ 'title' => $resolved['title'] ] );
            }
            self::save_account_meta( (int) $acc['id'], $meta );
            // обновляем external_id для консистентности
            global $wpdb;
            $wpdb->update(
                Installer::social_accounts_table(),
                [ 'external_id' => $resolved['channel_id'] ],
                [ 'id' => $acc['id'] ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        // 2) Получаем последние видео из uploads playlist.
        $videos = self::yt_list_uploads( $meta['uploads_id'], $api_key, self::PER_ACCOUNT_LIMIT );

        // 3) Определяем, какие id обновлять (новые + уже известные в окне REFRESH_WINDOW).
        $fresh_ids = array_map( static fn( $v ) => $v['video_id'], $videos );

        global $wpdb;
        $items_t = Installer::social_items_table();
        $cutoff  = $now - self::REFRESH_WINDOW;
        $known_in_window = $wpdb->get_col( $wpdb->prepare(
            "SELECT external_id FROM {$items_t}
             WHERE platform = %s AND account_id = %d AND fetched_ts >= %d",
            self::PLATFORM_YT, $acc['id'], $cutoff
        ) );
        $refresh_ids = array_values( array_unique( array_merge( $fresh_ids, (array) $known_in_window ) ) );
        if ( ! $refresh_ids ) {
            return [ 0, 0 ];
        }

        // 4) Запрашиваем статистику батчем (до 50 id на запрос).
        $stats_by_id = [];
        foreach ( array_chunk( $refresh_ids, 50 ) as $chunk ) {
            $data = self::yt_api( 'videos', [
                'part' => 'snippet,statistics',
                'id'   => implode( ',', $chunk ),
            ], $api_key );
            foreach ( $data['items'] ?? [] as $it ) {
                $id = (string) ( $it['id'] ?? '' );
                if ( $id === '' ) {
                    continue;
                }
                $stats_by_id[ $id ] = $it;
            }
        }

        // 5) Апсертим items и пишем snapshots.
        $new = 0; $ref = 0;
        foreach ( $refresh_ids as $vid ) {
            $src = $stats_by_id[ $vid ] ?? null;
            if ( ! $src ) {
                continue;
            }
            $snippet = $src['snippet']   ?? [];
            $stats   = $src['statistics'] ?? [];
            $title   = Normalizer::clean_text( (string) ( $snippet['title'] ?? '' ) );
            $desc    = Normalizer::clean_text( (string) ( $snippet['description'] ?? '' ) );
            if ( mb_strlen( $desc ) > 800 ) {
                $desc = mb_substr( $desc, 0, 800 ) . '…';
            }
            $pub_ts  = strtotime( (string) ( $snippet['publishedAt'] ?? 'now' ) ) ?: $now;
            $views   = (int) ( $stats['viewCount']    ?? 0 );
            $likes   = (int) ( $stats['likeCount']    ?? 0 );
            $cmnts   = (int) ( $stats['commentCount'] ?? 0 );

            $res = self::upsert_item( [
                'account_id'   => (int) $acc['id'],
                'platform'     => self::PLATFORM_YT,
                'external_id'  => $vid,
                'url'          => 'https://www.youtube.com/watch?v=' . $vid,
                'title'        => $title !== '' ? $title : '(untitled)',
                'excerpt'      => $desc,
                'published_ts' => $pub_ts,
                'now'          => $now,
                'views'        => $views,
                'likes'        => $likes,
                'comments'     => $cmnts,
                'shares'       => 0,
                'meta'         => null,
            ] );
            if ( $res === 'new' ) {
                $new++;
            } elseif ( $res === 'updated' ) {
                $ref++;
            }
        }
        return [ $new, $ref ];
    }

    /**
     * @return array{channel_id:string,uploads_id:string,title:string}
     */
    private static function yt_resolve_handle( string $handle, string $api_key ): array {
        $data = self::yt_api( 'channels', [
            'part'      => 'id,snippet,contentDetails',
            'forHandle' => $handle,
        ], $api_key );
        $items = $data['items'] ?? [];
        if ( ! $items ) {
            // Fallback: старый forUsername.
            $clean = ltrim( $handle, '@' );
            $data  = self::yt_api( 'channels', [
                'part'        => 'id,snippet,contentDetails',
                'forUsername' => $clean,
            ], $api_key );
            $items = $data['items'] ?? [];
        }
        if ( ! $items ) {
            throw new \RuntimeException( 'YouTube handle not found: ' . $handle );
        }
        $it = $items[0];
        $cid    = (string) ( $it['id'] ?? '' );
        $uploads = (string) ( $it['contentDetails']['relatedPlaylists']['uploads'] ?? '' );
        $title  = (string) ( $it['snippet']['title'] ?? '' );
        if ( $cid === '' || $uploads === '' ) {
            throw new \RuntimeException( 'YouTube handle resolve incomplete: ' . $handle );
        }
        return [ 'channel_id' => $cid, 'uploads_id' => $uploads, 'title' => $title ];
    }

    /**
     * @return list<array{video_id:string,title:string,published_at:string}>
     */
    private static function yt_list_uploads( string $uploads_id, string $api_key, int $limit ): array {
        $data = self::yt_api( 'playlistItems', [
            'part'       => 'snippet,contentDetails',
            'playlistId' => $uploads_id,
            'maxResults' => max( 1, min( 50, $limit ) ),
        ], $api_key );
        $out = [];
        foreach ( $data['items'] ?? [] as $it ) {
            $vid = (string) ( $it['contentDetails']['videoId'] ?? ( $it['snippet']['resourceId']['videoId'] ?? '' ) );
            if ( $vid === '' ) {
                continue;
            }
            $out[] = [
                'video_id'     => $vid,
                'title'        => (string) ( $it['snippet']['title'] ?? '' ),
                'published_at' => (string) ( $it['snippet']['publishedAt'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Общий обёртчик YouTube Data API v3.
     *
     * @param array<string,string|int> $params
     * @return array<string,mixed>
     */
    private static function yt_api( string $endpoint, array $params, string $api_key ): array {
        $params['key'] = $api_key;
        $url = 'https://www.googleapis.com/youtube/v3/' . $endpoint . '?' . http_build_query( $params );
        $body = self::http_get( $url, [ 'Accept' => 'application/json' ] );
        $data = json_decode( (string) $body, true );
        if ( ! is_array( $data ) ) {
            throw new \RuntimeException( 'YouTube API: invalid JSON' );
        }
        if ( isset( $data['error'] ) ) {
            $msg = (string) ( $data['error']['message'] ?? 'unknown' );
            throw new \RuntimeException( 'YouTube API: ' . $msg );
        }
        return $data;
    }

    // ---------------------------------------------------------------
    // Telegram driver (публичный t.me/s/)
    // ---------------------------------------------------------------

    /**
     * @return array{0:int,1:int}  [new_items, refreshed_items]
     */
    private static function fetch_telegram_account( array $acc, int $now ): array {
        $handle = ltrim( (string) $acc['handle'], '@' );
        if ( $handle === '' ) {
            throw new \RuntimeException( 'empty telegram handle' );
        }
        $url  = 'https://t.me/s/' . $handle;
        $html = self::http_get( $url, [
            // UA обычного десктопного браузера, чтобы TG отдал HTML-превью, а не redirect.
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept-Language' => 'pl,en;q=0.8',
        ] );
        if ( $html === null || $html === '' ) {
            throw new \RuntimeException( 'empty HTML from ' . $url );
        }
        $messages = self::tg_parse_messages( $html );
        if ( ! $messages ) {
            // t.me/s/<handle> возвращает 200 с «Channel not found» и без сообщений.
            throw new \RuntimeException( 'no messages parsed (handle may not exist or channel is private)' );
        }

        // external_id для TG совпадает с handle — сохраним если пусто.
        if ( empty( $acc['external_id'] ) ) {
            global $wpdb;
            $wpdb->update(
                Installer::social_accounts_table(),
                [ 'external_id' => $handle ],
                [ 'id' => $acc['id'] ],
                [ '%s' ], [ '%d' ]
            );
        }

        $new = 0; $ref = 0;
        foreach ( $messages as $m ) {
            // external_id = "<channel>/<msg_id>" — гарантирует уникальность между каналами.
            $ext = $handle . '/' . $m['msg_id'];
            $res = self::upsert_item( [
                'account_id'   => (int) $acc['id'],
                'platform'     => self::PLATFORM_TG,
                'external_id'  => $ext,
                'url'          => 'https://t.me/' . $handle . '/' . $m['msg_id'],
                'title'        => $m['title'],
                'excerpt'      => $m['excerpt'],
                'published_ts' => $m['published_ts'],
                'now'          => $now,
                'views'        => $m['views'],
                'likes'        => $m['reactions'],
                'comments'     => $m['comments'],
                'shares'       => $m['forwards'],
                'meta'         => null,
            ] );
            if ( $res === 'new' ) {
                $new++;
            } elseif ( $res === 'updated' ) {
                $ref++;
            }
        }
        return [ $new, $ref ];
    }

    /**
     * Парсер HTML превью Telegram-канала (t.me/s/<channel>).
     *
     * @return list<array{msg_id:string,title:string,excerpt:string,published_ts:int,views:int,reactions:int,comments:int,forwards:int}>
     */
    private static function tg_parse_messages( string $html ): array {
        $prev = libxml_use_internal_errors( true );
        $dom  = new \DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $xpath = new \DOMXPath( $dom );
        $nodes = $xpath->query( '//div[@data-post and contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message ")]' );
        if ( ! $nodes || $nodes->length === 0 ) {
            return [];
        }

        $out = [];
        foreach ( $nodes as $node ) {
            /** @var \DOMElement $node */
            $data_post = (string) $node->getAttribute( 'data-post' );
            $parts = explode( '/', $data_post );
            $msg_id = (string) ( end( $parts ) ?: '' );
            if ( $msg_id === '' || ! ctype_digit( $msg_id ) ) {
                continue;
            }

            $text_el = $xpath->query( './/div[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_text ")]', $node )->item( 0 );
            $raw_text = $text_el ? trim( $text_el->textContent ) : '';
            $title   = Normalizer::clean_text( mb_substr( $raw_text, 0, 160 ) );
            $excerpt = Normalizer::clean_text( mb_substr( $raw_text, 0, 800 ) );

            $time_el = $xpath->query( './/a[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_date ")]//time', $node )->item( 0 );
            $dt      = $time_el ? (string) $time_el->getAttribute( 'datetime' ) : '';
            $pub_ts  = $dt ? ( strtotime( $dt ) ?: 0 ) : 0;

            $views_el = $xpath->query( './/span[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_views ")]', $node )->item( 0 );
            $views    = $views_el ? self::parse_compact_number( $views_el->textContent ) : 0;

            // Реакции: каждая лежит в <span class="tgme_reaction"> с текстом-числом
            // (например «85.5K»). Внутри могут быть <tg-emoji>/<i> — они без
            // текстовой ноды, поэтому textContent спана даёт только число.
            $reactions = 0;
            $r_nodes = $xpath->query( './/span[contains(concat(" ",normalize-space(@class)," ")," tgme_reaction ")]', $node );
            if ( $r_nodes ) {
                foreach ( $r_nodes as $r ) {
                    $reactions += self::parse_compact_number( $r->textContent );
                }
            }

            $comments = 0;
            $c_el = $xpath->query( './/a[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_reply_count ")]', $node )->item( 0 );
            if ( $c_el ) {
                $comments = self::parse_compact_number( $c_el->textContent );
            }

            // forwards — не всегда доступны в превью, оставляем 0.
            $forwards = 0;

            if ( $title === '' ) {
                $title = 'TG ' . $msg_id;
            }

            $out[] = [
                'msg_id'       => $msg_id,
                'title'        => $title,
                'excerpt'      => $excerpt,
                'published_ts' => $pub_ts ?: time(),
                'views'        => $views,
                'reactions'    => $reactions,
                'comments'     => $comments,
                'forwards'     => $forwards,
            ];
        }
        return $out;
    }

    /** «1.2K» → 1200, «5,6M» → 5600000, «567» → 567. */
    public static function parse_compact_number( string $s ): int {
        $s = trim( $s );
        if ( $s === '' ) {
            return 0;
        }
        if ( ! preg_match( '/([0-9]+(?:[\.,][0-9]+)?)\s*([KMB])?/iu', $s, $m ) ) {
            return 0;
        }
        $num  = (float) str_replace( ',', '.', $m[1] );
        $mult = 1;
        if ( isset( $m[2] ) ) {
            switch ( strtoupper( $m[2] ) ) {
                case 'K': $mult = 1000; break;
                case 'M': $mult = 1000000; break;
                case 'B': $mult = 1000000000; break;
            }
        }
        return (int) round( $num * $mult );
    }

    // ---------------------------------------------------------------
    // Common: upsert + snapshot
    // ---------------------------------------------------------------

    /**
     * Апсерт item + пишем snapshot, если метрики изменились или снимок старше 10 мин.
     *
     * @param array<string,mixed> $row
     * @return 'new'|'updated'|'noop'
     */
    private static function upsert_item( array $row ): string {
        global $wpdb;
        $t_items = Installer::social_items_table();
        $t_snaps = Installer::social_snapshots_table();

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t_items} WHERE platform = %s AND external_id = %s",
            $row['platform'], $row['external_id']
        ), ARRAY_A );

        $now = (int) $row['now'];
        if ( ! $existing ) {
            $wpdb->insert( $t_items, [
                'account_id'     => $row['account_id'],
                'platform'       => $row['platform'],
                'external_id'    => $row['external_id'],
                'url'            => $row['url'],
                'title'          => $row['title'],
                'excerpt'        => $row['excerpt'],
                'published_ts'   => $row['published_ts'],
                'fetched_ts'     => $now,
                'updated_ts'     => $now,
                'views_count'    => $row['views'],
                'likes_count'    => $row['likes'],
                'comments_count' => $row['comments'],
                'shares_count'   => $row['shares'],
                'meta'           => $row['meta'],
            ], [ '%d','%s','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d','%d','%s' ] );
            $item_id = (int) $wpdb->insert_id;
            self::insert_snapshot( $item_id, $now, $row );
            return 'new';
        }

        $item_id = (int) $existing['id'];
        $changed = (
            (int) $existing['views_count']    !== (int) $row['views'] ||
            (int) $existing['likes_count']    !== (int) $row['likes'] ||
            (int) $existing['comments_count'] !== (int) $row['comments'] ||
            (int) $existing['shares_count']   !== (int) $row['shares']
        );

        $wpdb->update( $t_items, [
            'views_count'    => $row['views'],
            'likes_count'    => $row['likes'],
            'comments_count' => $row['comments'],
            'shares_count'   => $row['shares'],
            'updated_ts'     => $now,
            'title'          => $row['title'],
            'excerpt'        => $row['excerpt'],
        ], [ 'id' => $item_id ],
        [ '%d','%d','%d','%d','%d','%s','%s' ],
        [ '%d' ] );

        // Snapshot пишем только если реально что-то изменилось
        // или прошло достаточно времени с прошлого снимка (избегаем дублей).
        $last_ts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(snapshot_ts) FROM {$t_snaps} WHERE item_id = %d",
            $item_id
        ) );
        if ( $changed || ( $now - $last_ts ) >= 600 ) {
            self::insert_snapshot( $item_id, $now, $row );
        }
        return $changed ? 'updated' : 'noop';
    }

    private static function insert_snapshot( int $item_id, int $ts, array $row ): void {
        global $wpdb;
        $wpdb->insert( Installer::social_snapshots_table(), [
            'item_id'        => $item_id,
            'snapshot_ts'    => $ts,
            'views_count'    => $row['views'],
            'likes_count'    => $row['likes'],
            'comments_count' => $row['comments'],
            'shares_count'   => $row['shares'],
        ], [ '%d','%d','%d','%d','%d','%d' ] );
    }

    // ---------------------------------------------------------------
    // Velocity
    // ---------------------------------------------------------------

    /**
     * Скорость по метрике за последний час-два: Δmetric / Δhours, усреднённо
     * по двум последним снэпшотам item'а. Если снимков < 2 — 0.
     *
     * @return array{views:float,likes:float,comments:float}
     */
    public static function velocity_for_item( int $item_id ): array {
        global $wpdb;
        $t = Installer::social_snapshots_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT snapshot_ts, views_count, likes_count, comments_count
             FROM {$t}
             WHERE item_id = %d
             ORDER BY snapshot_ts DESC
             LIMIT 2",
            $item_id
        ), ARRAY_A );
        if ( ! $rows || count( $rows ) < 2 ) {
            return [ 'views' => 0.0, 'likes' => 0.0, 'comments' => 0.0 ];
        }
        [ $latest, $prev ] = $rows;
        $dt_sec = max( 1, (int) $latest['snapshot_ts'] - (int) $prev['snapshot_ts'] );
        $dt_h   = $dt_sec / 3600;
        return [
            'views'    => max( 0.0, ( (int) $latest['views_count']    - (int) $prev['views_count'] )    / $dt_h ),
            'likes'    => max( 0.0, ( (int) $latest['likes_count']    - (int) $prev['likes_count'] )    / $dt_h ),
            'comments' => max( 0.0, ( (int) $latest['comments_count'] - (int) $prev['comments_count'] ) / $dt_h ),
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function http_get( string $url, array $headers = [] ): ?string {
        $default_headers = [
            'User-Agent' => 'Mozilla/5.0 (compatible; tm-news/0.5; +https://trojmiasto.online)',
        ];
        $res = wp_remote_get( $url, [
            'timeout'     => self::HTTP_TIMEOUT,
            'redirection' => 3,
            'headers'     => array_merge( $default_headers, $headers ),
        ] );
        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( 'HTTP error: ' . $res->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code >= 400 ) {
            throw new \RuntimeException( 'HTTP ' . $code . ' on ' . $url );
        }
        return (string) wp_remote_retrieve_body( $res );
    }

    private static function decode_meta( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $d = json_decode( $raw, true );
            return is_array( $d ) ? $d : [];
        }
        return [];
    }

    private static function save_account_meta( int $id, array $meta ): void {
        global $wpdb;
        $wpdb->update(
            Installer::social_accounts_table(),
            [ 'meta' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
    }

    /** Добавляет кастомный cron-интервал 30 мин. Вызывать из фильтра cron_schedules. */
    public static function register_cron_schedule( array $schedules ): array {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = [
                'interval' => 1800,
                'display'  => __( 'Every 30 minutes (tm-social)', 'tm-news' ),
            ];
        }
        return $schedules;
    }
}

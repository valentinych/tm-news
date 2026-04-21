<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Реестр источников: дефолты на момент установки + редактируется в админке.
 *
 * Структура одного источника:
 *  - name    (str)   — отображаемое имя
 *  - url     (str)   — RSS endpoint
 *  - weight  (float) — 0..1, чем больше — тем сильнее источник влияет на hotness
 *  - enabled (bool)
 */
final class Sources {

    public const OPTION = 'tm_news_sources';

    /** @return array<string, array{name:string,url:string,weight:float,enabled:bool}> */
    public static function defaults(): array {
        return [
            // --- Рабочие, проверено ---
            'trojmiasto_pl' => [
                'name'    => 'Trojmiasto.pl',
                'url'     => 'https://www.trojmiasto.pl/rss/news.xml',
                'weight'  => 1.0,
                'enabled' => true,
            ],
            'radiogdansk' => [
                'name'    => 'Radio Gdańsk',
                'url'     => 'https://radiogdansk.pl/feed',
                'weight'  => 0.9,
                'enabled' => true,
            ],
            'gdynia_pl' => [
                'name'    => 'Gdynia.pl (oficjalny)',
                'url'     => 'https://www.gdynia.pl/rss',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'pomorskie_eu' => [
                'name'    => 'Pomorskie.eu (Urząd Marszałkowski)',
                'url'     => 'https://www.pomorskie.eu/feed',
                'weight'  => 0.6,
                'enabled' => true,
            ],
            'expressbiznesu' => [
                'name'    => 'Express Biznesu',
                'url'     => 'https://expressbiznesu.pl/feed',
                'weight'  => 0.5,
                'enabled' => true,
            ],

            // --- Отключены по умолчанию: 403 anti-bot или нет стабильного RSS.
            // Оставлены в списке, чтобы в админке можно было попробовать другой URL
            // (например, через прокси или новый путь, если редакция его откроет).
            'dziennikbaltycki' => [
                'name'    => 'Dziennik Bałtycki',
                'url'     => 'https://dziennikbaltycki.pl/rss.xml',
                'weight'  => 0.85,
                'enabled' => false,
            ],
            'naszemiasto_gdansk' => [
                'name'    => 'Nasze Miasto Gdańsk',
                'url'     => 'https://gdansk.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => false,
            ],
            'naszemiasto_gdynia' => [
                'name'    => 'Nasze Miasto Gdynia',
                'url'     => 'https://gdynia.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => false,
            ],
            'naszemiasto_sopot' => [
                'name'    => 'Nasze Miasto Sopot',
                'url'     => 'https://sopot.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => false,
            ],
            'zawszepomorze' => [
                'name'    => 'Zawsze Pomorze',
                'url'     => 'https://zawszepomorze.pl/feed/',
                'weight'  => 0.6,
                'enabled' => false,
            ],
            'gdansk_pl' => [
                'name'    => 'Gdańsk.pl (oficjalny)',
                'url'     => 'https://www.gdansk.pl/rss/aktualnosci.xml',
                'weight'  => 0.7,
                'enabled' => false,
            ],
            'sopot_pl' => [
                'name'    => 'Sopot.pl (oficjalny)',
                'url'     => 'https://www.sopot.pl/rss',
                'weight'  => 0.6,
                'enabled' => false,
            ],

            // --- Общепольские мейнстрим-СМИ. Отключены по умолчанию:
            // контент чаще не про Труймясто, topic_match задавит их score
            // до 0.3 × recency × weight — но иногда событие гремит по всей
            // Польше, и они дают ценный ранний сигнал.
            //
            // TVN24, Polskie Radio и PAP не добавляем: у TVN24 Cloudflare/WAF
            // отвечает 403 на серверный трафик из не-польских IP, у Polskie
            // Radio и PAP нет публичного рабочего RSS.
            'onet_wiadomosci' => [
                'name'    => 'Onet Wiadomości',
                'url'     => 'https://wiadomosci.onet.pl/.feed',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'interia_fakty' => [
                'name'    => 'Interia Fakty',
                'url'     => 'https://fakty.interia.pl/feed',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'wp_wiadomosci' => [
                'name'    => 'WP Wiadomości',
                'url'     => 'https://wiadomosci.wp.pl/rss.xml',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'rmf24' => [
                'name'    => 'RMF24',
                'url'     => 'https://www.rmf24.pl/fakty/feed',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'gazeta_pl' => [
                'name'    => 'Gazeta.pl',
                'url'     => 'https://wiadomosci.gazeta.pl/pub/rss/wiadomosci.xml',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'polsat_news' => [
                'name'    => 'Polsat News',
                'url'     => 'https://www.polsatnews.pl/rss/wszystkie.xml',
                'weight'  => 0.3,
                'enabled' => false,
            ],
        ];
    }

    /**
     * Одноразовая правка опции, добавленной в v2: часть URL оказалась
     * битой / заблокированной. Меняем/удаляем **только** если источник
     * всё ещё отключён И URL совпадает с тем самым битым дефолтом, —
     * т.е. пользователь ничего с ним не делал.
     *
     * Возвращает [changed, removed] для лога.
     *
     * @return array{0:int,1:int}
     */
    public static function cleanup_broken_v2_defaults(): array {
        $fixes = [
            // ключ => [ старый битый URL, новый URL | null => удалить ]
            'onet_wiadomosci' => [ 'https://wiadomosci.onet.pl/feed',              'https://wiadomosci.onet.pl/.feed' ],
            'polsat_news'     => [ 'https://www.polsatnews.pl/rss/polska',         'https://www.polsatnews.pl/rss/wszystkie.xml' ],
            'tvn24'           => [ 'https://tvn24.pl/najnowsze.xml',               null ],
            'polskie_radio'   => [ 'https://polskieradio24.pl/rss',                null ],
            'pap'             => [ 'https://www.pap.pl/kraj/rss.xml',              null ],
        ];

        $current = get_option( self::OPTION, [] );
        if ( ! is_array( $current ) ) {
            return [ 0, 0 ];
        }
        $changed = 0;
        $removed = 0;
        foreach ( $fixes as $key => [ $old_url, $new_url ] ) {
            if ( ! isset( $current[ $key ] ) ) {
                continue;
            }
            $src = $current[ $key ];
            if ( ! empty( $src['enabled'] ) ) {
                continue; // пользователь включил — не трогаем.
            }
            if ( (string) ( $src['url'] ?? '' ) !== $old_url ) {
                continue; // URL уже кастомный — не трогаем.
            }
            if ( $new_url === null ) {
                unset( $current[ $key ] );
                $removed++;
            } else {
                $current[ $key ]['url'] = $new_url;
                $changed++;
            }
        }
        if ( $changed || $removed ) {
            update_option( self::OPTION, $current, false );
        }
        return [ $changed, $removed ];
    }

    /**
     * Мёрджит новые ключи дефолтов в сохранённую опцию. Не перезаписывает
     * уже существующие источники (сохраняет weight/enabled/URL пользователя).
     *
     * Возвращает количество добавленных новых источников.
     */
    public static function merge_new_defaults(): int {
        $current = get_option( self::OPTION, [] );
        if ( ! is_array( $current ) ) {
            $current = [];
        }
        $added = 0;
        foreach ( self::defaults() as $key => $src ) {
            if ( ! isset( $current[ $key ] ) ) {
                $current[ $key ] = $src;
                $added++;
            }
        }
        if ( $added > 0 ) {
            update_option( self::OPTION, $current, false );
        }
        return $added;
    }

    public static function seed_defaults(): void {
        if ( get_option( self::OPTION ) === false ) {
            update_option( self::OPTION, self::defaults(), false );
        }
    }

    /** @return array<string, array{name:string,url:string,weight:float,enabled:bool}> */
    public static function all(): array {
        $stored = get_option( self::OPTION, [] );
        if ( ! is_array( $stored ) || ! $stored ) {
            return self::defaults();
        }
        return $stored;
    }

    /** @return array<string, array{name:string,url:string,weight:float,enabled:bool}> */
    public static function enabled(): array {
        return array_filter( self::all(), static fn( $s ) => ! empty( $s['enabled'] ) );
    }

    public static function get( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    public static function update( array $sources ): void {
        update_option( self::OPTION, $sources, false );
    }
}

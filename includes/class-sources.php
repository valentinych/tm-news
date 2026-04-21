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
            'tvn24' => [
                'name'    => 'TVN24',
                'url'     => 'https://tvn24.pl/najnowsze.xml',
                'weight'  => 0.5,
                'enabled' => false,
            ],
            'onet_wiadomosci' => [
                'name'    => 'Onet Wiadomości',
                'url'     => 'https://wiadomosci.onet.pl/feed',
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
            'polskie_radio' => [
                'name'    => 'Polskie Radio 24',
                'url'     => 'https://polskieradio24.pl/rss',
                'weight'  => 0.4,
                'enabled' => false,
            ],
            'polsat_news' => [
                'name'    => 'Polsat News',
                'url'     => 'https://www.polsatnews.pl/rss/polska',
                'weight'  => 0.3,
                'enabled' => false,
            ],
            'pap' => [
                'name'    => 'PAP (Polska Agencja Prasowa)',
                'url'     => 'https://www.pap.pl/kraj/rss.xml',
                'weight'  => 0.6,
                'enabled' => false,
            ],
        ];
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

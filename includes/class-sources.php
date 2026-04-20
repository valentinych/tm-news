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
            'trojmiasto_pl' => [
                'name'    => 'Trojmiasto.pl',
                'url'     => 'https://www.trojmiasto.pl/rss/Trojmiasto-nsz-2.xml',
                'weight'  => 1.0,
                'enabled' => true,
            ],
            'naszemiasto_gdansk' => [
                'name'    => 'Nasze Miasto Gdańsk',
                'url'     => 'https://gdansk.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'naszemiasto_gdynia' => [
                'name'    => 'Nasze Miasto Gdynia',
                'url'     => 'https://gdynia.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'naszemiasto_sopot' => [
                'name'    => 'Nasze Miasto Sopot',
                'url'     => 'https://sopot.naszemiasto.pl/rss.xml',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'dziennikbaltycki' => [
                'name'    => 'Dziennik Bałtycki',
                'url'     => 'https://dziennikbaltycki.pl/rss.xml',
                'weight'  => 0.85,
                'enabled' => true,
            ],
            'radiogdansk' => [
                'name'    => 'Radio Gdańsk',
                'url'     => 'https://radiogdansk.pl/feed',
                'weight'  => 0.9,
                'enabled' => true,
            ],
            'zawszepomorze' => [
                'name'    => 'Zawsze Pomorze',
                'url'     => 'https://zawszepomorze.pl/feed/',
                'weight'  => 0.6,
                'enabled' => true,
            ],
            'gdansk_pl' => [
                'name'    => 'Gdańsk.pl (oficjalny)',
                'url'     => 'https://www.gdansk.pl/rss/aktualnosci.xml',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'gdynia_pl' => [
                'name'    => 'Gdynia.pl (oficjalny)',
                'url'     => 'https://www.gdynia.pl/rss',
                'weight'  => 0.7,
                'enabled' => true,
            ],
            'sopot_pl' => [
                'name'    => 'Sopot.pl (oficjalny)',
                'url'     => 'https://www.sopot.pl/rss',
                'weight'  => 0.6,
                'enabled' => false,
            ],
        ];
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

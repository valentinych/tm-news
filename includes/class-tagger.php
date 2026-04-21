<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Эвристический теггер по заголовку+лиду айтема.
 * Ищет подстроки (lowercase) и ставит русскоязычные метки темы.
 *
 * Намеренно очень простой: это не ML, а подсказка редактору.
 * Словарь легко расширить; если станет тесно — вынесем в опции.
 */
final class Tagger {

    /** @var array<string,string[]> Тег → список подстрок (lowercase) для поиска в title+excerpt. */
    private const DICT = [
        'Гданьск'      => [ 'gdańsk', 'gdansk', 'gdańsku', 'gdańskie' ],
        'Гдыня'        => [ 'gdyni', 'gdynia' ],
        'Сопот'        => [ 'sopoc', 'sopot' ],
        'Транспорт'    => [ 'pkm', 'skm', 'ztm', 'mevo', 'autobus', 'tramwaj', 'pociąg', 'dworzec', 'lotnisko', 'rower', 'tramwajow' ],
        'Происшествия' => [ 'policj', 'wypadek', 'pożar', 'ranion', 'zatrzyman', 'śledz', 'kradzież', 'napa', 'ofiar', 'oszust' ],
        'Афиша'        => [ 'koncert', 'festiwal', 'wystawa', 'spektakl', 'teatr', 'kino', 'premier' ],
        'Спорт'        => [ 'lechia', 'arka gdyni', 'ergo arena', 'stadion', 'mecz', 'puchar', 'bramk' ],
        'Погода'       => [ 'pogod', 'sztorm', 'burza', 'ostrzeż', 'deszcz', 'śnieg', 'upał', 'mróz' ],
        'Бизнес'       => [ 'biznes', 'firma', 'inwestyc', 'budżet', 'gospodark', 'sprzedaż' ],
        'Политика'     => [ 'prezydent', 'rada miasta', 'radny', 'wybor', 'rząd', 'minist' ],
        'Образование'  => [ 'szkol', 'uniwersytet', 'student', 'maturzyst', 'przedszkol' ],
        'Здоровье'     => [ 'szpital', 'choro', 'pacjent', 'lekarz', 'pielęg' ],
    ];

    /**
     * @return string[] Найденные теги (в порядке словаря, без дубликатов).
     */
    public static function detect( string $title, string $excerpt = '' ): array {
        $text = mb_strtolower( $title . ' ' . $excerpt );
        $hits = [];
        foreach ( self::DICT as $tag => $needles ) {
            foreach ( $needles as $n ) {
                if ( $n !== '' && str_contains( $text, $n ) ) {
                    $hits[] = $tag;
                    continue 2;
                }
            }
        }
        return $hits;
    }
}

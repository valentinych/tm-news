<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Утилиты нормализации: канонический URL, чистка HTML, токенизация польского заголовка
 * для дешёвой Jaccard-кластеризации.
 */
final class Normalizer {

    /** Параметры из URL, которые рубим перед хешированием. */
    private const STRIP_QUERY = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'source',
    ];

    /** Стоп-слова польского — выкидываем при шинглах. */
    private const PL_STOPWORDS = [
        'a','aby','ach','acz','aczkolwiek','aj','albo','ale','alez','ależ','ani','az','aż','bardziej','bardzo',
        'bez','bo','bowiem','by','byc','być','byl','byla','bylo','byly','bym','bys','byś','byly','być','całkiem',
        'chce','chyba','ci','cie','cię','ciebie','co','cokolwiek','cos','coś','czasami','czasem','czemu','czy',
        'czyli','daleko','dla','dlaczego','dlatego','do','dobrze','dokąd','dosc','dość','dużo','dwa','dwaj','dwie',
        'dwoje','dziś','dzisiaj','gdy','gdyby','gdyż','gdzie','gdziekolwiek','go','i','ich','ile','im','inna','inne',
        'inny','innych','iz','iż','ja','ją','jak','jakas','jakaś','jakby','jaki','jakichs','jakichś','jakie','jakis',
        'jakiś','jakkolwiek','jako','jakoś','je','jeden','jedna','jednak','jednakze','jednakże','jedno','jednym','jego',
        'jej','jemu','jest','jestem','jeszcze','jezeli','jeżeli','jesli','jeśli','juz','już','kazdy','każdy','kiedy',
        'kierunku','kilka','kims','kimś','kto','ktokolwiek','ktora','która','które','którego','której','któremu',
        'którym','którzy','ku','lat','lecz','lub','ma','maja','mają','mam','mi','między','mimo','mna','mną','mnie',
        'moga','mogą','moi','moim','moja','moje','moze','może','mozliwe','możliwe','mu','musi','my','na','nad','nam',
        'nami','nas','nasi','nasz','nasza','nasze','naszego','naszych','natomiast','natychmiast','nawet','nia','nią',
        'nic','nich','nie','niech','niej','niemu','nigdy','nim','nimi','niz','niż','no','o','obok','od','około','on',
        'ona','one','oni','ono','oraz','oto','owszem','pan','pana','pani','po','pod','podczas','pomimo','ponad',
        'poniewaz','ponieważ','powinien','powinna','powinni','powinno','poza','prawie','przeciez','przecież','przed',
        'przede','przedtem','przez','przy','roku','rowniez','również','sam','sama','są','się','skad','skąd','soba',
        'sobą','sobie','sposob','sposób','swoje','ta','tak','taka','taki','takie','takze','także','tam','te','tego',
        'tej','temu','ten','teraz','tez','też','to','toba','tobą','tobie','totez','toteż','tu','tutaj','twoi','twoim',
        'twoja','twoje','twym','ty','tych','tylko','tym','u','w','wam','wami','was','wasi','wasz','wasza','wasze',
        'we','według','wiele','wielu','więc','wsród','wszelkich','wszystkich','wszystko','wtedy','wy','z','za','zaden',
        'żaden','zadna','żadna','zadne','żadne','zadnych','żadnych','zapewne','zawsze','ze','że','zeby','żeby','zeznała',
        'zł','znow','znów','znowu','zostal','został','zostala','została','zostali','zostalo','zostało','zostały',
    ];

    public static function canonical_url( string $url ): string {
        $url = trim( $url );
        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['host'] ) ) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host   = strtolower( $parts['host'] );
        $path   = $parts['path'] ?? '';
        $path   = rtrim( $path, '/' );
        if ( $path === '' ) {
            $path = '/';
        }

        $query = '';
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $args );
            foreach ( self::STRIP_QUERY as $bad ) {
                unset( $args[ $bad ] );
            }
            if ( $args ) {
                ksort( $args );
                $query = '?' . http_build_query( $args );
            }
        }
        return $scheme . '://' . $host . $path . $query;
    }

    public static function url_hash( string $url ): string {
        return sha1( self::canonical_url( $url ) );
    }

    public static function clean_text( string $html ): string {
        $text = wp_strip_all_tags( $html, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
        return trim( $text );
    }

    /** Нормализованный заголовок: lowercase, без пунктуации, без стоп-слов. */
    public static function normalize_title( string $title ): string {
        $t = mb_strtolower( $title, 'UTF-8' );
        $t = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $t ) ?? $t;
        $t = preg_replace( '/\s+/u', ' ', $t ) ?? $t;
        $t = trim( $t );
        $tokens = array_filter(
            explode( ' ', $t ),
            static fn( $w ) => $w !== '' && mb_strlen( $w ) > 2 && ! in_array( $w, self::PL_STOPWORDS, true )
        );
        return implode( ' ', $tokens );
    }

    /** Множество токенов для Jaccard-сравнения (уникальные слова нормализованного заголовка). */
    public static function token_set( string $normalized_title ): array {
        $tokens = array_filter( explode( ' ', $normalized_title ), static fn( $t ) => $t !== '' );
        return array_values( array_unique( $tokens ) );
    }

    public static function jaccard( array $a, array $b ): float {
        if ( ! $a || ! $b ) {
            return 0.0;
        }
        $a = array_unique( $a );
        $b = array_unique( $b );
        $inter = array_intersect( $a, $b );
        $union = array_unique( array_merge( $a, $b ) );
        return count( $union ) === 0 ? 0.0 : count( $inter ) / count( $union );
    }
}

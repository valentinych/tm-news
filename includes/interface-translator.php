<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Translator {

    /**
     * Возвращает локализованный рерайт.
     *
     * @param string $title_pl
     * @param string $excerpt_pl
     * @param string $source_url
     * @return array{
     *   title_ru:string, summary_ru:string,
     *   title_uk:string, summary_uk:string
     * }
     *
     * @throws \RuntimeException при ошибке API
     */
    public function rewrite( string $title_pl, string $excerpt_pl, string $source_url ): array;
}

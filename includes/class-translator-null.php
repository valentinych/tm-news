<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Заглушка: используется в dry-run, если ключ OpenAI не задан.
 * Просто отдаёт польский заголовок/лид без изменений.
 */
final class Translator_Null implements Translator {

    public function rewrite( string $title_pl, string $excerpt_pl, string $source_url ): array {
        return [
            'title_ru'   => $title_pl,
            'summary_ru' => $excerpt_pl,
            'title_uk'   => $title_pl,
            'summary_uk' => $excerpt_pl,
        ];
    }
}

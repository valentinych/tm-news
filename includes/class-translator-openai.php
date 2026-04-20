<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Реализация Translator через OpenAI Chat Completions (JSON-режим).
 * Ключ и модель хранятся в опциях плагина.
 */
final class Translator_OpenAI implements Translator {

    public const OPTION_KEY    = 'tm_news_openai_api_key';
    public const OPTION_MODEL  = 'tm_news_openai_model';
    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public function __construct( private string $api_key, private string $model ) {
    }

    public static function from_options(): ?self {
        $key = (string) get_option( self::OPTION_KEY, '' );
        if ( $key === '' ) {
            return null;
        }
        $model = (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
        return new self( $key, $model ?: self::DEFAULT_MODEL );
    }

    public function rewrite( string $title_pl, string $excerpt_pl, string $source_url ): array {
        $system = 'Ты — редактор русско- и украиноязычного новостного портала о Труймясте '
                . '(Гданьск, Гдыня, Сопот). Ты получаешь польский заголовок и лид новости и '
                . 'делаешь СВОИМИ СЛОВАМИ короткое резюме на русском и украинском: 2–3 '
                . 'предложения, нейтральный тон, без домыслов, без markdown, без эмодзи. '
                . 'Передавай суть факта, а не копируй формулировки. Топонимы: Гданьск/Гданськ, '
                . 'Гдыня/Гдиня, Сопот/Сопот. Верни строго JSON по заданной схеме.';

        $user = wp_json_encode( [
            'source_url' => $source_url,
            'title_pl'   => $title_pl,
            'lead_pl'    => $excerpt_pl,
            'schema'     => [
                'title_ru'   => 'строка, переведённый/перефразированный заголовок по-русски, до 90 символов',
                'summary_ru' => 'строка, 2–3 предложения на русском, до 350 символов',
                'title_uk'   => 'строка, переведённый/перефразированный заголовок на украинском, до 90 символов',
                'summary_uk' => 'строка, 2–3 предложения на украинском, до 350 символов',
            ],
        ], JSON_UNESCAPED_UNICODE );

        $payload = [
            'model'           => $this->model,
            'temperature'     => 0.3,
            'response_format' => [ 'type' => 'json_object' ],
            'messages'        => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user   ],
            ],
        ];

        $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 45,
        ] );

        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( 'OpenAI HTTP error: ' . $res->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );
        if ( $code !== 200 ) {
            throw new \RuntimeException( "OpenAI HTTP {$code}: " . mb_substr( $body, 0, 500 ) );
        }

        $decoded = json_decode( $body, true );
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if ( ! is_string( $content ) || $content === '' ) {
            throw new \RuntimeException( 'OpenAI: empty content' );
        }
        $data = json_decode( $content, true );
        if ( ! is_array( $data ) ) {
            throw new \RuntimeException( 'OpenAI: content is not JSON: ' . mb_substr( $content, 0, 200 ) );
        }

        foreach ( [ 'title_ru', 'summary_ru', 'title_uk', 'summary_uk' ] as $k ) {
            if ( empty( $data[ $k ] ) || ! is_string( $data[ $k ] ) ) {
                throw new \RuntimeException( "OpenAI: missing field {$k}" );
            }
        }

        return [
            'title_ru'   => trim( $data['title_ru'] ),
            'summary_ru' => trim( $data['summary_ru'] ),
            'title_uk'   => trim( $data['title_uk'] ),
            'summary_uk' => trim( $data['summary_uk'] ),
        ];
    }
}

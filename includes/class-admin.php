<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Админ-страница плагина: ключ API, модель, источники, пороги, ручной запуск, лог.
 * Живёт как подменю в разделе "Инструменты" (tools.php).
 */
final class Admin {

    private const PAGE_SLUG    = 'tm-news';
    private const OPTION_GROUP = 'tm_news_settings';

    public function boot(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_tm_news_run_now',     [ $this, 'handle_run_now' ] );
        add_action( 'admin_post_tm_news_clear_log',   [ $this, 'handle_clear_log' ] );
        add_action( 'admin_post_tm_news_save_sources', [ $this, 'handle_save_sources' ] );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'News aggregator', 'tm-news' ),
            __( 'News aggregator', 'tm-news' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        $opts = [
            Translator_OpenAI::OPTION_KEY,
            Translator_OpenAI::OPTION_MODEL,
            Scorer::OPTION_TAU,
            Scorer::OPTION_KEYS,
            Publisher::OPTION_TOPK,
            Publisher::OPTION_MIN_SCORE,
            Publisher::OPTION_AUTOPUBLISH,
            Publisher::OPTION_CATEGORY_ID,
        ];
        foreach ( $opts as $o ) {
            register_setting( self::OPTION_GROUP, $o );
        }
    }

    public function handle_run_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_news_run_now' );
        $dry = isset( $_POST['dry_run'] );
        $res = Plugin::instance()->run_pipeline( $dry );
        set_transient( 'tm_news_last_run', $res, 600 );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&ran=1' ) );
        exit;
    }

    public function handle_clear_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_news_clear_log' );
        Logger::clear();
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    public function handle_save_sources(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_news_save_sources' );
        $raw   = $_POST['sources'] ?? [];
        $saved = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $key => $row ) {
                $key = sanitize_key( (string) $key );
                if ( $key === '' || empty( $row['url'] ) ) {
                    continue;
                }
                $saved[ $key ] = [
                    'name'    => sanitize_text_field( (string) ( $row['name'] ?? $key ) ),
                    'url'     => esc_url_raw( (string) $row['url'] ),
                    'weight'  => max( 0.0, min( 1.0, (float) ( $row['weight'] ?? 0.5 ) ) ),
                    'enabled' => ! empty( $row['enabled'] ),
                ];
            }
        }
        Sources::update( $saved );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $last_run = get_transient( 'tm_news_last_run' );
        $sources  = Sources::all();

        $keywords_text = implode( "\n", Scorer::keywords() );

        $categories = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
        $cat_id     = (int) get_option( Publisher::OPTION_CATEGORY_ID, 0 );

        ?>
        <div class="wrap">
            <h1>Trojmiasto News Aggregator</h1>

            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Источники сохранены.</p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['ran'] ) && is_array( $last_run ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong>Запуск завершён.</strong>
                        fetched: <?php echo (int) $last_run['fetched']; ?> |
                        clustered: <?php echo (int) $last_run['clustered']; ?> |
                        scored: <?php echo (int) $last_run['scored']; ?> |
                        <?php
                        if ( ! empty( $last_run['publish']['created'] ) ) {
                            echo 'создано ' . count( $last_run['publish']['created'] );
                        }
                        ?>
                    </p>
                    <?php if ( ! empty( $last_run['error'] ) ) : ?>
                        <p style="color:#b32d2e;">Ошибка: <?php echo esc_html( $last_run['error'] ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2>1. Настройки LLM и планировщика</h2>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="openai_key">OpenAI API key</label></th>
                        <td>
                            <input type="password" id="openai_key" name="<?php echo esc_attr( Translator_OpenAI::OPTION_KEY ); ?>"
                                value="<?php echo esc_attr( (string) get_option( Translator_OpenAI::OPTION_KEY, '' ) ); ?>"
                                class="regular-text" autocomplete="off" />
                            <p class="description">Без ключа плагин тянет и кластеризует новости, но не пишет черновики. В dry-run используется заглушка.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_model">Модель</label></th>
                        <td>
                            <input type="text" id="openai_model" name="<?php echo esc_attr( Translator_OpenAI::OPTION_MODEL ); ?>"
                                value="<?php echo esc_attr( (string) get_option( Translator_OpenAI::OPTION_MODEL, Translator_OpenAI::DEFAULT_MODEL ) ); ?>"
                                class="regular-text" />
                            <p class="description">Дефолт: <code><?php echo esc_html( Translator_OpenAI::DEFAULT_MODEL ); ?></code>. Любая Chat Completions-совместимая.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tau">Recency τ (часы)</label></th>
                        <td>
                            <input type="number" id="tau" step="0.5" min="0.5"
                                name="<?php echo esc_attr( Scorer::OPTION_TAU ); ?>"
                                value="<?php echo esc_attr( (string) Scorer::tau_hours() ); ?>" />
                            <p class="description">Чем меньше — тем жёстче затухает вес старых новостей.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="topk">Top K за запуск</label></th>
                        <td>
                            <input type="number" id="topk" min="1" max="50"
                                name="<?php echo esc_attr( Publisher::OPTION_TOPK ); ?>"
                                value="<?php echo esc_attr( (string) get_option( Publisher::OPTION_TOPK, 8 ) ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="minscore">Минимальный score</label></th>
                        <td>
                            <input type="number" id="minscore" step="0.1" min="0"
                                name="<?php echo esc_attr( Publisher::OPTION_MIN_SCORE ); ?>"
                                value="<?php echo esc_attr( (string) get_option( Publisher::OPTION_MIN_SCORE, 0.6 ) ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Автопубликация</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( Publisher::OPTION_AUTOPUBLISH ); ?>" value="1"
                                    <?php checked( (int) get_option( Publisher::OPTION_AUTOPUBLISH, 0 ), 1 ); ?> />
                                Публиковать сразу, минуя черновик
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cat">Рубрика</label></th>
                        <td>
                            <select id="cat" name="<?php echo esc_attr( Publisher::OPTION_CATEGORY_ID ); ?>">
                                <option value="0">— не назначать —</option>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected( $cat_id, (int) $cat->term_id ); ?>>
                                        <?php echo esc_html( $cat->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kw">Ключевые слова темы</label></th>
                        <td>
                            <textarea id="kw" name="<?php echo esc_attr( Scorer::OPTION_KEYS ); ?>" rows="6" cols="60"><?php echo esc_textarea( $keywords_text ); ?></textarea>
                            <p class="description">По одному на строку. Совпадение с заголовком или лидом даёт буст score; отсутствие — множитель 0.3.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Сохранить настройки' ); ?>
            </form>

            <h2>2. Источники</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="tm_news_save_sources" />
                <?php wp_nonce_field( 'tm_news_save_sources' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Ключ</th><th>Название</th><th>RSS URL</th><th>Вес</th><th>Вкл.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $sources as $key => $s ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td><input type="text" name="sources[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $s['name'] ?? '' ); ?>" class="regular-text" /></td>
                            <td><input type="url" name="sources[<?php echo esc_attr( $key ); ?>][url]" value="<?php echo esc_attr( $s['url'] ?? '' ); ?>" class="large-text code" /></td>
                            <td><input type="number" step="0.05" min="0" max="1" name="sources[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( (string) ( $s['weight'] ?? 0.5 ) ); ?>" /></td>
                            <td><input type="checkbox" name="sources[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> /></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Сохранить источники', 'secondary' ); ?>
            </form>

            <h2>3. Ручной запуск</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="tm_news_run_now" />
                <?php wp_nonce_field( 'tm_news_run_now' ); ?>
                <p>
                    <label><input type="checkbox" name="dry_run" value="1" checked /> dry-run (не создавать посты)</label>
                </p>
                <?php submit_button( 'Запустить пайплайн сейчас', 'primary', 'go', false ); ?>
            </form>

            <h2>4. Лог (последние события)</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
                <input type="hidden" name="action" value="tm_news_clear_log" />
                <?php wp_nonce_field( 'tm_news_clear_log' ); ?>
                <?php submit_button( 'Очистить лог', 'delete', 'clear', false ); ?>
            </form>
            <pre style="background:#111;color:#eee;padding:1em;max-height:400px;overflow:auto;"><?php
                foreach ( array_reverse( Logger::tail( 100 ) ) as $e ) {
                    $ts = gmdate( 'Y-m-d H:i:s', (int) $e['ts'] );
                    $ctx = $e['ctx'] ? ' ' . wp_json_encode( $e['ctx'], JSON_UNESCAPED_UNICODE ) : '';
                    echo esc_html( sprintf( "%s  [%s] %s%s\n", $ts, $e['level'], $e['msg'], $ctx ) );
                }
            ?></pre>
        </div>
        <?php
    }
}

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

    private const PAGE_SLUG        = 'tm-news';
    private const PAGE_SLUG_ITEMS  = 'tm-news-fetched';
    private const OPTION_GROUP     = 'tm_news_settings';

    public function boot(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_tm_news_run_now',     [ $this, 'handle_run_now' ] );
        add_action( 'admin_post_tm_news_clear_log',   [ $this, 'handle_clear_log' ] );
        add_action( 'admin_post_tm_news_save_sources', [ $this, 'handle_save_sources' ] );
        add_action( 'admin_post_tm_news_fetch_items', [ $this, 'handle_fetch_items' ] );
        add_action( 'admin_notices', [ $this, 'render_digest_list_toolbar' ] );
        add_action( 'admin_notices', [ $this, 'digest_list_admin_notices' ] );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'News aggregator', 'tm-news' ),
            __( 'News aggregator', 'tm-news' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
        add_submenu_page(
            'edit.php?post_type=' . CPT::POST_TYPE,
            __( 'Забранные новости', 'tm-news' ),
            __( 'Забранные', 'tm-news' ),
            'manage_options',
            self::PAGE_SLUG_ITEMS,
            [ $this, 'render_items_page' ]
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
        $redirect = isset( $_POST['tm_news_redirect'] ) ? sanitize_key( wp_unslash( $_POST['tm_news_redirect'] ) ) : '';
        if ( $redirect === 'digest' ) {
            $url = admin_url( 'edit.php?post_type=' . rawurlencode( CPT::POST_TYPE ) . '&ran=1' );
        } else {
            $url = admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&ran=1' );
        }
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_fetch_items(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_news_fetch_items' );
        $n = Fetcher::fetch_all();
        wp_safe_redirect( add_query_arg( [
            'post_type' => CPT::POST_TYPE,
            'page'      => self::PAGE_SLUG_ITEMS,
            'fetched'   => $n,
        ], admin_url( 'edit.php' ) ) );
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

    /**
     * Кнопка полного прогона пайплайна на экране списка дайджеста (CPT).
     *
     * Выводится через admin_notices, потому что «официального» места для кастомной
     * кнопки на экране edit.php нет: restrict_manage_posts живёт внутри GET-формы
     * фильтров, а вкладывать свою form в чужую нельзя. admin_notices даёт отдельный
     * блок над таблицей.
     */
    public function render_digest_list_toolbar(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-' . CPT::POST_TYPE ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="notice notice-info tm-news-digest-actions" style="display:flex;align-items:center;gap:12px;padding:10px 12px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                <input type="hidden" name="action" value="tm_news_run_now" />
                <input type="hidden" name="tm_news_redirect" value="digest" />
                <?php wp_nonce_field( 'tm_news_run_now' ); ?>
                <?php
                submit_button(
                    __( 'Сгенерировать новости', 'tm-news' ),
                    'primary',
                    'tm_news_generate_digest',
                    false,
                    [ 'id' => 'tm-news-generate-from-digest' ]
                );
                ?>
            </form>
            <span class="description" style="margin:0;"><?php esc_html_e( 'Забирает RSS, кластеризует, считает score и создаёт черновики (как «Запустить пайплайн сейчас» без dry-run).', 'tm-news' ); ?></span>
        </div>
        <?php
    }

    /**
     * Результат последнего запуска с экрана дайджеста.
     */
    public function digest_list_admin_notices(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-' . CPT::POST_TYPE || empty( $_GET['ran'] ) ) {
            return;
        }
        $last_run = get_transient( 'tm_news_last_run' );
        if ( ! is_array( $last_run ) ) {
            return;
        }
        $this->render_pipeline_result_notice( $last_run );
    }

    /**
     * @param array<string,mixed> $last_run
     */
    private function render_pipeline_result_notice( array $last_run ): void {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong><?php esc_html_e( 'Запуск завершён.', 'tm-news' ); ?></strong>
                fetched: <?php echo (int) $last_run['fetched']; ?> |
                clustered: <?php echo (int) $last_run['clustered']; ?> |
                scored: <?php echo (int) $last_run['scored']; ?> |
                <?php
                $created = ( is_array( $last_run['publish'] ?? null ) && ! empty( $last_run['publish']['created'] ) )
                    ? $last_run['publish']['created']
                    : null;
                if ( is_array( $created ) ) {
                    echo esc_html( sprintf( /* translators: %d: number of drafts */ __( 'создано %d', 'tm-news' ), count( $created ) ) );
                }
                ?>
            </p>
            <?php if ( ! empty( $last_run['error'] ) ) : ?>
                <p style="color:#b32d2e;"><strong><?php esc_html_e( 'Ошибка:', 'tm-news' ); ?></strong> <?php echo esc_html( (string) $last_run['error'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
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
                <?php $this->render_pipeline_result_notice( $last_run ); ?>
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

    /**
     * Подстраница «Забранные новости»: список tm_news_items со статистикой,
     * кнопкой ручного fetch и bulk-action принудительного черновика.
     */
    public function render_items_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }

        $bulk_result = $this->maybe_process_make_draft_bulk();

        $table = new Items_Table();
        $table->prepare_items();

        $stats = $this->collect_items_stats();

        $base_url = add_query_arg( [
            'post_type' => CPT::POST_TYPE,
            'page'      => self::PAGE_SLUG_ITEMS,
        ], admin_url( 'edit.php' ) );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Забранные новости', 'tm-news' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( isset( $_GET['fetched'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( sprintf( /* translators: %d: number of new items */ __( 'Забрано новых айтемов: %d', 'tm-news' ), (int) $_GET['fetched'] ) ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( is_array( $bulk_result ) ) : ?>
                <div class="notice notice-<?php echo $bulk_result['errors'] ? 'warning' : 'success'; ?> is-dismissible">
                    <p>
                        <?php echo esc_html( sprintf( __( 'Создано черновиков: %d', 'tm-news' ), $bulk_result['created'] ) ); ?>
                        <?php if ( $bulk_result['errors'] ) : ?>
                            · <?php echo esc_html( sprintf( __( 'ошибок: %d', 'tm-news' ), $bulk_result['errors'] ) ); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ( ! empty( $bulk_result['messages'] ) ) : ?>
                        <ul style="margin:0 0 0 1.2em;list-style:disc;">
                            <?php foreach ( $bulk_result['messages'] as $m ) : ?>
                                <li><?php echo esc_html( $m ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tm-news-stats" style="display:flex;flex-wrap:wrap;gap:12px;margin:1em 0;">
                <?php foreach ( $stats as $label => $cell ) : ?>
                    <div style="background:#fff;border:1px solid #ccd0d4;padding:10px 14px;min-width:140px;">
                        <div style="font-size:11px;text-transform:uppercase;color:#666;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:20px;font-weight:600;">
                            <?php echo isset( $cell['html'] ) ? $cell['html'] : esc_html( (string) $cell['value'] ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0;display:flex;gap:10px;align-items:center;">
                <input type="hidden" name="action" value="tm_news_fetch_items" />
                <?php wp_nonce_field( 'tm_news_fetch_items' ); ?>
                <?php submit_button( __( 'Забрать новости', 'tm-news' ), 'primary', 'tm_news_fetch', false ); ?>
                <span class="description"><?php esc_html_e( 'RSS всех включённых источников, новые айтемы попадают в таблицу.', 'tm-news' ); ?></span>
            </form>

            <?php $this->render_score_explainer(); ?>

            <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
                <input type="hidden" name="post_type" value="<?php echo esc_attr( CPT::POST_TYPE ); ?>" />
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG_ITEMS ); ?>" />

                <?php
                $sources_all      = Sources::all();
                $selected_sources = Items_Table::parse_sources_filter();
                $date_from_in     = (string) ( $_REQUEST['date_from'] ?? '' );
                $date_to_in       = (string) ( $_REQUEST['date_to'] ?? '' );
                $reset_url        = add_query_arg( [
                    'post_type' => CPT::POST_TYPE,
                    'page'      => self::PAGE_SLUG_ITEMS,
                ], admin_url( 'edit.php' ) );
                ?>
                <div class="tm-news-filters" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;margin:12px 0;padding:12px 14px;background:#fff;border:1px solid #ccd0d4;">
                    <div style="flex:1 1 320px;min-width:260px;">
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;">
                            <?php esc_html_e( 'Источники', 'tm-news' ); ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:4px 14px;">
                            <?php foreach ( $sources_all as $key => $src ) :
                                $checked = in_array( (string) $key, $selected_sources, true );
                                $name    = (string) ( $src['name'] ?? $key );
                                ?>
                                <label style="white-space:nowrap;">
                                    <input type="checkbox" name="source[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> />
                                    <?php echo esc_html( $name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;">
                            <?php esc_html_e( 'Опубликовано', 'tm-news' ); ?>
                        </div>
                        <label style="margin-right:6px;">
                            <?php esc_html_e( 'с', 'tm-news' ); ?>
                            <input type="date" name="date_from" value="<?php echo esc_attr( $date_from_in ); ?>" />
                        </label>
                        <label>
                            <?php esc_html_e( 'по', 'tm-news' ); ?>
                            <input type="date" name="date_to" value="<?php echo esc_attr( $date_to_in ); ?>" />
                        </label>
                    </div>
                    <div>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Применить фильтры', 'tm-news' ); ?></button>
                        <a href="<?php echo esc_url( $reset_url ); ?>" class="button"><?php esc_html_e( 'Сброс', 'tm-news' ); ?></a>
                    </div>
                </div>

                <?php $table->search_box( __( 'Поиск', 'tm-news' ), 'tm-news-item' ); ?>
                <?php $table->display(); ?>
            </form>

            <p class="description" style="margin-top:1em;">
                <?php echo wp_kses_post( sprintf(
                    /* translators: %s: link to digest list */
                    __( 'Для создания черновика нужен настроенный OpenAI-ключ (см. <a href="%s">News aggregator</a>). Если по URL источника уже есть пост, дубль не создаётся.', 'tm-news' ),
                    esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) )
                ) ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Обрабатываем POST/GET bulk submit таблицы ещё до её рендера: при успехе
     * делаем redirect (PRG), иначе возвращаем сводку для отображения.
     *
     * @return array{created:int,errors:int,messages:string[]}|null
     */
    /**
     * Сворачиваемый блок с объяснением формулы Score над таблицей.
     * Подставляет живые значения τ и список topic-ключей из Scorer,
     * чтобы описание не расходилось с реальностью.
     */
    private function render_score_explainer(): void {
        $tau_h    = Scorer::tau_hours();
        $keywords = Scorer::keywords();
        $kw_count = count( $keywords );
        $kw_sample_arr = array_slice( $keywords, 0, 12 );
        $kw_sample = implode( ', ', $kw_sample_arr );
        if ( $kw_count > count( $kw_sample_arr ) ) {
            $kw_sample .= ', …';
        }

        ?>
        <details class="tm-news-score-explainer" style="margin:1em 0;background:#fff;border:1px solid #ccd0d4;padding:8px 14px;">
            <summary style="cursor:pointer;font-weight:600;">
                <?php esc_html_e( 'Как считается Score (0–100)', 'tm-news' ); ?>
            </summary>
            <div style="margin-top:10px;color:#1d2327;line-height:1.55;">
                <p style="margin:0 0 8px;">
                    <?php esc_html_e( 'Score показывает «горячесть» конкретной новости — насколько она свежая, из доверенного источника и релевантна теме Труймясто. Значение в таблице — это округлённый процент от 0 до 100.', 'tm-news' ); ?>
                </p>

                <p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Формула:', 'tm-news' ); ?></strong></p>
                <pre style="margin:0 0 10px;padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;overflow:auto;">score = weight(источник) × recency × topic_match
score_100 = round(score × 100)</pre>

                <ul style="margin:0 0 8px 1.2em;list-style:disc;">
                    <li>
                        <strong>weight(источник)</strong> — «доверие» к источнику, число 0…1.
                        <?php esc_html_e( 'Настраивается в полях', 'tm-news' ); ?>
                        <code>Инструменты → News aggregator → Источники</code>.
                        <?php esc_html_e( 'Пример: Trojmiasto.pl = 1.0, Radio Gdańsk = 0.9, общепольские мейнстрим-СМИ = 0.3–0.6.', 'tm-news' ); ?>
                    </li>
                    <li>
                        <strong>recency</strong> = <code>exp(−age / τ)</code>,
                        <?php echo wp_kses_post( sprintf(
                            /* translators: %s: tau in hours */
                            __( 'где <code>age</code> — возраст публикации в секундах, а <code>τ</code> = <strong>%s ч</strong> (настраивается в разделе News aggregator).', 'tm-news' ),
                            esc_html( rtrim( rtrim( number_format( $tau_h, 2, '.', '' ), '0' ), '.' ) )
                        ) ); ?>
                        <?php esc_html_e( 'Новость «сегодня» ≈ 1.0, старая на τ часов ≈ 0.37, на 2τ ≈ 0.14.', 'tm-news' ); ?>
                    </li>
                    <li>
                        <strong>topic_match</strong> =
                        <code>1.0</code>,
                        <?php esc_html_e( 'если в заголовке или анонсе найдено хотя бы одно ключевое слово, иначе', 'tm-news' ); ?>
                        <code>0.3</code>.
                        <?php echo wp_kses_post( sprintf(
                            /* translators: 1: number of keywords, 2: comma-separated sample */
                            __( 'Сейчас активно %1$d ключевых слов: <code>%2$s</code>.', 'tm-news' ),
                            (int) $kw_count,
                            esc_html( $kw_sample )
                        ) ); ?>
                    </li>
                </ul>

                <p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Цвет бейджа:', 'tm-news' ); ?></strong>
                    <?php esc_html_e( 'градиент HSL от красного (0) к зелёному (100).', 'tm-news' ); ?>
                </p>

                <p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Примеры:', 'tm-news' ); ?></strong></p>
                <ul style="margin:0 0 0 1.2em;list-style:disc;">
                    <li><?php esc_html_e( 'Свежая новость про Гданьск из Trojmiasto.pl:', 'tm-news' ); ?> <code>1.0 × ≈1.0 × 1.0 ≈ 1.00 → 100</code>.</li>
                    <li><?php echo wp_kses_post( sprintf( __( 'Новость возрастом %s ч из Radio Gdańsk без матча по ключам:', 'tm-news' ), esc_html( rtrim( rtrim( number_format( $tau_h, 2, '.', '' ), '0' ), '.' ) ) ) ); ?>
                        <code>0.9 × 0.37 × 0.3 ≈ 0.10 → 10</code>.
                    </li>
                    <li><?php esc_html_e( 'Общепольский заголовок без упоминания Труймяста умножится на 0.3 и почти наверняка не попадёт в топ — это задумано.', 'tm-news' ); ?></li>
                </ul>
            </div>
        </details>
        <?php
    }

    private function maybe_process_make_draft_bulk(): ?array {
        $action = (string) ( $_REQUEST['action'] ?? '' );
        if ( $action === '' || $action === '-1' ) {
            $action = (string) ( $_REQUEST['action2'] ?? '' );
        }
        if ( $action !== 'tm_news_make_draft' ) {
            return null;
        }
        $ids = array_map( 'intval', (array) ( $_REQUEST['item_ids'] ?? [] ) );
        $ids = array_filter( $ids );
        if ( ! $ids ) {
            return null;
        }
        check_admin_referer( 'bulk-tm_news_items' );

        $created  = 0;
        $errors   = 0;
        $messages = [];
        foreach ( $ids as $iid ) {
            $res = Publisher::publish_item( (int) $iid );
            if ( ! empty( $res['ok'] ) ) {
                $created++;
            } else {
                $errors++;
                $messages[] = sprintf( 'item #%d: %s', (int) $iid, (string) ( $res['err'] ?? 'unknown error' ) );
            }
        }
        return [ 'created' => $created, 'errors' => $errors, 'messages' => array_slice( $messages, 0, 20 ) ];
    }

    /**
     * @return array<string,array{value:string,html?:string}>
     */
    private function collect_items_stats(): array {
        global $wpdb;
        $items_t = Installer::items_table();
        $clust_t = Installer::clusters_table();

        $now = time();
        $day = $now - 86400;

        $total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_t}" );
        $last_24h   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items_t} WHERE fetched_ts >= %d", $day ) );
        $clustered  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_t} WHERE cluster_id IS NOT NULL" );
        $drafted    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clust_t} WHERE post_id IS NOT NULL" );
        $sources_on = count( Sources::enabled() );
        $last_fetch = (int) $wpdb->get_var( "SELECT MAX(fetched_ts) FROM {$items_t}" );
        $last_label = $last_fetch > 0 ? wp_date( 'Y-m-d H:i', $last_fetch ) : '—';

        // Score-агрегаты. Ограничиваем 5000 строк, чтобы не сжечь память на больших базах —
        // если когда-нибудь упрёмся, перенесём score в колонку БД.
        $score_rows = $wpdb->get_results(
            "SELECT source_key, title, excerpt, pub_ts FROM {$items_t} ORDER BY fetched_ts DESC LIMIT 5000",
            ARRAY_A
        );
        $score_rows = Items_Table::annotate_score( $score_rows );
        $avg = 0;
        $max = 0;
        $hot = 0;
        if ( $score_rows ) {
            $sum = 0;
            foreach ( $score_rows as $r ) {
                $s    = (int) $r['score_100'];
                $sum += $s;
                if ( $s > $max ) {
                    $max = $s;
                }
                if ( $s >= 70 ) {
                    $hot++;
                }
            }
            $avg = (int) round( $sum / count( $score_rows ) );
        }

        return [
            __( 'Всего', 'tm-news' )               => [ 'value' => (string) $total ],
            __( 'За 24 часа', 'tm-news' )          => [ 'value' => (string) $last_24h ],
            __( 'Средний Score', 'tm-news' )       => [ 'value' => (string) $avg, 'html' => Items_Table::render_score_badge( $avg ) ],
            __( 'Макс Score', 'tm-news' )          => [ 'value' => (string) $max, 'html' => Items_Table::render_score_badge( $max ) ],
            __( 'Горячих (≥70)', 'tm-news' )       => [ 'value' => (string) $hot ],
            __( 'В кластерах', 'tm-news' )         => [ 'value' => (string) $clustered ],
            __( 'Черновиков', 'tm-news' )          => [ 'value' => (string) $drafted ],
            __( 'Активных источников', 'tm-news' ) => [ 'value' => (string) $sources_on ],
            __( 'Последний fetch', 'tm-news' )     => [ 'value' => $last_label ],
        ];
    }
}

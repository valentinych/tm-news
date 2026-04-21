<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Админ-UI для модуля Social: три подстраницы под пунктом меню
 * Инструменты → News aggregator → Social.
 */
final class Social_Admin {

    public const PAGE_ACCOUNTS = 'tm-social-accounts';
    public const PAGE_ITEMS    = 'tm-social-items';
    public const PAGE_SETTINGS = 'tm-social-settings';

    public function boot(): void {
        add_action( 'admin_menu',  [ $this, 'register_menu' ], 20 );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_post_tm_social_accounts_save',   [ $this, 'handle_accounts_save' ] );
        add_action( 'admin_post_tm_social_accounts_add',    [ $this, 'handle_accounts_add' ] );
        add_action( 'admin_post_tm_social_accounts_delete', [ $this, 'handle_accounts_delete' ] );
        add_action( 'admin_post_tm_social_run_now',         [ $this, 'handle_run_now' ] );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'Social (YT/TG)', 'tm-news' ),
            __( '— Social: посты', 'tm-news' ),
            'manage_options',
            self::PAGE_ITEMS,
            [ $this, 'render_items_page' ]
        );
        add_management_page(
            __( 'Social: аккаунты', 'tm-news' ),
            __( '— Social: аккаунты', 'tm-news' ),
            'manage_options',
            self::PAGE_ACCOUNTS,
            [ $this, 'render_accounts_page' ]
        );
        add_management_page(
            __( 'Social: настройки', 'tm-news' ),
            __( '— Social: настройки', 'tm-news' ),
            'manage_options',
            self::PAGE_SETTINGS,
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'tm_social_settings', Social::OPTION_YT_KEY, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
    }

    // --- handlers --------------------------------------------------------

    public function handle_accounts_add(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_social_accounts_add' );
        $platform = (string) ( $_POST['platform'] ?? '' );
        $handle   = (string) ( $_POST['handle']   ?? '' );
        $title    = (string) ( $_POST['title']    ?? '' );
        $tags     = (string) ( $_POST['tags']     ?? '' );
        $res = Social::add_account( $platform, $handle, sanitize_text_field( $title ), '', sanitize_text_field( $tags ), false );
        $arg = is_wp_error( $res ) ? [ 'added' => 0, 'err' => rawurlencode( $res->get_error_message() ) ] : [ 'added' => 1 ];
        wp_safe_redirect( add_query_arg( $arg, admin_url( 'tools.php?page=' . self::PAGE_ACCOUNTS ) ) );
        exit;
    }

    public function handle_accounts_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_social_accounts_save' );
        $rows = (array) ( $_POST['accounts'] ?? [] );
        $saved = 0;
        foreach ( $rows as $id => $row ) {
            $id = (int) $id;
            if ( $id <= 0 ) {
                continue;
            }
            Social::update_account( $id, [
                'title'   => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
                'tags'    => sanitize_text_field( (string) ( $row['tags']  ?? '' ) ),
                'enabled' => ! empty( $row['enabled'] ) ? 1 : 0,
            ] );
            $saved++;
        }
        wp_safe_redirect( add_query_arg( [ 'saved' => $saved ], admin_url( 'tools.php?page=' . self::PAGE_ACCOUNTS ) ) );
        exit;
    }

    public function handle_accounts_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'tm_social_accounts_delete_' . $id );
        Social::delete_account( $id );
        wp_safe_redirect( add_query_arg( [ 'deleted' => 1 ], admin_url( 'tools.php?page=' . self::PAGE_ACCOUNTS ) ) );
        exit;
    }

    public function handle_run_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        check_admin_referer( 'tm_social_run_now' );
        $res = Social::run_once();
        set_transient( 'tm_social_last_run', $res, 600 );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_ITEMS . '&ran=1' ) );
        exit;
    }

    // --- render ---------------------------------------------------------

    public function render_accounts_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        $accounts = Social::all_accounts();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Social: аккаунты', 'tm-news' ); ?></h1>

            <?php if ( ! empty( $_GET['added'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Аккаунт добавлен.', 'tm-news' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['err'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( (string) $_GET['err'] ) ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Обновлено: %d', 'tm-news' ), (int) $_GET['saved'] ) ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Аккаунт удалён.', 'tm-news' ); ?></p></div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Модуль отслеживает публичные YouTube-каналы (через Data API v3) и публичные Telegram-каналы (через страницу t.me/s/<handle>, без авторизации). Приватные аккаунты и закрытые каналы принципиально не поддерживаются.', 'tm-news' ); ?>
                <?php esc_html_e( 'Включите только те аккаунты, которые вам нужны — неактивные не расходуют квоту.', 'tm-news' ); ?>
            </p>

            <h2><?php esc_html_e( 'Добавить аккаунт', 'tm-news' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:20px;">
                <input type="hidden" name="action" value="tm_social_accounts_add" />
                <?php wp_nonce_field( 'tm_social_accounts_add' ); ?>
                <label>
                    <div style="font-size:11px;color:#666;"><?php esc_html_e( 'Платформа', 'tm-news' ); ?></div>
                    <select name="platform">
                        <option value="youtube">YouTube</option>
                        <option value="telegram">Telegram</option>
                    </select>
                </label>
                <label>
                    <div style="font-size:11px;color:#666;"><?php esc_html_e( 'Handle или URL', 'tm-news' ); ?></div>
                    <input type="text" name="handle" class="regular-text" placeholder="@RadioGdansk или tvp_info" required />
                </label>
                <label>
                    <div style="font-size:11px;color:#666;"><?php esc_html_e( 'Название (опц.)', 'tm-news' ); ?></div>
                    <input type="text" name="title" class="regular-text" />
                </label>
                <label>
                    <div style="font-size:11px;color:#666;"><?php esc_html_e( 'Теги (через запятую)', 'tm-news' ); ?></div>
                    <input type="text" name="tags" class="regular-text" placeholder="trojmiasto,local,news" />
                </label>
                <?php submit_button( __( 'Добавить', 'tm-news' ), 'primary', 'submit', false ); ?>
            </form>

            <h2><?php esc_html_e( 'Список аккаунтов', 'tm-news' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="tm_social_accounts_save" />
                <?php wp_nonce_field( 'tm_social_accounts_save' ); ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Платформа', 'tm-news' ); ?></th>
                        <th><?php esc_html_e( 'Handle', 'tm-news' ); ?></th>
                        <th><?php esc_html_e( 'Название', 'tm-news' ); ?></th>
                        <th><?php esc_html_e( 'Теги', 'tm-news' ); ?></th>
                        <th><?php esc_html_e( 'Включён', 'tm-news' ); ?></th>
                        <th><?php esc_html_e( 'Последний fetch', 'tm-news' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $accounts as $a ) :
                        $id      = (int) $a['id'];
                        $last_ts = (int) $a['last_checked_ts'];
                        $last    = $last_ts > 0 ? wp_date( 'Y-m-d H:i', $last_ts ) : '—';
                        $del_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=tm_social_accounts_delete&id=' . $id ),
                            'tm_social_accounts_delete_' . $id
                        );
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $a['platform'] ); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url( (string) $a['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $a['handle'] ); ?></a>
                                <?php if ( ! empty( $a['external_id'] ) ) : ?>
                                    <br /><code style="color:#666;font-size:11px;"><?php echo esc_html( (string) $a['external_id'] ); ?></code>
                                <?php endif; ?>
                            </td>
                            <td><input type="text" name="accounts[<?php echo $id; ?>][title]" value="<?php echo esc_attr( (string) $a['title'] ); ?>" class="regular-text" /></td>
                            <td><input type="text" name="accounts[<?php echo $id; ?>][tags]"  value="<?php echo esc_attr( (string) $a['tags'] ); ?>" class="regular-text" /></td>
                            <td><input type="checkbox" name="accounts[<?php echo $id; ?>][enabled]" value="1" <?php checked( ! empty( $a['enabled'] ) ); ?> /></td>
                            <td><?php echo esc_html( $last ); ?></td>
                            <td><a href="<?php echo esc_url( $del_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Удалить аккаунт? Посты и снимки останутся в БД как архив.', 'tm-news' ) ); ?>');"><?php esc_html_e( 'Удалить', 'tm-news' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( ! $accounts ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'Пусто. Добавьте аккаунт выше.', 'tm-news' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php submit_button( __( 'Сохранить изменения', 'tm-news' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function render_items_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }

        $table = new Social_Items_Table();
        $table->prepare_items();
        $stats = $this->collect_items_stats();
        $last_run = get_transient( 'tm_social_last_run' );

        $base_url = admin_url( 'tools.php?page=' . self::PAGE_ITEMS );
        $accounts = Social::all_accounts();

        $selected_platform = (string) ( $_REQUEST['platform'] ?? '' );
        $selected_accts    = array_values( array_filter( array_map( 'intval', (array) ( $_REQUEST['account_id'] ?? [] ) ) ) );
        $date_from         = (string) ( $_REQUEST['date_from'] ?? '' );
        $date_to           = (string) ( $_REQUEST['date_to']   ?? '' );
        $tag_in            = (string) ( $_REQUEST['tag']       ?? '' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Social: посты', 'tm-news' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( ! empty( $_GET['ran'] ) && is_array( $last_run ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong><?php esc_html_e( 'Тик выполнен.', 'tm-news' ); ?></strong>
                        accounts: <?php echo (int) $last_run['accounts']; ?> |
                        new: <?php echo (int) $last_run['new_items']; ?> |
                        refreshed: <?php echo (int) $last_run['refreshed']; ?> |
                        errors: <?php echo (int) $last_run['errors']; ?>
                    </p>
                    <?php if ( ! empty( $last_run['messages'] ) ) : ?>
                        <ul style="margin:0 0 0 1.2em;list-style:disc;color:#b32d2e;">
                        <?php foreach ( array_slice( (array) $last_run['messages'], 0, 10 ) as $m ) : ?>
                            <li><?php echo esc_html( (string) $m ); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tm-news-stats" style="display:flex;flex-wrap:wrap;gap:12px;margin:1em 0;">
                <?php foreach ( $stats as $label => $val ) : ?>
                    <div style="background:#fff;border:1px solid #ccd0d4;padding:10px 14px;min-width:140px;">
                        <div style="font-size:11px;text-transform:uppercase;color:#666;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:20px;font-weight:600;"><?php echo esc_html( $val ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="tm_social_run_now" />
                <?php wp_nonce_field( 'tm_social_run_now' ); ?>
                <?php submit_button( __( 'Забрать сейчас', 'tm-news' ), 'primary', 'tm_social_run', false ); ?>
                <span class="description"><?php esc_html_e( 'Обычно запускается по cron каждые 30 минут.', 'tm-news' ); ?></span>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_ACCOUNTS ) ); ?>" class="button"><?php esc_html_e( 'Аккаунты', 'tm-news' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SETTINGS ) ); ?>" class="button"><?php esc_html_e( 'Настройки', 'tm-news' ); ?></a>
            </form>

            <form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_ITEMS ); ?>" />

                <div class="tm-news-filters" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;margin:12px 0;padding:12px 14px;background:#fff;border:1px solid #ccd0d4;">
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;"><?php esc_html_e( 'Платформа', 'tm-news' ); ?></div>
                        <select name="platform">
                            <option value=""><?php esc_html_e( 'Все', 'tm-news' ); ?></option>
                            <option value="youtube"  <?php selected( $selected_platform, 'youtube'  ); ?>>YouTube</option>
                            <option value="telegram" <?php selected( $selected_platform, 'telegram' ); ?>>Telegram</option>
                        </select>
                    </div>
                    <div style="flex:1 1 320px;min-width:260px;">
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;"><?php esc_html_e( 'Аккаунты', 'tm-news' ); ?></div>
                        <div style="display:flex;flex-wrap:wrap;gap:2px 14px;max-height:140px;overflow:auto;border:1px solid #dcdcde;padding:4px 8px;">
                            <?php foreach ( $accounts as $a ) :
                                $aid = (int) $a['id'];
                                $label = ( $a['platform'] === 'youtube' ? '▶' : '✈' ) . ' ' . ( $a['title'] !== '' ? $a['title'] : $a['handle'] );
                                ?>
                                <label style="white-space:nowrap;">
                                    <input type="checkbox" name="account_id[]" value="<?php echo $aid; ?>" <?php checked( in_array( $aid, $selected_accts, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;"><?php esc_html_e( 'Тег', 'tm-news' ); ?></div>
                        <input type="text" name="tag" value="<?php echo esc_attr( $tag_in ); ?>" placeholder="trojmiasto" />
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;"><?php esc_html_e( 'Опубликовано', 'tm-news' ); ?></div>
                        <label><?php esc_html_e( 'с', 'tm-news' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" /></label>
                        <label><?php esc_html_e( 'по', 'tm-news' ); ?> <input type="date" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>" /></label>
                    </div>
                    <div>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Применить', 'tm-news' ); ?></button>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Сброс', 'tm-news' ); ?></a>
                    </div>
                </div>

                <?php $table->search_box( __( 'Поиск', 'tm-news' ), 'tm-social-item' ); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'nope' );
        }
        $yt_key = (string) get_option( Social::OPTION_YT_KEY, '' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Social: настройки', 'tm-news' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'tm_social_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="yt_key">YouTube Data API key</label></th>
                        <td>
                            <input type="password" id="yt_key" class="regular-text" autocomplete="off"
                                   name="<?php echo esc_attr( Social::OPTION_YT_KEY ); ?>"
                                   value="<?php echo esc_attr( $yt_key ); ?>" />
                            <p class="description">
                                <?php echo wp_kses_post( __( 'Бесплатный ключ: создайте проект в <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>, включите <code>YouTube Data API v3</code>, сгенерируйте API key (Credentials → Create credentials → API key). Квота 10 000 units/сутки — хватает на ~50 каналов при опросе раз в 30 мин. Без ключа YouTube-драйвер просто не работает, Telegram всё равно будет тянуться.', 'tm-news' ) ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Расписание', 'tm-news' ); ?></th>
                        <td>
                            <p><?php esc_html_e( 'Cron tm_social_cron_tick запускается каждые 30 минут. Можно также жать «Забрать сейчас» на странице постов.', 'tm-news' ); ?></p>
                            <?php
                            $next = wp_next_scheduled( Social::CRON_HOOK );
                            if ( $next ) {
                                echo '<p>' . esc_html( sprintf( __( 'Следующий тик: %s', 'tm-news' ), wp_date( 'Y-m-d H:i', $next ) ) ) . '</p>';
                            } else {
                                echo '<p style="color:#b32d2e;">' . esc_html__( 'Cron не зарегистрирован. Деактивируйте и снова активируйте плагин.', 'tm-news' ) . '</p>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Что и как собирается', 'tm-news' ); ?></h2>
            <ul style="list-style:disc;margin-left:1.2em;max-width:820px;">
                <li><strong>YouTube</strong>: через <code>YouTube Data API v3</code>. Для каждого включённого канала драйвер один раз резолвит handle → channelId + uploads playlist, далее по playlist берёт последние до 20 видео и забирает метрики (viewCount / likeCount / commentCount).</li>
                <li><strong>Telegram</strong>: публичная страница <code>https://t.me/s/&lt;handle&gt;</code> без авторизации. Парсим HTML: posts, время публикации, views, сумму реакций (как «лайки»), количество комментариев (если у канала подключена discussion-группа).</li>
                <li><strong>Velocity</strong>: разница двух последних снапшотов делится на прошедшее время. Колонка «Лайки/ч» и «Просмотры/ч» в таблице — это и есть скорость.</li>
                <li><strong>Квоты</strong>: YouTube ~5 000 units/сутки на ~50 каналов при 30-минутном интервале. Telegram — просто HTTP GET, лимитов со стороны WP нет, но не стоит добавлять сотни каналов — будет медленный один тик.</li>
            </ul>
        </div>
        <?php
    }

    // --- helpers --------------------------------------------------------

    /** @return array<string,string> */
    private function collect_items_stats(): array {
        global $wpdb;
        $t = Installer::social_items_table();
        $now = time();
        $day = $now - 86400;

        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
        $last_24h = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE fetched_ts >= %d", $day ) );
        $yt       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE platform = %s", Social::PLATFORM_YT ) );
        $tg       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE platform = %s", Social::PLATFORM_TG ) );
        $last_up  = (int) $wpdb->get_var( "SELECT MAX(updated_ts) FROM {$t}" );
        $accts_on = count( Social::enabled_accounts() );

        return [
            __( 'Всего постов', 'tm-news' )        => (string) $total,
            __( 'За 24 часа',   'tm-news' )        => (string) $last_24h,
            __( 'YouTube',      'tm-news' )        => (string) $yt,
            __( 'Telegram',     'tm-news' )        => (string) $tg,
            __( 'Аккаунтов вкл.', 'tm-news' )      => (string) $accts_on,
            __( 'Последнее обновление', 'tm-news' ) => $last_up > 0 ? wp_date( 'Y-m-d H:i', $last_up ) : '—',
        ];
    }
}

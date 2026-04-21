<?php
/**
 * Plugin Name: Trojmiasto News Aggregator
 * Plugin URI:  https://github.com/valentinych/tm-news
 * Description: Собирает свежие новости из польских источников по Труймясту, кластеризует их и с помощью LLM готовит короткие рерайты на русский и украинский. Создаёт черновики в CPT tm_news_digest для ручной публикации.
 * Version:     0.1.2
 * Author:      trojmiasto.online
 * License:     MIT
 * Text Domain: tm-news
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TM_NEWS_VERSION', '0.1.2' );
define( 'TM_NEWS_PLUGIN_FILE', __FILE__ );
define( 'TM_NEWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TM_NEWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TM_NEWS_PREFIX', 'tm_news_' );

require_once TM_NEWS_PLUGIN_DIR . 'includes/class-logger.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-installer.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-cpt.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-sources.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-normalizer.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-clusterer.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-scorer.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/interface-translator.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-translator-openai.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-translator-null.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-publisher.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-admin.php';
require_once TM_NEWS_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ \TM_News\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \TM_News\Installer::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    \TM_News\Plugin::instance()->boot();
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once TM_NEWS_PLUGIN_DIR . 'includes/class-cli.php';
    \WP_CLI::add_command( 'tm-news', \TM_News\CLI::class );
}

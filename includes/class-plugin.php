<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstrap: регистрирует хуки, cron handler, CPT, админ-меню.
 */
final class Plugin {

    public const CRON_HOOK = 'tm_news_cron_tick';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        add_action( 'init', [ CPT::class, 'register' ] );
        add_action( 'init', [ Installer::class, 'maybe_upgrade' ] );

        add_action( self::CRON_HOOK, [ $this, 'run_pipeline' ] );

        if ( is_admin() ) {
            $admin = new Admin();
            $admin->boot();
        }
    }

    /**
     * Пайплайн одного тика:
     *  1) fetch все активные источники
     *  2) cluster новые айтемы
     *  3) score активные кластеры
     *  4) publish топ-K в черновики
     */
    public function run_pipeline( bool $dry_run = false ): array {
        $out = [
            'fetched'   => 0,
            'clustered' => 0,
            'scored'    => 0,
            'publish'   => null,
        ];
        try {
            $out['fetched']   = Fetcher::fetch_all();
            $out['clustered'] = Clusterer::cluster_unassigned();
            $out['scored']    = Scorer::score_all();
            $out['publish']   = Publisher::publish_top( $dry_run );
        } catch ( \Throwable $e ) {
            Logger::error( 'pipeline failed', [ 'err' => $e->getMessage() ] );
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}

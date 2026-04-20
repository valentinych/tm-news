<?php
namespace TM_News;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Простой логгер: пишет в error_log и в ring-buffer в опции (последние N событий)
 * для отображения на странице Tools → News log.
 */
final class Logger {

    private const OPTION   = 'tm_news_log';
    private const MAX_KEEP = 200;

    public static function info( string $msg, array $ctx = [] ): void {
        self::write( 'info', $msg, $ctx );
    }

    public static function warn( string $msg, array $ctx = [] ): void {
        self::write( 'warn', $msg, $ctx );
    }

    public static function error( string $msg, array $ctx = [] ): void {
        self::write( 'error', $msg, $ctx );
    }

    private static function write( string $level, string $msg, array $ctx ): void {
        $line = sprintf( '[tm-news] %s %s %s', strtoupper( $level ), $msg, $ctx ? wp_json_encode( $ctx ) : '' );
        error_log( $line );

        $log   = get_option( self::OPTION, [] );
        $log[] = [
            'ts'    => time(),
            'level' => $level,
            'msg'   => $msg,
            'ctx'   => $ctx,
        ];
        if ( count( $log ) > self::MAX_KEEP ) {
            $log = array_slice( $log, -self::MAX_KEEP );
        }
        update_option( self::OPTION, $log, false );
    }

    /** @return array<int, array{ts:int,level:string,msg:string,ctx:array}> */
    public static function tail( int $n = 100 ): array {
        $log = get_option( self::OPTION, [] );
        return array_slice( $log, -$n );
    }

    public static function clear(): void {
        update_option( self::OPTION, [], false );
    }
}

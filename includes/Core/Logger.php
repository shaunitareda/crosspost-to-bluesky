<?php
namespace CTB\Core;
class Logger {
    private const OPTION      = 'ctb_debug_log';
    private const MAX_ENTRIES = 200;
    public function log( string $level, string $message, array $context = [] ): void {
        $entries   = get_option( self::OPTION, [] );
        $entries[] = [ 'time' => current_time( 'Y-m-d H:i:s' ), 'level' => strtoupper( $level ), 'message' => $message, 'context' => $context ];
        if ( count( $entries ) > self::MAX_ENTRIES ) $entries = array_slice( $entries, -self::MAX_ENTRIES );
        update_option( self::OPTION, $entries, false );
    }
    public function info(  string $msg, array $ctx = [] ): void { $this->log( 'info',  $msg, $ctx ); }
    public function error( string $msg, array $ctx = [] ): void { $this->log( 'error', $msg, $ctx ); }
    public function debug( string $msg, array $ctx = [] ): void { $this->log( 'debug', $msg, $ctx ); }
    public function get_entries(): array { return array_reverse( get_option( self::OPTION, [] ) ); }
    public function clear(): void        { update_option( self::OPTION, [], false ); }
}

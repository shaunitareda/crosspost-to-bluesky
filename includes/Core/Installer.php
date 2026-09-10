<?php
namespace CTB\Core;
class Installer {
    public static function activate(): void {
        if ( false === get_option( 'ctb_settings' ) ) {
            add_option( 'ctb_settings', [
                'identifier'         => '',
                'app_password'       => '',
                'pds_host'           => 'https://bsky.social',
                'enabled_post_types' => [ 'post' ],
                'auto_crosspost'     => 1,
                'template'           => "{title}\n\n{content}",
                'include_permalink'  => 0,
                'debug_enabled'      => 1,
                'video_enabled'      => 1,
            ] );
        }
    }
}

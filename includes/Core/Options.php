<?php
namespace CTB\Core;
class Options {
    private string $key = 'ctb_settings';
    private array $defaults = [
        'identifier'         => '',
        'app_password'       => '',
        'pds_host'           => 'https://bsky.social',
        'enabled_post_types' => [ 'post' ],
        'auto_crosspost'     => 1,
        'template'           => "{title}\n\n{content}",
        'include_permalink'  => 0,
        'debug_enabled'      => 1,
        'video_enabled'      => 1,
    ];
    public function all(): array {
        $saved = get_option( $this->key, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $this->defaults );
    }
    public function get( string $key, $default = null ) {
        return $this->all()[ $key ] ?? $default;
    }
    public function update( array $values ): bool {
        return update_option( $this->key, array_merge( $this->all(), $values ) );
    }
}

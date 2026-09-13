<?php
/**
 * Regression coverage for post-type-aware permalink/card behavior.
 * Run: php tests/post-type-card-test.php
 */
namespace CTB\Api {
    class Auth {}
    class ImageUploader {}
    class VideoUploader {}
    class HttpClient {}
}
namespace CTB\Core {
    class Options {
        public function __construct( private array $values = [] ) {}
        public function get( string $key, $default = null ) { return $this->values[ $key ] ?? $default; }
    }
    class Logger { public function debug( string $message, array $context = [] ): void {} }
}
namespace {
    class WP_Post {
        public int $ID = 1;
        public string $post_title = '';
        public string $post_excerpt = '';
        public string $post_content = '';
        public string $post_type = 'post';
    }

    $titles = [];
    $permalinks = [];
    function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
    function strip_shortcodes( $text ) { return (string) $text; }
    function get_the_title( $post ) { global $titles; return $titles[ $post->ID ] ?? $post->post_title; }
    function has_excerpt( $post ) { return '' !== trim( $post->post_excerpt ); }
    function wp_trim_words( $text, $num_words = 55, $more = null ) { return trim( (string) $text ); }
    function get_permalink( $post ) { global $permalinks; return $permalinks[ $post->ID ] ?? 'https://evecodes.com/post/' . $post->ID; }
    function get_post_format( $post ) { return false; }

    require_once __DIR__ . '/../includes/Api/PostCreator.php';

    $assert = function ( bool $ok, string $message ): void {
        if ( ! $ok ) {
            fwrite( STDERR, "FAIL: {$message}\n" );
            exit( 1 );
        }
    };

    $options = new \CTB\Core\Options( [ 'template' => '{title} {content}', 'include_permalink' => 1 ] );
    $logger = new \CTB\Core\Logger();
    $ref = new \ReflectionClass( \CTB\Api\PostCreator::class );
    $creator = $ref->newInstanceWithoutConstructor();
    $optionsProp = $ref->getProperty( 'options' );
    $optionsProp->setAccessible( true );
    $optionsProp->setValue( $creator, $options );
    $loggerProp = $ref->getProperty( 'logger' );
    $loggerProp->setAccessible( true );
    $loggerProp->setValue( $creator, $logger );

    $buildText = $ref->getMethod( 'build_text' );
    $buildText->setAccessible( true );
    $findPrimaryUrl = $ref->getMethod( 'find_primary_url' );
    $findPrimaryUrl->setAccessible( true );

    $post = new \WP_Post();
    $post->ID = 10;
    $post->post_title = 'Journal title';
    $post->post_content = 'Journal body';
    $post->post_type = 'post';
    $titles[10] = 'Journal title';
    $permalinks[10] = 'https://evecodes.com/journal-entry/';
    $text = $buildText->invoke( $creator, $post );
    $assert( str_contains( $text, 'https://evecodes.com/journal-entry/' ), 'normal post appends its permalink when enabled' );
    $assert( $findPrimaryUrl->invoke( $creator, $text ) === 'https://evecodes.com/journal-entry/', 'normal post permalink is available for external card creation' );

    $note = new \WP_Post();
    $note->ID = 11;
    $note->post_title = 'Voice memo';
    $note->post_content = 'Plain social note text';
    $note->post_type = 'jetpack-social-note';
    $titles[11] = 'Voice memo';
    $permalinks[11] = 'https://evecodes.com/sn/voice-memo/';
    $text = $buildText->invoke( $creator, $note );
    $assert( ! str_contains( $text, 'https://evecodes.com/sn/voice-memo/' ), 'Social Note does not append its own permalink' );
    $assert( null === $findPrimaryUrl->invoke( $creator, $text ), 'Social Note without an intentional URL does not create its own card' );

    $note->post_content = 'Read this https://example.com/story';
    $text = $buildText->invoke( $creator, $note );
    $assert( ! str_contains( $text, 'https://evecodes.com/sn/voice-memo/' ), 'Social Note still suppresses its own permalink when content has another URL' );
    $assert( $findPrimaryUrl->invoke( $creator, $text ) === 'https://example.com/story', 'Social Note preserves ordinary external URLs for intentional cards' );

    echo "All post-type card regression tests passed.\n";
}

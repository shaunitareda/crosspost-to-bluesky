<?php
namespace CTB\Api {
    class Auth {}
    class ImageUploader {}
    class VideoUploader {}
    class HttpClient {}
}
namespace CTB\Core {
    class Options {}
    class Logger {}
}
namespace {
    require_once __DIR__ . '/../includes/Api/PostCreator.php';

    $assert = function ( bool $ok, string $message ): void {
        if ( ! $ok ) {
            fwrite( STDERR, "FAIL: {$message}\n" );
            exit( 1 );
        }
    };

    $ref = new ReflectionClass( \CTB\Api\PostCreator::class );
    $obj = $ref->newInstanceWithoutConstructor();
    $tid = $ref->getMethod( 'generate_record_key' );
    $tid->setAccessible( true );

    $previous = '';
    for ( $i = 0; $i < 1000; $i++ ) {
        $value = $tid->invoke( $obj );
        $assert(
            preg_match( '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $value ) === 1,
            'generated Blacksky rkey is a valid ATProto TID'
        );
        if ( '' !== $previous ) {
            $assert( strcmp( $value, $previous ) > 0, 'generated TIDs are monotonically increasing' );
        }
        $previous = $value;
    }

    $creator_source = file_get_contents( __DIR__ . '/../includes/Api/PostCreator.php' );
    $publisher_source = file_get_contents( __DIR__ . '/../includes/Core/Publisher.php' );

    $assert( str_contains( $creator_source, '/xrpc/community.blacksky.feed.submitPost' ), 'Blacksky submit endpoint is present' );
    $assert( str_contains( $creator_source, 'did:web:api.blacksky.community#bsky_appview' ), 'Blacksky AppView proxy is present' );
    $assert( str_contains( $creator_source, "'collection' => 'community.blacksky.feed.post'" ), 'Blacksky stub collection is present' );
    $assert( str_contains( $creator_source, '/xrpc/community.blacksky.feed.deletePost' ), 'Blacksky cleanup endpoint is present' );
    $assert( str_contains( $creator_source, "'uri' => $submitted_uri" ), 'Thread chaining returns the AppView canonical URI' );
    $assert( str_contains( $publisher_source, "'_ctb_blacksky_only'" ), 'Publisher recognizes Blacksky-only routing' );
    $assert(
        str_contains( $publisher_source, "'_ctb_blacksky_uri'" ) && str_contains( $publisher_source, "'_ctb_blacksky_cid'" ),
        'Blacksky publication metadata is isolated'
    );

    echo "All Blacksky routing regression tests passed.\n";
}

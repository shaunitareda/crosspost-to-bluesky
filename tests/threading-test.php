<?php
/**
 * Lightweight regression tests for PostCreator threading helpers.
 * Run: php tests/threading-test.php
 */

$source = file_get_contents( __DIR__ . '/../includes/Api/PostCreator.php' );
if ( false === $source ) {
    fwrite( STDERR, "Unable to read PostCreator.php\n" );
    exit( 1 );
}

// Load PostCreator without WordPress by stubbing constructor type dependencies.
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

    $ref = new ReflectionClass( \CTB\Api\PostCreator::class );
    $obj = $ref->newInstanceWithoutConstructor();
    $split = $ref->getMethod( 'split_text_for_thread' );
    $split->setAccessible( true );
    $facets = $ref->getMethod( 'build_facets' );
    $facets->setAccessible( true );
    $recordRef = $ref->getMethod( 'record_ref_from_response' );
    $recordRef->setAccessible( true );

    $assert = function ( bool $ok, string $message ): void {
        if ( ! $ok ) {
            fwrite( STDERR, "FAIL: {$message}\n" );
            exit( 1 );
        }
    };

    $chunks = $split->invoke( $obj, str_repeat( 'a', 300 ) );
    $assert( count( $chunks ) === 1 && mb_strlen( $chunks[0] ) === 300, 'exactly 300 chars stays one post' );

    $chunks = $split->invoke( $obj, str_repeat( 'a', 301 ) );
    $assert( count( $chunks ) === 2 && implode( '', $chunks ) === str_repeat( 'a', 301 ), '301 chars threads without loss' );

    $text = str_repeat( 'First paragraph sentence. ', 9 ) . "\n\n" . str_repeat( 'Second paragraph sentence. ', 9 );
    $chunks = $split->invoke( $obj, trim( $text ) );
    $assert( count( $chunks ) >= 2, 'multi-paragraph text threads' );
    foreach ( $chunks as $chunk ) $assert( mb_strlen( $chunk ) <= 300, 'paragraph chunks <= 300 chars' );

    $unicode = str_repeat( '😀 café 東京 ', 40 );
    $chunks = $split->invoke( $obj, trim( $unicode ) );
    foreach ( $chunks as $chunk ) $assert( mb_strlen( $chunk ) <= 300 && mb_check_encoding( $chunk, 'UTF-8' ), 'unicode chunks valid and <= 300 chars' );

    $hashText = str_repeat( 'alpha ', 47 ) . '#first ' . str_repeat( 'beta ', 47 ) . '#second';
    $chunks = $split->invoke( $obj, $hashText );
    $seen = [];
    foreach ( $chunks as $chunk ) {
        foreach ( $facets->invoke( $obj, $chunk ) as $facet ) {
            $feature = $facet['features'][0] ?? [];
            if ( ( $feature['$type'] ?? '' ) === 'app.bsky.richtext.facet#tag' ) $seen[] = $feature['tag'];
        }
    }
    $assert( in_array( 'first', $seen, true ) && in_array( 'second', $seen, true ), 'hashtags rebuild across threaded chunks' );

    $url = 'https://example.com/' . str_repeat( 'pathsegment', 12 );
    $urlText = str_repeat( 'x ', 95 ) . $url . ' tail words after url';
    $chunks = $split->invoke( $obj, $urlText );
    $joined = implode( ' ', $chunks );
    $assert( substr_count( $joined, $url ) === 1, 'URL near boundary remains intact' );
    foreach ( $chunks as $chunk ) $assert( mb_strlen( $chunk ) <= 300, 'URL chunks <= 300 chars' );

    $long = implode( ' ', array_fill( 0, 500, 'word' ) );
    $chunks = $split->invoke( $obj, $long );
    $assert( count( $chunks ) >= 8, 'multiple thread chunks created' );
    foreach ( $chunks as $chunk ) $assert( mb_strlen( $chunk ) <= 300, 'all multiple chunks <= 300 chars' );

    $root = $recordRef->invoke( $obj, [ 'uri' => 'at://did:plc:test/app.bsky.feed.post/1', 'cid' => 'cid1' ] );
    $parent = $recordRef->invoke( $obj, [ 'uri' => 'at://did:plc:test/app.bsky.feed.post/2', 'cid' => 'cid2' ] );
    $assert( $root === [ 'uri' => 'at://did:plc:test/app.bsky.feed.post/1', 'cid' => 'cid1' ], 'root reference parsed correctly' );
    $assert( $parent === [ 'uri' => 'at://did:plc:test/app.bsky.feed.post/2', 'cid' => 'cid2' ], 'parent reference parsed correctly' );
    $reply = [ 'root' => $root, 'parent' => $parent ];
    $assert( $reply['root']['uri'] !== $reply['parent']['uri'], 'root remains first record while parent advances' );

    echo "All threading regression tests passed.\n";
}

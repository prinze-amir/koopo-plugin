<?php
/** Local regression: deleting a product must not cascade into shared media. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' ) {
    throw new RuntimeException( 'Run only with local WP-CLI.' );
}
add_filter( 'pre_http_request', static function () { return new WP_Error( 'test_no_network', 'No remote requests during media regression.' ); }, PHP_INT_MAX );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$products = []; $attachment = 0; $path = ''; $checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    ++$checks;
};
try {
    $assert( false === has_action( 'before_delete_post', 'delete_product_images' ), 'Unsafe deletion hook is still registered.' );
    foreach ( [ 'owner', 'featured', 'gallery' ] as $name ) {
        $p = new WC_Product_Simple();
        $p->set_name( 'Disposable media retention ' . $name ); $p->set_status( 'draft' ); $p->save();
        $products[] = $p->get_id();
    }
    $upload = wp_upload_bits( 'koopo-retention-' . wp_generate_uuid4() . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4S8AAAAASUVORK5CYII=' ) );
    if ( $upload['error'] ) throw new RuntimeException( $upload['error'] );
    $path = $upload['file']; $hash = hash_file( 'sha256', $path );
    $attachment = wp_insert_attachment( [ 'post_title' => 'Synthetic retention test image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], $path, $products[0], true );
    if ( is_wp_error( $attachment ) ) throw new RuntimeException( $attachment->get_error_message() );
    foreach ( array_slice( $products, 0, 2 ) as $id ) { $p = wc_get_product( $id ); $p->set_image_id( $attachment ); $p->save(); }
    $gallery = wc_get_product( $products[2] ); $gallery->set_gallery_image_ids( [ $attachment ] ); $gallery->save();
    $variation = new WC_Product_Variation(); $variation->set_parent_id( $products[1] ); $variation->set_image_id( $attachment ); $variation->save(); $products[] = $variation->get_id();
    wc_get_product( $products[0] )->delete( true );
    $assert( get_post_type( $attachment ) === 'attachment', 'Shared attachment was deleted.' );
    $assert( file_exists( $path ) && hash_file( 'sha256', $path ) === $hash, 'Shared file changed or disappeared.' );
    $assert( (int) wc_get_product( $products[1] )->get_image_id() === $attachment, 'Other featured image lost its reference.' );
    $assert( wc_get_product( $products[2] )->get_gallery_image_ids() === [ $attachment ], 'Gallery reference disappeared.' );
    $assert( (int) wc_get_product( $variation->get_id() )->get_image_id() === $attachment, 'Variation image disappeared.' );
    wc_get_product( $products[2] )->delete( true );
    $assert( get_post( $attachment ) && file_exists( $path ), 'Gallery product deletion removed shared media.' );
    echo "PASS: {$checks} shared-media retention checks.\n";
} finally {
    foreach ( array_reverse( $products ) as $id ) {
        $p = wc_get_product( $id );
        if ( $p ) { $p->set_image_id( 0 ); $p->set_gallery_image_ids( [] ); $p->save(); $p->delete( true ); }
    }
    // This attachment and file were created above; no existing media is used.
    if ( is_int( $attachment ) && $attachment > 0 ) wp_delete_attachment( $attachment, true );
    if ( $path && file_exists( $path ) ) unlink( $path );
}

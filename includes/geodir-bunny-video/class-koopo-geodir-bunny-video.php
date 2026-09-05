<?php
/**
 * Koopo bridge for GeoDirectory listing videos uploaded to Bunny Stream.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Geodir_Bunny_Video {
    const FIELD_KEY = 'koopo_bunny_videos';
    const META_KEY  = '_koopo_geodir_bunny_videos';
    const NONCE     = 'koopo_geodir_bunny_video';

    private static $instance = null;
    private static $rendered = false;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_detail_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        add_filter( 'geodir_custom_fields_predefined', array( $this, 'register_predefined_field' ), 20, 2 );
        add_filter( 'geodir_custom_field_input_html_' . self::FIELD_KEY, array( $this, 'render_form_field' ), 20, 2 );
        add_action( 'geodir_after_custom_form_field_video', array( $this, 'render_fallback_after_video_field' ), 20, 3 );
        add_action( 'geodir_post_saved', array( $this, 'save_listing_videos' ), 20, 4 );

        add_filter( 'geodir_single_post_tab_content', array( $this, 'append_to_video_tab' ), 20, 3 );
        add_filter( 'geodir_single_post_tabs_array', array( $this, 'ensure_video_tab' ), 20, 2 );
    }

    public function register_assets() {
        wp_register_style(
            'koopo-geodir-bunny-video',
            plugins_url( 'assets/css/koopo-geodir-bunny-video.css', dirname( dirname( __DIR__ ) ) . '/koopo.php' ),
            array(),
            $this->asset_version( 'assets/css/koopo-geodir-bunny-video.css' )
        );

        if ( ! wp_script_is( 'koopo-video-tus', 'registered' ) ) {
            wp_register_script(
                'koopo-video-tus',
                'https://cdn.jsdelivr.net/npm/tus-js-client@4.1.0/dist/tus.min.js',
                array(),
                '4.1.0',
                true
            );
        }

        wp_register_script(
            'koopo-geodir-bunny-video',
            plugins_url( 'assets/js/koopo-geodir-bunny-video.js', dirname( dirname( __DIR__ ) ) . '/koopo.php' ),
            array( 'koopo-video-tus' ),
            $this->asset_version( 'assets/js/koopo-geodir-bunny-video.js' ),
            true
        );
    }

    public function maybe_enqueue_detail_assets() {
        if ( ! is_singular( $this->supported_post_types() ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( $post_id && ! empty( $this->get_listing_videos( $post_id ) ) ) {
            wp_enqueue_style( 'koopo-geodir-bunny-video' );
        }
    }

    public function register_predefined_field( $fields, $post_type ) {
        if ( ! in_array( $post_type, $this->supported_post_types(), true ) ) {
            return $fields;
        }

        $fields[ self::FIELD_KEY ] = array(
            'field_type'  => 'html',
            'class'       => 'gd-koopo-bunny-videos',
            'icon'        => 'fas fa-video',
            'name'        => __( 'Koopo Bunny Video Upload', 'koopo' ),
            'description' => __( 'Adds a Koopo upload control that stores listing videos in Bunny Stream.', 'koopo' ),
            'single_use'  => self::FIELD_KEY,
            'defaults'    => array(
                'data_type'          => 'TEXT',
                'admin_title'        => __( 'Uploaded Videos', 'koopo' ),
                'frontend_title'     => __( 'Uploaded Videos', 'koopo' ),
                'frontend_desc'      => __( 'Upload videos for this listing.', 'koopo' ),
                'htmlvar_name'       => self::FIELD_KEY,
                'is_active'          => true,
                'for_admin_use'      => false,
                'default_value'      => '',
                'show_in'            => '',
                'is_required'        => false,
                'option_values'      => '',
                'validation_pattern' => '',
                'validation_msg'     => '',
                'required_msg'       => '',
                'field_icon'         => 'fas fa-video',
                'css_class'          => '',
                'cat_sort'           => false,
                'cat_filter'         => false,
                'single_use'         => true,
            ),
        );

        return $fields;
    }

    public function render_fallback_after_video_field( $listing_type = '', $package_id = 0, $field = array() ) {
        if ( self::$rendered || ! in_array( $listing_type, $this->supported_post_types(), true ) ) {
            return;
        }

        echo $this->render_form_field( '', array(
            'frontend_title' => __( 'Uploaded Videos', 'koopo' ),
            'desc'           => __( 'Upload videos to Bunny Stream for this listing.', 'koopo' ),
            'name'           => self::FIELD_KEY,
        ) );
    }

    public function render_form_field( $html, $field ) {
        if ( self::$rendered ) {
            return '';
        }

        self::$rendered = true;

        $post_id = $this->current_form_post_id();
        $videos  = $post_id ? $this->get_listing_videos( $post_id ) : array();

        $this->enqueue_form_assets();

        $config = array(
            'root'          => esc_url_raw( rest_url( 'koopo/v1/geodir/bunny-videos' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'postId'        => $post_id,
            'maxUploadMb'   => $this->max_upload_mb(),
            'acceptedMimes' => $this->allowed_video_mimes(),
            'text'          => array(
                'choose'     => __( 'Choose Video', 'koopo' ),
                'uploading'  => __( 'Uploading...', 'koopo' ),
                'processing' => __( 'Processing', 'koopo' ),
                'ready'      => __( 'Ready', 'koopo' ),
                'failed'     => __( 'Upload failed. Please try again.', 'koopo' ),
                'remove'     => __( 'Remove', 'koopo' ),
            ),
        );

        $title = ! empty( $field['frontend_title'] ) ? (string) $field['frontend_title'] : __( 'Uploaded Videos', 'koopo' );
        $desc  = ! empty( $field['desc'] ) ? (string) $field['desc'] : ( ! empty( $field['frontend_desc'] ) ? (string) $field['frontend_desc'] : '' );

        ob_start();
        ?>
        <div class="geodir_form_row clearfix gd-fieldset-details koopo-gd-bunny-field" data-koopo-gd-bunny-video data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
            <label><?php echo esc_html( $title ); ?></label>
            <div class="koopo-gd-bunny-field__control">
                <input type="file" accept="<?php echo esc_attr( implode( ',', $this->allowed_video_mimes() ) ); ?>" data-koopo-gd-bunny-file hidden />
                <button type="button" class="button koopo-gd-bunny-field__button" data-koopo-gd-bunny-pick><?php esc_html_e( 'Choose Video', 'koopo' ); ?></button>
                <span class="koopo-gd-bunny-field__status" data-koopo-gd-bunny-status></span>
            </div>
            <div class="koopo-gd-bunny-field__progress" data-koopo-gd-bunny-progress hidden>
                <span data-koopo-gd-bunny-progress-bar></span>
            </div>
            <div class="koopo-gd-bunny-field__list" data-koopo-gd-bunny-list></div>
            <input type="hidden" name="<?php echo esc_attr( self::FIELD_KEY ); ?>_payload" value="<?php echo esc_attr( wp_json_encode( $videos ) ); ?>" data-koopo-gd-bunny-payload />
            <?php if ( '' !== trim( $desc ) ) : ?>
                <span class="geodir_message_note"><?php echo esc_html( $desc ); ?></span>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function register_rest_routes() {
        register_rest_route( 'koopo/v1', '/geodir/bunny-videos/init', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => array( $this, 'can_upload_from_request' ),
            'callback'            => array( $this, 'rest_init_upload' ),
        ) );

        register_rest_route( 'koopo/v1', '/geodir/bunny-videos/complete', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => array( $this, 'can_upload_from_request' ),
            'callback'            => array( $this, 'rest_complete_upload' ),
        ) );

        register_rest_route( 'koopo/v1', '/geodir/bunny-videos/remove', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => array( $this, 'can_upload_from_request' ),
            'callback'            => array( $this, 'rest_remove_video' ),
        ) );
    }

    public function can_upload_from_request( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'koopo_gd_video_login_required', __( 'Please log in to upload videos.', 'koopo' ), array( 'status' => 401 ) );
        }

        $post_id = absint( (int) $request->get_param( 'post_id' ) );
        if ( ! $post_id ) {
            return true;
        }

        if ( ! in_array( get_post_type( $post_id ), $this->supported_post_types(), true ) ) {
            return new WP_Error( 'koopo_gd_video_invalid_post_type', __( 'Videos can only be uploaded to supported directory listings.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( $this->can_edit_listing( $post_id ) ) {
            return true;
        }

        return new WP_Error( 'koopo_gd_video_forbidden', __( 'You cannot edit videos for this listing.', 'koopo' ), array( 'status' => 403 ) );
    }

    public function rest_init_upload( WP_REST_Request $request ) {
        $provider = $this->bunny_provider();
        if ( is_wp_error( $provider ) ) {
            return $provider;
        }

        $mime = sanitize_text_field( (string) $request->get_param( 'mime' ) );
        $size = absint( (int) $request->get_param( 'size' ) );
        $name = sanitize_file_name( (string) $request->get_param( 'name' ) );

        $validation = $this->validate_upload( $mime, $size );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $response = $provider->init_upload( array(
            'title' => $name ? $name : sprintf( 'GeoDirectory Video %s', gmdate( 'Y-m-d H:i:s' ) ),
            'mime'  => $mime,
            'size'  => $size,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return new WP_REST_Response( $response, 200 );
    }

    public function rest_complete_upload( WP_REST_Request $request ) {
        $provider_video_id = sanitize_text_field( (string) $request->get_param( 'provider_video_id' ) );
        if ( '' === $provider_video_id ) {
            return new WP_Error( 'koopo_gd_video_missing_id', __( 'Missing Bunny video ID.', 'koopo' ), array( 'status' => 400 ) );
        }

        $item = $this->build_video_item( $provider_video_id, sanitize_text_field( (string) $request->get_param( 'title' ) ) );
        $post_id = absint( (int) $request->get_param( 'post_id' ) );

        if ( $post_id && $this->can_edit_listing( $post_id ) ) {
            $videos   = $this->upsert_video_item( $this->get_listing_videos( $post_id ), $item );
            update_post_meta( $post_id, self::META_KEY, $videos );
        }

        return new WP_REST_Response( $item, 200 );
    }

    public function rest_remove_video( WP_REST_Request $request ) {
        $post_id = absint( (int) $request->get_param( 'post_id' ) );
        $provider_video_id = sanitize_text_field( (string) $request->get_param( 'provider_video_id' ) );

        if ( $post_id && $provider_video_id && $this->can_edit_listing( $post_id ) ) {
            $videos = array_values( array_filter( $this->get_listing_videos( $post_id ), function ( $item ) use ( $provider_video_id ) {
                return empty( $item['provider_video_id'] ) || $item['provider_video_id'] !== $provider_video_id;
            } ) );
            update_post_meta( $post_id, self::META_KEY, $videos );
        }

        return new WP_REST_Response( array( 'removed' => true ), 200 );
    }

    public function save_listing_videos( $data, $gd_post, $post, $update = false ) {
        if ( empty( $post->ID ) || ! in_array( $post->post_type, $this->supported_post_types(), true ) ) {
            return;
        }

        if ( ! isset( $_POST[ self::FIELD_KEY . '_payload' ] ) ) {
            return;
        }

        $raw = wp_unslash( $_POST[ self::FIELD_KEY . '_payload' ] );
        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = array();
        }

        update_post_meta( $post->ID, self::META_KEY, $this->sanitize_video_items( $decoded ) );
    }

    public function append_to_video_tab( $content, $tab, $child = false ) {
        if ( $child || empty( $tab->tab_key ) || 'video' !== (string) $tab->tab_key ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return $content;
        }

        $videos = $this->refresh_listing_videos( $post_id );
        if ( empty( $videos ) ) {
            return $content;
        }

        return $content . $this->render_video_grid( $videos );
    }

    public function ensure_video_tab( $tabs_array, $gd_post ) {
        $post_id = ! empty( $gd_post->ID ) ? absint( (int) $gd_post->ID ) : get_the_ID();
        if ( ! $post_id || empty( $this->get_listing_videos( $post_id ) ) ) {
            return $tabs_array;
        }

        foreach ( $tabs_array as $tab ) {
            if ( ! empty( $tab['tab_key'] ) && 'video' === (string) $tab['tab_key'] ) {
                return $tabs_array;
            }
        }

        $tabs_array[] = array(
            'id'                   => 'koopo_bunny_video',
            'tab_type'             => 'standard',
            'tab_key'              => 'video',
            'tab_name'             => __( 'Video', 'koopo' ),
            'tab_icon'             => 'fas fa-video',
            'tab_content_rendered' => $this->render_video_grid( $this->refresh_listing_videos( $post_id ) ),
        );

        return $tabs_array;
    }

    private function render_video_grid( $videos ) {
        $provider = $this->bunny_provider();
        wp_enqueue_style( 'koopo-geodir-bunny-video' );

        ob_start();
        ?>
        <div class="koopo-gd-bunny-videos">
            <?php foreach ( $videos as $video ) : ?>
                <?php
                $provider_video_id = ! empty( $video['provider_video_id'] ) ? (string) $video['provider_video_id'] : '';
                $status = ! empty( $video['status'] ) ? (string) $video['status'] : 'processing';
                $embed_url = '';
                if ( $provider_video_id && ! is_wp_error( $provider ) ) {
                    $embed_url = method_exists( $provider, 'signed_embed_url' )
                        ? (string) $provider->signed_embed_url( $provider_video_id, false, true )
                        : (string) $provider->embed_url( $provider_video_id, false, true );
                }
                ?>
                <article class="koopo-gd-bunny-video">
                    <?php if ( 'ready' === $status && $embed_url ) : ?>
                        <iframe src="<?php echo esc_url( $embed_url ); ?>" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe>
                    <?php else : ?>
                        <div class="koopo-gd-bunny-video__placeholder">
                            <span><?php echo esc_html( 'failed' === $status ? __( 'Video failed to process.', 'koopo' ) : __( 'Video is processing.', 'koopo' ) ); ?></span>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function build_video_item( $provider_video_id, $title = '' ) {
        $provider = $this->bunny_provider();
        $state = ! is_wp_error( $provider ) ? $provider->fetch_video_state( $provider_video_id ) : array();
        $status = 'processing';
        $urls = array();

        if ( is_array( $state ) ) {
            $status = ! empty( $state['ready'] ) ? 'ready' : ( ! empty( $state['failed'] ) ? 'failed' : 'processing' );
            $urls = ! empty( $state['urls'] ) && is_array( $state['urls'] ) ? $state['urls'] : array();
        }

        return array(
            'provider'          => 'bunny',
            'provider_video_id' => $provider_video_id,
            'status'            => $status,
            'title'             => '' !== $title ? $title : __( 'Uploaded video', 'koopo' ),
            'poster_url'        => ! empty( $urls['poster_url'] ) ? esc_url_raw( $urls['poster_url'] ) : '',
            'hls_url'           => ! empty( $urls['hls_url'] ) ? esc_url_raw( $urls['hls_url'] ) : '',
            'embed_url'         => ! is_wp_error( $provider ) ? esc_url_raw( $provider->embed_url( $provider_video_id, false, true ) ) : '',
            'created_by'        => get_current_user_id(),
            'created_at'        => gmdate( 'c' ),
        );
    }

    private function refresh_listing_videos( $post_id ) {
        $videos = $this->get_listing_videos( $post_id );
        if ( empty( $videos ) ) {
            return array();
        }

        $provider = $this->bunny_provider();
        if ( is_wp_error( $provider ) ) {
            return $videos;
        }

        $changed = false;
        foreach ( $videos as &$video ) {
            if ( empty( $video['provider_video_id'] ) || 'processing' !== (string) $video['status'] ) {
                continue;
            }

            $state = $provider->fetch_video_state( $video['provider_video_id'] );
            if ( is_wp_error( $state ) || ! is_array( $state ) ) {
                continue;
            }

            $new_status = ! empty( $state['ready'] ) ? 'ready' : ( ! empty( $state['failed'] ) ? 'failed' : 'processing' );
            if ( $new_status !== $video['status'] ) {
                $video['status'] = $new_status;
                $changed = true;
            }

            if ( ! empty( $state['urls'] ) && is_array( $state['urls'] ) ) {
                foreach ( array( 'poster_url', 'hls_url' ) as $key ) {
                    if ( ! empty( $state['urls'][ $key ] ) && empty( $video[ $key ] ) ) {
                        $video[ $key ] = esc_url_raw( $state['urls'][ $key ] );
                        $changed = true;
                    }
                }
            }
        }
        unset( $video );

        if ( $changed ) {
            update_post_meta( $post_id, self::META_KEY, $videos );
        }

        return $videos;
    }

    private function get_listing_videos( $post_id ) {
        return $this->sanitize_video_items( get_post_meta( $post_id, self::META_KEY, true ) );
    }

    private function sanitize_video_items( $items ) {
        if ( ! is_array( $items ) ) {
            return array();
        }

        $clean = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['provider_video_id'] ) ) {
                continue;
            }

            $status = ! empty( $item['status'] ) ? sanitize_key( (string) $item['status'] ) : 'processing';
            if ( ! in_array( $status, array( 'uploading', 'processing', 'ready', 'failed' ), true ) ) {
                $status = 'processing';
            }

            $clean[] = array(
                'provider'          => 'bunny',
                'provider_video_id' => sanitize_text_field( (string) $item['provider_video_id'] ),
                'status'            => $status,
                'title'             => ! empty( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : __( 'Uploaded video', 'koopo' ),
                'poster_url'        => ! empty( $item['poster_url'] ) ? esc_url_raw( (string) $item['poster_url'] ) : '',
                'hls_url'           => ! empty( $item['hls_url'] ) ? esc_url_raw( (string) $item['hls_url'] ) : '',
                'embed_url'         => ! empty( $item['embed_url'] ) ? esc_url_raw( (string) $item['embed_url'] ) : '',
                'created_by'        => ! empty( $item['created_by'] ) ? absint( (int) $item['created_by'] ) : get_current_user_id(),
                'created_at'        => ! empty( $item['created_at'] ) ? sanitize_text_field( (string) $item['created_at'] ) : gmdate( 'c' ),
            );
        }

        return $clean;
    }

    private function upsert_video_item( $videos, $item ) {
        foreach ( $videos as $index => $video ) {
            if ( ! empty( $video['provider_video_id'] ) && $video['provider_video_id'] === $item['provider_video_id'] ) {
                $videos[ $index ] = $item;
                return $videos;
            }
        }

        $videos[] = $item;
        return $videos;
    }

    private function enqueue_form_assets() {
        wp_enqueue_style( 'koopo-geodir-bunny-video' );
        wp_enqueue_script( 'koopo-geodir-bunny-video' );
    }

    private function current_form_post_id() {
        foreach ( array( 'pid', 'post', 'post_id' ) as $key ) {
            if ( isset( $_REQUEST[ $key ] ) ) {
                return absint( (int) $_REQUEST[ $key ] );
            }
        }

        $post_id = get_the_ID();
        return $post_id && in_array( get_post_type( $post_id ), $this->supported_post_types(), true ) ? absint( $post_id ) : 0;
    }

    private function can_edit_listing( $post_id ) {
        if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $post_id ) ) {
            return true;
        }

        return get_current_user_id() && (int) get_post_field( 'post_author', $post_id ) === get_current_user_id();
    }

    private function bunny_provider() {
        if ( ! class_exists( 'Koopo_Video_REST' ) || ! class_exists( 'Koopo_Video_Provider_Bunny' ) ) {
            return new WP_Error( 'koopo_video_missing', __( 'Koopo Video must be active to upload Bunny Stream videos.', 'koopo' ), array( 'status' => 500 ) );
        }

        return Koopo_Video_REST::provider_for( 'bunny' );
    }

    private function validate_upload( $mime, $size ) {
        if ( class_exists( 'Koopo_Video_Settings' ) ) {
            return Koopo_Video_Settings::validate_upload_constraints( $mime, $size );
        }

        if ( 0 === strpos( $mime, 'video/' ) ) {
            return true;
        }

        return new WP_Error( 'koopo_gd_video_invalid_mime', __( 'Please choose a valid video file.', 'koopo' ), array( 'status' => 400 ) );
    }

    private function allowed_video_mimes() {
        if ( class_exists( 'Koopo_Video_Settings' ) ) {
            return Koopo_Video_Settings::allowed_video_mimes();
        }

        return array( 'video/mp4', 'video/webm', 'video/quicktime' );
    }

    private function max_upload_mb() {
        if ( class_exists( 'Koopo_Video_Settings' ) ) {
            return Koopo_Video_Settings::max_upload_mb();
        }

        return 1024;
    }

    private function supported_post_types() {
        return apply_filters( 'koopo_geodir_bunny_video_post_types', array( 'gd_place', 'gd_event' ) );
    }

    private function asset_version( $relative_path ) {
        $path = trailingslashit( KOOPO_PATH ) . ltrim( $relative_path, '/' );
        return is_file( $path ) ? (string) filemtime( $path ) : '1';
    }
}

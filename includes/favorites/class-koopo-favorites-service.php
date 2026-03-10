<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Favorites_Service {
    const USER_META_LISTS           = 'koopo_favorites_lists';
    const OPTION_ENABLED_POST_TYPES = 'koopo_favorites_enabled_post_types';
    const DEFAULT_LIST_ID           = 'koopo-default-favorites';

    public function get_default_enabled_post_types() {
        return array( 'post', 'product', 'tribe_events', 'event' );
    }

    public function get_enabled_post_types() {
        $saved = get_option( self::OPTION_ENABLED_POST_TYPES, $this->get_default_enabled_post_types() );

        if ( ! is_array( $saved ) ) {
            $saved = $this->get_default_enabled_post_types();
        }

        $saved = array_values( array_unique( array_filter( array_map( 'sanitize_key', $saved ) ) ) );

        if ( empty( $saved ) ) {
            return array( 'post' );
        }

        return $saved;
    }

    public function is_post_type_enabled( $post_type ) {
        return in_array( sanitize_key( $post_type ), $this->get_enabled_post_types(), true );
    }

    public function is_post_favoritable( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        if ( 'publish' !== $post->post_status ) {
            return false;
        }

        return $this->is_post_type_enabled( $post->post_type );
    }

    public function get_user_lists( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array();
        }

        $lists = get_user_meta( $user_id, self::USER_META_LISTS, true );
        if ( ! is_array( $lists ) ) {
            $lists = array();
        }

        $normalized = array();
        foreach ( $lists as $list ) {
            $normalized[] = $this->normalize_list_data( $list );
        }

        return $this->ensure_default_list( $user_id, $normalized );
    }

    public function get_user_lists_for_response( $user_id, $include_items = true ) {
        $lists = $this->get_user_lists( $user_id );
        $output = array();

        foreach ( $lists as $list ) {
            $output[] = $this->format_list_for_response( $list, $user_id, $include_items );
        }

        return $output;
    }

    public function create_list( $user_id, $name ) {
        $user_id = absint( $user_id );
        $name    = sanitize_text_field( (string) $name );

        if ( ! $user_id ) {
            return new WP_Error( 'invalid_user', __( 'Invalid user.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( '' === $name ) {
            return new WP_Error( 'invalid_name', __( 'List name is required.', 'koopo' ), array( 'status' => 400 ) );
        }

        $lists = $this->get_user_lists( $user_id );

        if ( count( $lists ) >= 100 ) {
            return new WP_Error( 'too_many_lists', __( 'You have reached the maximum number of lists.', 'koopo' ), array( 'status' => 400 ) );
        }

        $list = $this->normalize_list_data(
            array(
                'id'         => wp_generate_uuid4(),
                'name'       => $name,
                'slug'       => $this->generate_share_slug(),
                'is_public'  => false,
                'items'      => array(),
                'created_at' => gmdate( 'c' ),
                'updated_at' => gmdate( 'c' ),
            )
        );

        array_unshift( $lists, $list );
        $this->save_user_lists( $user_id, $lists );

        return $this->format_list_for_response( $list, $user_id, true );
    }

    public function update_list( $user_id, $list_id, $data ) {
        $user_id = absint( $user_id );
        $list_id = sanitize_text_field( (string) $list_id );

        if ( self::DEFAULT_LIST_ID === $list_id && isset( $data['name'] ) ) {
            unset( $data['name'] );
        }

        $lists = $this->get_user_lists( $user_id );
        foreach ( $lists as $index => $list ) {
            if ( $list_id !== $list['id'] ) {
                continue;
            }

            if ( isset( $data['name'] ) ) {
                $name = sanitize_text_field( (string) $data['name'] );
                if ( '' !== $name ) {
                    $list['name'] = $name;
                }
            }

            if ( isset( $data['is_public'] ) ) {
                $list['is_public'] = (bool) $data['is_public'];
                if ( $list['is_public'] && empty( $list['slug'] ) ) {
                    $list['slug'] = $this->generate_share_slug();
                }
            }

            if ( self::DEFAULT_LIST_ID === $list['id'] ) {
                $list['name'] = $this->get_default_list_name();
            }

            $list['updated_at'] = gmdate( 'c' );
            $lists[ $index ] = $this->normalize_list_data( $list );
            $this->save_user_lists( $user_id, $lists );

            return $this->format_list_for_response( $lists[ $index ], $user_id, true );
        }

        return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
    }

    public function delete_list( $user_id, $list_id ) {
        $user_id = absint( $user_id );
        $list_id = sanitize_text_field( (string) $list_id );

        if ( self::DEFAULT_LIST_ID === $list_id ) {
            return new WP_Error( 'cannot_delete_default_list', __( 'The default Favorites list cannot be deleted.', 'koopo' ), array( 'status' => 400 ) );
        }

        $lists  = $this->get_user_lists( $user_id );
        $next   = array();
        $found  = false;

        foreach ( $lists as $list ) {
            if ( $list['id'] === $list_id ) {
                $found = true;
                continue;
            }
            $next[] = $list;
        }

        if ( ! $found ) {
            return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $this->save_user_lists( $user_id, $next );

        return array( 'deleted' => true, 'list_id' => $list_id );
    }

    public function add_item_to_list( $user_id, $list_id, $post_id ) {
        $user_id = absint( $user_id );
        $post_id = absint( $post_id );

        if ( ! $this->is_post_favoritable( $post_id ) ) {
            return new WP_Error( 'post_not_favoritable', __( 'This post type cannot be favorited.', 'koopo' ), array( 'status' => 400 ) );
        }

        $lists = $this->get_user_lists( $user_id );
        foreach ( $lists as $index => $list ) {
            if ( $list['id'] !== $list_id ) {
                continue;
            }

            if ( ! in_array( $post_id, $list['items'], true ) ) {
                $list['items'][] = $post_id;
                $list['items']   = array_values( array_unique( array_map( 'absint', $list['items'] ) ) );
                $list['updated_at'] = gmdate( 'c' );
                $lists[ $index ] = $this->normalize_list_data( $list );
                $this->save_user_lists( $user_id, $lists );
            }

            return $this->format_list_for_response( $lists[ $index ], $user_id, true );
        }

        return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
    }

    public function remove_item_from_list( $user_id, $list_id, $post_id ) {
        $user_id = absint( $user_id );
        $post_id = absint( $post_id );

        $lists = $this->get_user_lists( $user_id );
        foreach ( $lists as $index => $list ) {
            if ( $list['id'] !== $list_id ) {
                continue;
            }

            $list['items'] = array_values(
                array_filter(
                    array_map( 'absint', (array) $list['items'] ),
                    function( $id ) use ( $post_id ) {
                        return $id !== $post_id;
                    }
                )
            );
            $list['updated_at'] = gmdate( 'c' );
            $lists[ $index ] = $this->normalize_list_data( $list );
            $this->save_user_lists( $user_id, $lists );

            return $this->format_list_for_response( $lists[ $index ], $user_id, true );
        }

        return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
    }

    public function get_post_status_for_user( $user_id, $post_id ) {
        $user_id = absint( $user_id );
        $post_id = absint( $post_id );

        $list_ids = array();
        foreach ( $this->get_user_lists( $user_id ) as $list ) {
            if ( in_array( $post_id, $list['items'], true ) ) {
                $list_ids[] = $list['id'];
            }
        }

        return array(
            'post_id'      => $post_id,
            'is_favorited' => ! empty( $list_ids ),
            'list_ids'     => $list_ids,
        );
    }

    public function get_list_by_id( $user_id, $list_id ) {
        $user_id = absint( $user_id );
        $list_id = sanitize_text_field( (string) $list_id );

        foreach ( $this->get_user_lists( $user_id ) as $list ) {
            if ( $list_id === $list['id'] ) {
                return $list;
            }
        }

        return null;
    }

    public function build_share_url( $slug ) {
        $slug = sanitize_title( (string) $slug );
        return add_query_arg( 'koopo_favorites_share', $slug, home_url( '/' ) );
    }

    public function get_public_list_by_slug( $slug ) {
        $slug = sanitize_title( (string) $slug );
        if ( '' === $slug ) {
            return null;
        }

        $users = get_users(
            array(
                'fields'   => array( 'ID', 'display_name' ),
                'meta_key' => self::USER_META_LISTS,
                'number'   => -1,
            )
        );

        foreach ( $users as $user ) {
            $lists = $this->get_user_lists( $user->ID );
            foreach ( $lists as $list ) {
                if ( ! empty( $list['is_public'] ) && $slug === $list['slug'] ) {
                    $formatted = $this->format_list_for_response( $list, $user->ID, true );
                    $formatted['owner'] = array(
                        'id'           => (int) $user->ID,
                        'display_name' => $user->display_name,
                    );
                    return $formatted;
                }
            }
        }

        return null;
    }

    public function publish_list_as_post( $user_id, $list_id, $status = 'draft' ) {
        $user_id = absint( $user_id );
        $list    = $this->get_list_by_id( $user_id, $list_id );

        if ( ! $list ) {
            return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) {
            $status = 'draft';
        }

        $items_html = '';
        foreach ( $this->build_items_payload( $list['items'] ) as $item ) {
            $items_html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a></li>';
        }

        $content = "<p>" . esc_html__( 'Favorite list shared from Koopo.', 'koopo' ) . "</p>";
        $content .= $items_html ? '<ul>' . $items_html . '</ul>' : '<p>' . esc_html__( 'No items in this list.', 'koopo' ) . '</p>';

        $post_id = wp_insert_post(
            array(
                'post_type'    => 'post',
                'post_title'   => wp_strip_all_tags( $list['name'] ),
                'post_content' => $content,
                'post_status'  => $status,
                'post_author'  => $user_id,
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        return array(
            'post_id'  => (int) $post_id,
            'edit_url' => get_edit_post_link( $post_id, '' ),
            'url'      => get_permalink( $post_id ),
            'status'   => get_post_status( $post_id ),
        );
    }

    public function format_list_for_response( $list, $user_id, $include_items = true ) {
        $list = $this->normalize_list_data( $list );

        $response = array(
            'id'         => $list['id'],
            'name'       => $list['name'],
            'slug'       => $list['slug'],
            'is_public'  => (bool) $list['is_public'],
            'is_default' => self::DEFAULT_LIST_ID === $list['id'],
            'items_count'=> count( $list['items'] ),
            'created_at' => $list['created_at'],
            'updated_at' => $list['updated_at'],
            'share_url'  => $list['is_public'] ? $this->build_share_url( $list['slug'] ) : '',
            'owner_id'   => (int) $user_id,
        );

        if ( $include_items ) {
            $response['items'] = $this->build_items_payload( $list['items'] );
        }

        return $response;
    }

    private function save_user_lists( $user_id, $lists ) {
        update_user_meta( $user_id, self::USER_META_LISTS, array_values( $lists ) );
    }

    private function normalize_list_data( $list ) {
        $list = is_array( $list ) ? $list : array();

        $items = isset( $list['items'] ) && is_array( $list['items'] ) ? $list['items'] : array();
        $items = array_values( array_unique( array_filter( array_map( 'absint', $items ) ) ) );

        $id   = ! empty( $list['id'] ) ? sanitize_text_field( (string) $list['id'] ) : wp_generate_uuid4();
        $name = ! empty( $list['name'] ) ? sanitize_text_field( (string) $list['name'] ) : $this->get_default_list_name();

        if ( self::DEFAULT_LIST_ID === $id ) {
            $name = $this->get_default_list_name();
        }

        return array(
            'id'         => $id,
            'name'       => $name,
            'slug'       => ! empty( $list['slug'] ) ? sanitize_title( (string) $list['slug'] ) : $this->generate_share_slug(),
            'is_public'  => ! empty( $list['is_public'] ),
            'items'      => $items,
            'created_at' => ! empty( $list['created_at'] ) ? sanitize_text_field( (string) $list['created_at'] ) : gmdate( 'c' ),
            'updated_at' => ! empty( $list['updated_at'] ) ? sanitize_text_field( (string) $list['updated_at'] ) : gmdate( 'c' ),
        );
    }

    private function ensure_default_list( $user_id, $lists ) {
        $default_index = null;
        $changed       = false;

        foreach ( $lists as $index => $list ) {
            if ( self::DEFAULT_LIST_ID !== $list['id'] ) {
                continue;
            }

            $default_index = $index;
            if ( $list['name'] !== $this->get_default_list_name() ) {
                $list['name'] = $this->get_default_list_name();
                $lists[ $index ] = $this->normalize_list_data( $list );
                $changed = true;
            }
            break;
        }

        if ( null === $default_index ) {
            array_unshift( $lists, $this->get_default_list_data() );
            $changed = true;
        } elseif ( 0 !== $default_index ) {
            $default = $lists[ $default_index ];
            unset( $lists[ $default_index ] );
            array_unshift( $lists, $default );
            $lists   = array_values( $lists );
            $changed = true;
        }

        if ( $changed ) {
            $this->save_user_lists( $user_id, $lists );
        }

        return $lists;
    }

    private function get_default_list_name() {
        return __( 'Favorites', 'koopo' );
    }

    private function get_default_list_data() {
        return $this->normalize_list_data(
            array(
                'id'         => self::DEFAULT_LIST_ID,
                'name'       => $this->get_default_list_name(),
                'slug'       => $this->generate_share_slug(),
                'is_public'  => false,
                'items'      => array(),
                'created_at' => gmdate( 'c' ),
                'updated_at' => gmdate( 'c' ),
            )
        );
    }

    private function generate_share_slug() {
        return sanitize_title( wp_generate_password( 10, false, false ) );
    }

    private function build_items_payload( $post_ids ) {
        $items = array();
        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $this->is_post_favoritable( $post_id ) ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            $thumb = get_the_post_thumbnail_url( $post_id, 'medium' );

            $items[] = array(
                'post_id'    => $post_id,
                'title'      => get_the_title( $post_id ),
                'post_type'  => $post->post_type,
                'url'        => get_permalink( $post_id ),
                'date_gmt'   => get_post_field( 'post_date_gmt', $post_id ),
                'thumbnail'  => $thumb ? esc_url_raw( $thumb ) : '',
            );
        }

        return $items;
    }
}

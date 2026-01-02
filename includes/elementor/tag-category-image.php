<?php
namespace Koopo\Elementor\Tags;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

if ( ! defined( 'ABSPATH' ) ) exit;

class Woo_Category_Image_Tag extends Data_Tag {

    public function get_name() {
        return 'koopo-woo-category-image';
    }

    public function get_title() {
        return 'Woo Category Image (Koopo)';
    }

    public function get_group() {
        return 'site';
    }

    public function get_categories() {
        return [ TagsModule::IMAGE_CATEGORY ];
    }

    public function get_value( array $options = [] ) {
        $attachment_id = null;

        if ( is_product_category() ) {
            $term = get_queried_object();
            $attachment_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
        }

        // If category image exists
        if ( $attachment_id ) {
            $url = wp_get_attachment_url( $attachment_id );
            return [
                'url' => esc_url( $url ),
                'id'  => $attachment_id,
                'alt' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            ];
        }

        // If fallback default image is set
        $fallback_url = get_option( 'koopo_default_cat_image' );
        if ( $fallback_url ) {
            return [
                'url' => esc_url( $fallback_url ),
                'id'  => '',
                'alt' => 'Default Woo category image',
            ];
        }

        return [
            'url' => '',
            'id'  => '',
            'alt' => '',
        ];
    }

    public function render() {
        $value = $this->get_value();
        if ( ! empty( $value['url'] ) ) {
            echo esc_url( $value['url'] );
        }
    }
}
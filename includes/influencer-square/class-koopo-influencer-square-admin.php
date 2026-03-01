<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Influencer_Square_Admin {
    private $analytics;

    public function __construct( Koopo_Influencer_Square_Analytics $analytics ) {
        $this->analytics = $analytics;
    }

    public function hooks() {
        add_action( 'koopo_admin_register_submenus', array( $this, 'register_submenu' ), 15, 2 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_submenu( $parent_slug, $capability ) {
        add_submenu_page(
            $parent_slug,
            __( 'Influencer Square', 'koopo' ),
            __( 'Influencer Square', 'koopo' ),
            $capability,
            'koopo-influencer-square',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'koopo_influencer_square_group',
            Koopo_Influencer_Square_Analytics::OPTION_REVENUE_SHARE,
            array(
                'sanitize_callback' => array( $this, 'sanitize_toggle' ),
                'default'           => 1,
            )
        );

        register_setting(
            'koopo_influencer_square_group',
            Koopo_Influencer_Square_Analytics::OPTION_AD_RPM,
            array(
                'sanitize_callback' => array( $this, 'sanitize_non_negative_float' ),
                'default'           => 8.0,
            )
        );

        register_setting(
            'koopo_influencer_square_group',
            Koopo_Influencer_Square_Analytics::OPTION_CREATOR_SHARE,
            array(
                'sanitize_callback' => array( $this, 'sanitize_share_percent' ),
                'default'           => 40.0,
            )
        );
    }

    public function sanitize_non_negative_float( $value ) {
        $number = is_numeric( $value ) ? (float) $value : 0.0;
        return max( 0.0, round( $number, 4 ) );
    }

    public function sanitize_share_percent( $value ) {
        $number = is_numeric( $value ) ? (float) $value : 0.0;
        $number = max( 0.0, $number );
        return min( 100.0, round( $number, 4 ) );
    }

    public function sanitize_toggle( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $global            = $this->analytics->get_global_analytics();
        $selected_author   = isset( $_GET['author_id'] ) ? absint( $_GET['author_id'] ) : 0;
        $selected_analytics = $selected_author ? $this->analytics->get_author_analytics( $selected_author ) : array();
        ?>
        <div class="wrap koopo-is-admin">
            <h1><?php esc_html_e( 'Influencer Square', 'koopo' ); ?></h1>
            <p><?php esc_html_e( 'Track article growth, engagement, and payout estimates for creators.', 'koopo' ); ?></p>

            <style>
                .koopo-is-admin .koopo-is-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); margin: 18px 0; }
                .koopo-is-admin .koopo-is-card { border: 1px solid #dcdcde; border-radius: 10px; padding: 14px; background: #fff; }
                .koopo-is-admin .koopo-is-card h3 { margin: 0 0 6px; font-size: 13px; color: #50575e; }
                .koopo-is-admin .koopo-is-card strong { font-size: 24px; display: block; line-height: 1.2; }
                .koopo-is-admin .koopo-is-panel { border: 1px solid #dcdcde; border-radius: 10px; padding: 18px; margin: 16px 0; background: #fff; }
                .koopo-is-admin .koopo-is-table-wrap { overflow-x: auto; }
                .koopo-is-admin .koopo-is-note { color: #50575e; margin-top: 10px; }
                .koopo-is-admin .koopo-is-actions { display: flex; gap: 10px; align-items: center; margin-top: 12px; }
                .koopo-is-admin .koopo-is-money { color: #06752f; font-weight: 600; }
                .koopo-is-admin .koopo-is-switch { display: inline-flex; align-items: center; gap: 10px; }
                .koopo-is-admin .koopo-is-switch input { display: none; }
                .koopo-is-admin .koopo-is-switch-ui { width: 46px; height: 26px; border-radius: 999px; background: #c3c6cc; position: relative; display: inline-block; }
                .koopo-is-admin .koopo-is-switch-ui:before { content: ""; position: absolute; width: 20px; height: 20px; border-radius: 50%; background: #fff; top: 3px; left: 3px; transition: transform .18s ease; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
                .koopo-is-admin .koopo-is-switch input:checked + .koopo-is-switch-ui { background: #1d7f3f; }
                .koopo-is-admin .koopo-is-switch input:checked + .koopo-is-switch-ui:before { transform: translateX(20px); }
            </style>

            <div class="koopo-is-panel">
                <h2><?php esc_html_e( 'Revenue Model Settings', 'koopo' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'koopo_influencer_square_group' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Revenue Sharing', 'koopo' ); ?></th>
                            <td>
                                <label class="koopo-is-switch" for="koopo_is_revenue_sharing_enabled">
                                    <input type="hidden" name="<?php echo esc_attr( Koopo_Influencer_Square_Analytics::OPTION_REVENUE_SHARE ); ?>" value="0" />
                                    <input type="checkbox" id="koopo_is_revenue_sharing_enabled" name="<?php echo esc_attr( Koopo_Influencer_Square_Analytics::OPTION_REVENUE_SHARE ); ?>" value="1" <?php checked( $this->analytics->is_revenue_sharing_enabled() ); ?> />
                                    <span class="koopo-is-switch-ui" aria-hidden="true"></span>
                                    <span><?php echo $this->analytics->is_revenue_sharing_enabled() ? esc_html__( 'Enabled', 'koopo' ) : esc_html__( 'Disabled', 'koopo' ); ?></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="koopo_is_ad_rpm"><?php esc_html_e( 'Ad Revenue RPM (per 1000 views)', 'koopo' ); ?></label></th>
                            <td>
                                <input type="number" step="0.01" min="0" id="koopo_is_ad_rpm" name="<?php echo esc_attr( Koopo_Influencer_Square_Analytics::OPTION_AD_RPM ); ?>" value="<?php echo esc_attr( $this->analytics->get_ad_rpm() ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="koopo_is_creator_share_percent"><?php esc_html_e( 'Creator Share %', 'koopo' ); ?></label></th>
                            <td>
                                <input type="number" step="0.01" min="0" max="100" id="koopo_is_creator_share_percent" name="<?php echo esc_attr( Koopo_Influencer_Square_Analytics::OPTION_CREATOR_SHARE ); ?>" value="<?php echo esc_attr( $this->analytics->get_creator_share_percent() ); ?>" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Revenue Settings', 'koopo' ) ); ?>
                </form>
                <p class="koopo-is-note"><?php esc_html_e( 'Revenue and payout are currently estimated from views and your RPM/share settings. You can later replace this with real ad network revenue.', 'koopo' ); ?></p>
            </div>

            <div class="koopo-is-panel">
                <h2><?php esc_html_e( 'Global Performance', 'koopo' ); ?></h2>
                <div class="koopo-is-grid">
                    <?php $this->metric_card( __( 'Authors', 'koopo' ), number_format_i18n( (int) $global['totals']['authors'] ) ); ?>
                    <?php $this->metric_card( __( 'Articles', 'koopo' ), number_format_i18n( (int) $global['totals']['articles'] ) ); ?>
                    <?php $this->metric_card( __( 'Views', 'koopo' ), number_format_i18n( (int) $global['totals']['views'] ) ); ?>
                    <?php $this->metric_card( __( 'Comments', 'koopo' ), number_format_i18n( (int) $global['totals']['comments'] ) ); ?>
                    <?php $this->metric_card( __( 'Likes', 'koopo' ), number_format_i18n( (int) $global['totals']['likes'] ) ); ?>
                    <?php $this->metric_card( __( 'Dislikes', 'koopo' ), number_format_i18n( (int) $global['totals']['dislikes'] ) ); ?>
                    <?php $this->metric_card( __( 'Estimated Revenue', 'koopo' ), '$' . number_format_i18n( (float) $global['totals']['estimated_revenue'], 2 ) ); ?>
                    <?php $this->metric_card( __( 'Creator Share', 'koopo' ), '$' . number_format_i18n( (float) $global['totals']['creator_share'], 2 ) ); ?>
                </div>
            </div>

            <div class="koopo-is-panel">
                <h2><?php esc_html_e( 'Author Analytics', 'koopo' ); ?></h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="koopo-influencer-square" />
                    <label for="koopo-is-author"><?php esc_html_e( 'Select creator', 'koopo' ); ?></label>
                    <select id="koopo-is-author" name="author_id">
                        <option value="0"><?php esc_html_e( 'Choose an author', 'koopo' ); ?></option>
                        <?php foreach ( $global['authors'] as $author_row ) : ?>
                            <option value="<?php echo esc_attr( (int) $author_row['author_id'] ); ?>" <?php selected( $selected_author, (int) $author_row['author_id'] ); ?>>
                                <?php echo esc_html( $author_row['display_name'] . ' (' . number_format_i18n( (int) $author_row['views'] ) . ' views)' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="koopo-is-actions">
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Load Analytics', 'koopo' ); ?></button>
                    </span>
                </form>

                <?php if ( ! empty( $selected_analytics ) ) : ?>
                    <h3>
                        <?php
                        printf(
                            /* translators: %s is author display name */
                            esc_html__( 'Totals for %s', 'koopo' ),
                            esc_html( $selected_analytics['author']['display_name'] )
                        );
                        ?>
                    </h3>
                    <div class="koopo-is-grid">
                        <?php $this->metric_card( __( 'Articles', 'koopo' ), number_format_i18n( (int) $selected_analytics['totals']['articles'] ) ); ?>
                        <?php $this->metric_card( __( 'Views', 'koopo' ), number_format_i18n( (int) $selected_analytics['totals']['views'] ) ); ?>
                        <?php $this->metric_card( __( 'Comments', 'koopo' ), number_format_i18n( (int) $selected_analytics['totals']['comments'] ) ); ?>
                        <?php $this->metric_card( __( 'Likes', 'koopo' ), number_format_i18n( (int) $selected_analytics['totals']['likes'] ) ); ?>
                        <?php $this->metric_card( __( 'Dislikes', 'koopo' ), number_format_i18n( (int) $selected_analytics['totals']['dislikes'] ) ); ?>
                        <?php $this->metric_card( __( 'Est. Revenue', 'koopo' ), '$' . number_format_i18n( (float) $selected_analytics['totals']['estimated_revenue'], 2 ) ); ?>
                        <?php $this->metric_card( __( 'Creator Share', 'koopo' ), '$' . number_format_i18n( (float) $selected_analytics['totals']['creator_share'], 2 ) ); ?>
                    </div>

                    <div class="koopo-is-table-wrap">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Article', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Views', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Comments', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Likes', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Dislikes', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Revenue', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Creator Share', 'koopo' ); ?></th>
                                    <th><?php esc_html_e( 'Source', 'koopo' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ( empty( $selected_analytics['articles'] ) ) : ?>
                                <tr><td colspan="8"><?php esc_html_e( 'No published articles yet.', 'koopo' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $selected_analytics['articles'] as $article ) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url( $article['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $article['title'] ); ?></a>
                                            <?php if ( ! empty( $article['edit_url'] ) ) : ?>
                                                <div><a href="<?php echo esc_url( $article['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'koopo' ); ?></a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( number_format_i18n( (int) $article['views'] ) ); ?></td>
                                        <td><?php echo esc_html( number_format_i18n( (int) $article['comments'] ) ); ?></td>
                                        <td><?php echo esc_html( number_format_i18n( (int) $article['likes'] ) ); ?></td>
                                        <td><?php echo esc_html( number_format_i18n( (int) $article['dislikes'] ) ); ?></td>
                                        <td class="koopo-is-money"><?php echo esc_html( '$' . number_format_i18n( (float) $article['estimated_revenue'], 2 ) ); ?></td>
                                        <td class="koopo-is-money"><?php echo esc_html( '$' . number_format_i18n( (float) $article['creator_share'], 2 ) ); ?></td>
                                        <td><?php echo esc_html( ucfirst( (string) $article['revenue_source'] ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="koopo-is-note"><?php esc_html_e( 'Choose an author above to view detailed article analytics and payout estimates.', 'koopo' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function metric_card( $label, $value ) {
        ?>
        <div class="koopo-is-card">
            <h3><?php echo esc_html( $label ); ?></h3>
            <strong><?php echo esc_html( $value ); ?></strong>
        </div>
        <?php
    }
}

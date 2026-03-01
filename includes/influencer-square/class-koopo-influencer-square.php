<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-koopo-influencer-square-analytics.php';
require_once __DIR__ . '/class-koopo-influencer-square-rest.php';
require_once __DIR__ . '/class-koopo-influencer-square-admin.php';

class Koopo_Influencer_Square {
    private static $instance = null;
    private $analytics;
    private $rest;
    private $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->analytics = new Koopo_Influencer_Square_Analytics();
        $this->rest      = new Koopo_Influencer_Square_REST( $this->analytics );
        $this->admin     = new Koopo_Influencer_Square_Admin( $this->analytics );

        add_action( 'wp', array( $this, 'track_single_post_view' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'the_content', array( $this, 'inject_reaction_ui' ), 20 );
        add_shortcode( 'koopo_influencer_square_dashboard', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'influencerSquareDashboard', array( $this, 'render_dashboard_shortcode' ) );

        $this->rest->hooks();
        $this->admin->hooks();
    }

    public function track_single_post_view() {
        $this->analytics->maybe_track_view_from_request();
    }

    public function enqueue_assets() {
        if ( ! is_singular( $this->analytics->get_trackable_post_types() ) ) {
            return;
        }

        wp_enqueue_style(
            'koopo-influencer-square-reactions',
            plugins_url( 'assets/influencer-square-reactions.css', __FILE__ ),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'koopo-influencer-square-reactions',
            plugins_url( 'assets/influencer-square-reactions.js', __FILE__ ),
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            'koopo-influencer-square-reactions',
            'KoopoInfluencerSquare',
            array(
                'restBase'   => esc_url_raw( rest_url( 'koopo/v1/influencer-square' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl'   => wp_login_url( get_permalink() ),
                'messages'   => array(
                    'loginRequired' => __( 'Please log in to react to this article.', 'koopo' ),
                    'error'         => __( 'Something went wrong. Please try again.', 'koopo' ),
                ),
            )
        );
    }

    public function inject_reaction_ui( $content ) {
        if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        if ( ! is_singular( $this->analytics->get_trackable_post_types() ) ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id || ! $this->analytics->is_trackable_post( $post_id ) ) {
            return $content;
        }

        $stats = $this->analytics->get_post_stats( $post_id, get_current_user_id() );
        $ui    = $this->render_reaction_ui( $stats );

        return $ui . $content . $ui;
    }

    public function render_dashboard_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'author_id' => 0,
            ),
            (array) $atts,
            'koopo_influencer_square_dashboard'
        );

        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<div class="koopo-is-dashboard-login"><p>' . esc_html__( 'Please log in to view your Influencer Square analytics.', 'koopo' ) . '</p><p><a class="button" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log In', 'koopo' ) . '</a></p></div>';
        }

        $current_user_id = get_current_user_id();
        $author_id       = absint( $atts['author_id'] );

        if ( ! $author_id ) {
            $author_id = $current_user_id;
        }

        if ( $author_id !== $current_user_id && ! current_user_can( 'manage_options' ) ) {
            $author_id = $current_user_id;
        }

        $analytics = $this->analytics->get_author_analytics( $author_id );
        if ( empty( $analytics ) ) {
            return '<div class="koopo-is-dashboard-empty"><p>' . esc_html__( 'No analytics found for this author.', 'koopo' ) . '</p></div>';
        }

        wp_enqueue_style(
            'koopo-influencer-square-dashboard',
            plugins_url( 'assets/influencer-square-dashboard.css', __FILE__ ),
            array(),
            '1.0.0'
        );

        $totals                  = $analytics['totals'];
        $author                  = $analytics['author'];
        $revenue_sharing_enabled = ! empty( $analytics['settings']['revenue_sharing_enabled'] );
        $is_admin_viewer         = current_user_can( 'manage_options' );
        $colspan                 = $revenue_sharing_enabled ? 7 : 5;

        ob_start();
        ?>
        <section class="koopo-is-dashboard">
            <header class="koopo-is-dashboard__hero">
                <h2><?php esc_html_e( 'Influencer Square Dashboard', 'koopo' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s is author display name */
                        esc_html__( 'Analytics for %s', 'koopo' ),
                        esc_html( $author['display_name'] )
                    );
                    ?>
                </p>
            </header>

            <div class="koopo-is-dashboard__cards">
                <?php echo $this->dashboard_card( __( 'Total Views', 'koopo' ), number_format_i18n( (int) $totals['views'] ) ); ?>
                <?php echo $this->dashboard_card( __( 'Articles', 'koopo' ), number_format_i18n( (int) $totals['articles'] ) ); ?>
                <?php echo $this->dashboard_card( __( 'Comments', 'koopo' ), number_format_i18n( (int) $totals['comments'] ) ); ?>
                <?php echo $this->dashboard_card( __( 'Likes', 'koopo' ), number_format_i18n( (int) $totals['likes'] ) ); ?>
                <?php echo $this->dashboard_card( __( 'Dislikes', 'koopo' ), number_format_i18n( (int) $totals['dislikes'] ) ); ?>
                <?php if ( $revenue_sharing_enabled ) : ?>
                    <?php echo $this->dashboard_card( __( 'Est. Revenue', 'koopo' ), '$' . number_format_i18n( (float) $totals['estimated_revenue'], 2 ), 'money' ); ?>
                    <?php echo $this->dashboard_card( __( 'Potential Profit Share', 'koopo' ), '$' . number_format_i18n( (float) $totals['creator_share'], 2 ), 'money' ); ?>
                <?php endif; ?>
            </div>

            <?php if ( $revenue_sharing_enabled ) : ?>
                <div class="koopo-is-dashboard__meta">
                    <span><?php esc_html_e( 'Revenue Model:', 'koopo' ); ?> <?php echo esc_html( '$' . number_format_i18n( (float) $analytics['settings']['ad_rpm'], 2 ) ); ?> RPM</span>
                    <span><?php esc_html_e( 'Creator Share:', 'koopo' ); ?> <?php echo esc_html( number_format_i18n( (float) $analytics['settings']['creator_share_percent'], 2 ) . '%' ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( $is_admin_viewer ) : ?>
                <div class="koopo-is-dashboard__admin-earnings">
                    <h3><?php esc_html_e( 'Admin Earnings (Platform Share)', 'koopo' ); ?></h3>
                    <?php if ( $revenue_sharing_enabled ) : ?>
                        <p class="koopo-is-dashboard__admin-amount">
                            <?php echo esc_html( '$' . number_format_i18n( (float) $totals['admin_share'], 2 ) ); ?>
                        </p>
                        <p>
                            <?php
                            printf(
                                /* translators: %s is percentage */
                                esc_html__( 'Based on %s%% platform share from estimated article revenue for this dashboard scope.', 'koopo' ),
                                esc_html( number_format_i18n( 100 - (float) $analytics['settings']['creator_share_percent'], 2 ) )
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Revenue sharing is disabled. Revenue and payout values are hidden on this dashboard.', 'koopo' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="koopo-is-dashboard__table-wrap">
                <table class="koopo-is-dashboard__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Article', 'koopo' ); ?></th>
                            <th><?php esc_html_e( 'Views', 'koopo' ); ?></th>
                            <th><?php esc_html_e( 'Comments', 'koopo' ); ?></th>
                            <th><?php esc_html_e( 'Likes', 'koopo' ); ?></th>
                            <th><?php esc_html_e( 'Dislikes', 'koopo' ); ?></th>
                            <?php if ( $revenue_sharing_enabled ) : ?>
                                <th><?php esc_html_e( 'Est. Revenue', 'koopo' ); ?></th>
                                <th><?php esc_html_e( 'Profit Share', 'koopo' ); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $analytics['articles'] ) ) : ?>
                            <tr>
                                <td colspan="<?php echo esc_attr( $colspan ); ?>"><?php esc_html_e( 'No published articles yet.', 'koopo' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $analytics['articles'] as $article ) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url( $article['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $article['title'] ); ?></a>
                                    </td>
                                    <td><?php echo esc_html( number_format_i18n( (int) $article['views'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( (int) $article['comments'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( (int) $article['likes'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( (int) $article['dislikes'] ) ); ?></td>
                                    <?php if ( $revenue_sharing_enabled ) : ?>
                                        <td class="koopo-is-dashboard__money"><?php echo esc_html( '$' . number_format_i18n( (float) $article['estimated_revenue'], 2 ) ); ?></td>
                                        <td class="koopo-is-dashboard__money"><?php echo esc_html( '$' . number_format_i18n( (float) $article['creator_share'], 2 ) ); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php

        return ob_get_clean();
    }

    private function render_reaction_ui( $stats ) {
        $post_id          = (int) $stats['post_id'];
        $likes            = (int) $stats['likes'];
        $dislikes         = (int) $stats['dislikes'];
        $current_reaction = isset( $stats['current_reaction'] ) ? $stats['current_reaction'] : 'none';

        ob_start();
        ?>
        <div class="koopo-is-reactions" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-current-reaction="<?php echo esc_attr( $current_reaction ); ?>">
            <div class="koopo-is-reactions__title"><?php esc_html_e( 'Was this article helpful?', 'koopo' ); ?></div>
            <div class="koopo-is-reactions__buttons">
                <button type="button" class="koopo-is-btn koopo-is-like <?php echo ( 'like' === $current_reaction ) ? 'is-active' : ''; ?>" data-reaction="like">
                    <span class="koopo-is-btn__icon" aria-hidden="true">👍</span>
                    <span class="koopo-is-btn__label"><?php esc_html_e( 'Like', 'koopo' ); ?></span>
                    <span class="koopo-is-count" data-count-for="like"><?php echo esc_html( number_format_i18n( $likes ) ); ?></span>
                </button>
                <button type="button" class="koopo-is-btn koopo-is-dislike <?php echo ( 'dislike' === $current_reaction ) ? 'is-active' : ''; ?>" data-reaction="dislike">
                    <span class="koopo-is-btn__icon" aria-hidden="true">👎</span>
                    <span class="koopo-is-btn__label"><?php esc_html_e( 'Dislike', 'koopo' ); ?></span>
                    <span class="koopo-is-count" data-count-for="dislike"><?php echo esc_html( number_format_i18n( $dislikes ) ); ?></span>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function dashboard_card( $label, $value, $type = 'default' ) {
        $money_class = 'money' === $type ? ' koopo-is-dashboard__card--money' : '';
        ob_start();
        ?>
        <article class="koopo-is-dashboard__card<?php echo esc_attr( $money_class ); ?>">
            <h3><?php echo esc_html( $label ); ?></h3>
            <strong><?php echo esc_html( $value ); ?></strong>
        </article>
        <?php
        return ob_get_clean();
    }
}

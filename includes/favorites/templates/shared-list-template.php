<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>
<main class="koopo-favorites-shared-page">
    <div class="koopo-favorites-shared-page__inner">
        <?php echo do_shortcode( '[koopo_favorites_shared]' ); ?>
    </div>
</main>
<?php
get_footer();

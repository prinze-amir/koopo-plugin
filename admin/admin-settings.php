<?php

// Register the default image setting
add_action( 'admin_menu', function() {
    add_options_page(
        'Woo Category Image Fallback',
        'Woo Category Image Fallback',
        'manage_options',
        'koopo-cat-fallback-settings',
        'koopo_cat_fallback_settings_page'
    );
});

add_action( 'admin_init', function() {
    register_setting( 'koopo_cat_fallback_group', 'koopo_default_cat_image' );
});


// Render the settings page
function koopo_cat_fallback_settings_page() {
    ?>
    <div class="wrap">
        <h1>Woo Category Image Fallback</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'koopo_cat_fallback_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Default Fallback Image URL</th>
                    <td>
                        <input type="text" id="koopo_default_cat_image" name="koopo_default_cat_image" value="<?php echo esc_attr( get_option('koopo_default_cat_image') ); ?>" style="width: 60%;" />
                        <input type="button" class="button" id="upload_default_image" value="Upload Image" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($){
        let mediaUploader;
        $('#upload_default_image').click(function(e) {
            e.preventDefault();
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            mediaUploader = wp.media({
                title: 'Select Default Image',
                button: { text: 'Use This Image' },
                multiple: false
            });
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#koopo_default_cat_image').val(attachment.url);
            });
            mediaUploader.open();
        });
    });
    </script>
    <?php
}
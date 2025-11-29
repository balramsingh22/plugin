<?php
/**
 * Plugin Name: Bulk RTCL Listings (Everywhere + Author Search + Extras)
 * Description: Bulk-create rtcl_listing entries by city. Supports Everywhere (all city terms from rtcl_location), Author live search, Age/Address/Phone/WhatsApp/Website, and Featured Image (upload or URL). Extra fields support {city}/{state} placeholders. Includes thumbnail support + RTCL gallery meta.
 * Version: 1.4.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensure rtcl_listing supports thumbnails and the two gallery sizes exist.
 */
add_action('init', function () {
    // Critical: without this, _thumbnail_id may not be honored for rtcl_listing
    add_post_type_support('rtcl_listing', 'thumbnail');
}, 20);

add_action('after_setup_theme', function () {
    // Register RTCL gallery sizes if theme hasn't already
    // WordPress doesn't have has_image_size(), so we check via global
    global $_wp_additional_image_sizes;

    $has_rtcl_gallery = isset($_wp_additional_image_sizes['rtcl-gallery']);
    $has_rtcl_gallery_thumb = isset($_wp_additional_image_sizes['rtcl-gallery-thumbnail']);

    if ( ! $has_rtcl_gallery ) {
        add_image_size('rtcl-gallery', 1024, 768, true);
    }
    if ( ! $has_rtcl_gallery_thumb ) {
        add_image_size('rtcl-gallery-thumbnail', 150, 150, true);
    }
}, 11);


class BCP_Bulk_RTCL_Listings_Advanced {
    const NONCE_ACTION = 'bcp_rtcl_bulk_create';
    const NONCE_NAME   = 'bcp_rtcl_nonce';

    const CPT          = 'rtcl_listing';
    const TAX_CAT      = 'rtcl_category';
    const TAX_LOC      = 'rtcl_location';

    // Duplicate detection keys
    const META_CITY    = '_bcp_rtcl_city';
    const META_STATE   = '_bcp_rtcl_state';

    // RTCL gallery meta key (common key; adjust if your site has a different one)
    const META_GALLERY = '_rtcl_image_gallery';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_post' ] );

        // Author search UI (admin) and AJAX endpoint
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_bcp_user_search', [ $this, 'ajax_user_search' ] );
    }

    public function register_admin_page() {
        add_management_page(
            'Bulk RTCL Listings',
            'Bulk RTCL Listings',
            'manage_options',
            'bcp-rtcl-bulk-listings',
            [ $this, 'render_admin_page' ]
        );
    }

    private function field( $key, $default = '' ) {
        return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
    }

    private function parse_city_state_lines( $raw ) {
        $lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
        $out = [];
        foreach ( $lines as $line ) {
            $parts = array_map( 'trim', explode( ',', $line, 2 ) );
            $city  = $parts[0] ?? '';
            $state = isset( $parts[1] ) ? $parts[1] : '';
            if ( $city !== '' ) {
                $out[] = [ 'city' => $city, 'state' => $state ];
            }
        }
        return $out;
    }

    private function fetch_all_city_terms() {
        // Fetch all rtcl_location terms (no empty restriction)
        $terms = get_terms( [
            'taxonomy'   => self::TAX_LOC,
            'hide_empty' => false,
        ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        // Map children counts
        $children_map = [];
        foreach ( $terms as $t ) {
            $parent_id = (int) $t->parent;
            if ( ! isset( $children_map[ $parent_id ] ) ) {
                $children_map[ $parent_id ] = 0;
            }
            $children_map[ $parent_id ]++;
        }

        // Leaf terms (no children) treated as City; parent (if exists) as State
        $cities = [];
        $term_index = [];
        foreach ( $terms as $t ) {
            $term_index[ $t->term_id ] = $t; // for quick lookup
        }
        foreach ( $terms as $t ) {
            if ( isset( $children_map[ $t->term_id ] ) ) {
                continue; // not a leaf
            }
            $city_name  = $t->name;
            $state_name = '';
            if ( $t->parent ) {
                $p = $term_index[ $t->parent ] ?? null;
                if ( $p && ! is_wp_error( $p ) ) {
                    $state_name = $p->name;
                }
            }
            $cities[] = [ 'city' => $city_name, 'state' => $state_name ];
        }
        return $cities;
    }

    // Upload via file or fallback to URL sideload. Returns [attachment_id, error_message]
    private function handle_featured_image_from_form(): array {
        $attachment_id = 0;
        $err = '';

        error_log( '[BULK-POST] Starting image upload process...' );

        // Try file upload first
        if ( isset( $_FILES['bcp_featured_image'] ) && ! empty( $_FILES['bcp_featured_image']['name'] ) ) {
            error_log( '[BULK-POST] File upload detected: ' . $_FILES['bcp_featured_image']['name'] );
            
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $res = media_handle_upload( 'bcp_featured_image', 0 );
            if ( is_wp_error( $res ) ) {
                $err = 'Upload failed: ' . $res->get_error_message();
                error_log( '[BULK-POST] File upload FAILED: ' . $err );
            } else {
                $attachment_id = (int) $res;
                error_log( '[BULK-POST] File upload SUCCESS: Attachment ID = ' . $attachment_id );
                return [ $attachment_id, $err ];
            }
        }

        // Fallback: URL sideload
        $image_url = isset( $_POST['bcp_featured_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['bcp_featured_image_url'] ) ) : '';
        if ( $attachment_id === 0 && $image_url ) {
            error_log( '[BULK-POST] URL sideload detected: ' . $image_url );
            
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp = download_url( $image_url );
            if ( is_wp_error( $tmp ) ) {
                $err = $err ? ( $err . ' | ' ) : '';
                $err .= 'Sideload failed: ' . $tmp->get_error_message();
                error_log( '[BULK-POST] download_url FAILED: ' . $err );
                return [ 0, $err ];
            }

            $file = [
                'name'     => basename( parse_url( $image_url, PHP_URL_PATH ) ) ?: 'image.jpg',
                'tmp_name' => $tmp,
            ];
            $overrides = [ 'test_form' => false ];

            $file_array = wp_handle_sideload( $file, $overrides );
            if ( isset( $file_array['error'] ) ) {
                @unlink( $tmp );
                $err = $err ? ( $err . ' | ' ) : '';
                $err .= 'Sideload failed: ' . $file_array['error'];
                error_log( '[BULK-POST] wp_handle_sideload FAILED: ' . $err );
                return [ 0, $err ];
            }

            $attachment = [
                'post_mime_type' => $file_array['type'],
                'post_title'     => sanitize_file_name( wp_basename( $file_array['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            $attachment_id = wp_insert_attachment( $attachment, $file_array['file'] );
            if ( is_wp_error( $attachment_id ) ) {
                $err = $err ? ( $err . ' | ' ) : '';
                $err .= 'Attachment failed: ' . $attachment_id->get_error_message();
                error_log( '[BULK-POST] wp_insert_attachment FAILED: ' . $err );
                return [ 0, $err ];
            }

            error_log( '[BULK-POST] wp_insert_attachment SUCCESS: Attachment ID = ' . $attachment_id );

            // Generate metadata (requires GD/Imagick)
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $file_array['file'] );
            if ( ! empty( $attach_data ) ) {
                wp_update_attachment_metadata( $attachment_id, $attach_data );
                error_log( '[BULK-POST] Attachment metadata generated successfully' );
            } else {
                $err = trim( $err . ' | No attachment metadata generated (check GD/Imagick).' );
                error_log( '[BULK-POST] WARNING: No attachment metadata generated' );
            }

            return [ $attachment_id, $err ];
        }

        error_log( '[BULK-POST] No image uploaded (attachment_id = 0)' );
        return [ $attachment_id, $err ];
    }

    /**
     * Clone/duplicate an attachment for a new post
     * This ensures each listing gets its own image copy
     */
    private function duplicate_attachment( $original_att_id, $new_post_id ) {
        if ( ! $original_att_id || ! $new_post_id ) {
            return 0;
        }

        // Increase limits for bulk operations
        @set_time_limit( 300 ); // 5 minutes per image
        @ini_set( 'memory_limit', '512M' );

        $original_file = get_attached_file( $original_att_id );
        if ( ! $original_file || ! file_exists( $original_file ) ) {
            error_log( sprintf( '[BULK-POST] Original attachment file not found: %s', $original_file ) );
            return 0;
        }

        // Get upload directory
        $upload_dir = wp_upload_dir();
        $filename = basename( $original_file );
        
        // Create unique filename for the copy
        $new_filename = wp_unique_filename( $upload_dir['path'], $filename );
        $new_file = $upload_dir['path'] . '/' . $new_filename;

        // Copy the file
        if ( ! copy( $original_file, $new_file ) ) {
            error_log( '[BULK-POST] Failed to copy attachment file' );
            return 0;
        }

        // Get original attachment post data
        $original_post = get_post( $original_att_id );
        if ( ! $original_post ) {
            @unlink( $new_file );
            return 0;
        }

        // Create new attachment post
        $attachment = [
            'post_mime_type' => $original_post->post_mime_type,
            'post_title'     => $original_post->post_title,
            'post_content'   => $original_post->post_content,
            'post_status'    => 'inherit',
            'post_parent'    => $new_post_id,
        ];

        $new_att_id = wp_insert_attachment( $attachment, $new_file, $new_post_id );
        if ( is_wp_error( $new_att_id ) ) {
            @unlink( $new_file );
            error_log( '[BULK-POST] Failed to create attachment: ' . $new_att_id->get_error_message() );
            return 0;
        }

        // Generate metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $new_att_id, $new_file );
        wp_update_attachment_metadata( $new_att_id, $attach_data );

        // Copy original attachment meta
        update_post_meta( $new_att_id, '_rtcl_attachment_type', 'image' );
        update_post_meta( $new_att_id, '_wp_attachment_image_alt', get_post_meta( $original_att_id, '_wp_attachment_image_alt', true ) );

        error_log( sprintf( '[BULK-POST] Duplicated attachment %d → %d for post %d', $original_att_id, $new_att_id, $new_post_id ) );

        return $new_att_id;
    }

    public function ajax_user_search() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [] );
        }
        if (
            ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bcp_user_search' )
        ) {
            wp_send_json( [] );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( strlen( $term ) < 2 ) {
            wp_send_json( [] );
        }

        $args = [
            'number'  => 20,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'search'  => '*' . esc_attr( $term ) . '*',
            'fields'  => [ 'ID', 'display_name', 'user_email', 'user_login' ],
        ];
        $users = get_users( $args );

        $out = [];
        foreach ( $users as $u ) {
            $out[] = [
                'ID'         => (int) $u->ID,
                'display'    => $u->display_name ?: $u->user_login,
                'user_email' => $u->user_email,
                'user_login' => $u->user_login,
            ];
        }
        wp_send_json( $out );
    }

    public function maybe_handle_post() {
        if ( ! isset( $_POST['bcp_submit'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        // Featured image: file or URL fallback
        list( $attachment_id, $upload_err ) = $this->handle_featured_image_from_form();
        if ( $upload_err ) {
            add_settings_error( 'bcp_rtcl_bulk', 'image_issue', $upload_err, 'warning' );
        }

        $everywhere       = ! empty( $_POST['bcp_everywhere'] );
        $cities_raw       = $this->field( 'bcp_cities' );
        $title_template   = $this->field( 'bcp_title_template', '{city} Escorts Service' );
        $content_template = $this->field( 'bcp_content_template', 'Top-rated {city} escorts in {state}. Book now.' );
        $post_status      = $this->field( 'bcp_status', 'draft' );
        $category_term_id = intval( $this->field( 'bcp_category', 0 ) );

        // Author selection: explicit ID if provided
        $author_id_input = trim( (string) $this->field( 'bcp_author_id', '' ) );
        $author_id = $author_id_input !== '' ? absint( $author_id_input ) : get_current_user_id();
        if ( $author_id > 0 ) {
            $user = get_user_by( 'id', $author_id );
            if ( ! $user ) {
                add_settings_error( 'bcp_rtcl_bulk', 'author_invalid', 'Author ID is invalid. Using current user instead.', 'warning' );
                $author_id = get_current_user_id();
            }
        }

        // Extra fields (support placeholders)
        $age      = $this->field( 'bcp_age', '' );
        $address  = $this->field( 'bcp_address', '' );
        $phone    = $this->field( 'bcp_phone', '' );
        $whatsapp = $this->field( 'bcp_whatsapp', '' );
        $website  = $this->field( 'bcp_website', '' );

        // Compose list of (city, state)
        $city_state_list = $everywhere
            ? $this->fetch_all_city_terms()
            : $this->parse_city_state_lines( $cities_raw );

        $created = [];
        $skipped = [];
        $errors  = [];

        // Log attachment ID status
        error_log( sprintf( '[BULK-POST] Attachment ID for bulk creation: %s', $attachment_id > 0 ? $attachment_id : 'NONE (0)' ) );
        if ( $attachment_id > 0 ) {
            $att_post = get_post( $attachment_id );
            if ( $att_post ) {
                error_log( sprintf( '[BULK-POST] Attachment verified: ID=%d, Type=%s, Status=%s', 
                    $attachment_id, 
                    $att_post->post_type,
                    $att_post->post_status 
                ) );
            } else {
                error_log( '[BULK-POST] WARNING: Attachment ID exists but get_post() returned NULL!' );
                $attachment_id = 0; // Reset to prevent errors
            }
        }

        if ( empty( $city_state_list ) ) {
            add_settings_error( 'bcp_rtcl_bulk', 'no_cities', $everywhere ? 'No city terms found in rtcl_location.' : 'Please provide at least one city.', 'error' );
        } else {
            // Increase limits for bulk operations (600+ listings)
            @set_time_limit( 0 ); // No limit
            @ini_set( 'memory_limit', '1024M' ); // 1GB
            @ini_set( 'max_execution_time', '0' );
            
            error_log( sprintf( '[BULK-POST] Starting bulk creation of %d listings', count( $city_state_list ) ) );
            
            foreach ( $city_state_list as $row ) {
                $city  = $row['city'] ?? '';
                $state = $row['state'] ?? '';
                $result = $this->create_listing(
                    $city,
                    $state,
                    $title_template,
                    $content_template,
                    $post_status,
                    $category_term_id,
                    $author_id,
                    [
                        'age'      => $age,
                        'address'  => $address,
                        'phone'    => $phone,
                        'whatsapp' => $whatsapp,
                        'website'  => $website,
                        'thumb_id' => $attachment_id,
                    ]
                );

                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '%s%s: %s',
                        esc_html( $city ),
                        $state ? ', ' . esc_html( $state ) : '',
                        $result->get_error_message()
                    );
                } elseif ( $result === 'exists' ) {
                    $skipped[] = $state ? "$city, $state" : $city;
                } else {
                    $created[] = $state ? "$city, $state" : $city;
                }
            }
        }

        if ( $created ) {
            add_settings_error( 'bcp_rtcl_bulk', 'created', 'Created: ' . esc_html( implode( ', ', $created ) ), 'updated' );
        }
        if ( $skipped ) {
            add_settings_error( 'bcp_rtcl_bulk', 'skipped', 'Skipped (duplicates): ' . esc_html( implode( ', ', $skipped ) ), 'warning' );
        }
        if ( $errors ) {
            add_settings_error( 'bcp_rtcl_bulk', 'errors', implode( ' | ', array_map( 'esc_html', $errors ) ), 'error' );
        }
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        settings_errors( 'bcp_rtcl_bulk' );

        $selected_status = isset( $_POST['bcp_status'] ) ? sanitize_text_field( wp_unslash( $_POST['bcp_status'] ) ) : 'draft';
        $everywhere      = ! empty( $_POST['bcp_everywhere'] );
        $author_id_val   = isset( $_POST['bcp_author_id'] ) ? sanitize_text_field( wp_unslash( $_POST['bcp_author_id'] ) ) : '';

        ?>
        <div class="wrap">
            <h1>Bulk RTCL Listings</h1>
            <p>
                Create listings in <code><?php echo esc_html( self::CPT ); ?></code> (Classifieds).<br>
                Listings appear at: <code><?php echo esc_html( admin_url( 'edit.php?post_type=' . self::CPT ) ); ?></code><br>
                Placeholders available: <code>{city}</code>, <code>{state}</code> (work in Title, Content, and Extra fields like Address/Phone/WhatsApp/Website).
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">City Source</th>
                        <td>
                            <label>
                                <input type="checkbox" name="bcp_everywhere" value="1" <?php checked( $everywhere, true ); ?>>
                                Everywhere (All city terms from <code><?php echo esc_html( self::TAX_LOC ); ?></code>)
                            </label>
                            <p class="description">When enabled, a listing will be created for every leaf term under the RTCL Location taxonomy. Parent term is treated as State.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_cities">Cities (one per line)</label></th>
                        <td>
                            <textarea id="bcp_cities" name="bcp_cities" rows="10" cols="70" placeholder="City, State
City
Mumbai, Maharashtra
Delhi, Delhi
Bengaluru, Karnataka
Chennai, Tamil Nadu
Kolkata, West Bengal"><?php echo isset( $_POST['bcp_cities'] ) ? esc_textarea( wp_unslash( $_POST['bcp_cities'] ) ) : ''; ?></textarea>
                            <p class="description">Ignored if "Everywhere" is enabled.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_title_template">Title Template</label></th>
                        <td>
                            <input type="text" id="bcp_title_template" name="bcp_title_template" class="regular-text" value="<?php echo isset( $_POST['bcp_title_template'] ) ? esc_attr( wp_unslash( $_POST['bcp_title_template'] ) ) : '{city} Escorts Service'; ?>">
                            <p class="description">Use placeholders: {city}, {state}</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_content_template">Content Template</label></th>
                        <td>
                            <textarea id="bcp_content_template" name="bcp_content_template" rows="6" cols="70" placeholder="Premium {city} escorts in {state}. 24/7 booking."><?php echo isset( $_POST['bcp_content_template'] ) ? esc_textarea( wp_unslash( $_POST['bcp_content_template'] ) ) : 'Premium {city} escorts in {state}. 24/7 booking.'; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_status">Listing Status</label></th>
                        <td>
                            <select id="bcp_status" name="bcp_status">
                                <?php
                                $statuses = [
                                    'publish' => 'Publish',
                                    'draft'   => 'Draft',
                                    'pending' => 'Pending Review',
                                ];
                                foreach ( $statuses as $key => $label ) {
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr( $key ),
                                        selected( $selected_status, $key, false ),
                                        esc_html( $label )
                                    );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_category">RTCL Category (optional)</label></th>
                        <td>
                            <?php
                            wp_dropdown_categories( [
                                'show_option_none' => '— None —',
                                'taxonomy'         => self::TAX_CAT,
                                'name'             => 'bcp_category',
                                'orderby'          => 'name',
                                'hide_empty'       => false,
                                'selected'         => isset( $_POST['bcp_category'] ) ? intval( $_POST['bcp_category'] ) : 0,
                            ] );
                            ?>
                            <p class="description">Assigned to all created listings.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_author_search">Author</label></th>
                        <td>
                            <input type="hidden" id="bcp_author_id" name="bcp_author_id" value="<?php echo esc_attr( $author_id_val ); ?>">
                            <input type="text" id="bcp_author_search" class="regular-text" placeholder="Search by email, username, or name">
                            <ul id="bcp_author_results"></ul>
                            <div id="bcp_author_selected">
                                <?php
                                if ( ! empty( $author_id_val ) ) {
                                    $u = get_user_by( 'id', intval( $author_id_val ) );
                                    if ( $u ) {
                                        echo esc_html( $u->display_name ) . ' (ID: ' . intval( $u->ID ) . ')';
                                    }
                                }
                                ?>
                            </div>
                            <p class="description">Type to search, then click a result to select. The selected User ID will be used as Author.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Extra Fields</th>
                        <td>
                            <p>
                                <label>Age<br>
                                    <input type="text" name="bcp_age" value="<?php echo isset( $_POST['bcp_age'] ) ? esc_attr( wp_unslash( $_POST['bcp_age'] ) ) : ''; ?>" placeholder="e.g., 22">
                                </label>
                            </p>
                            <p>
                                <label>Address<br>
                                    <textarea name="bcp_address" rows="3" cols="60" placeholder="Hotel Area, {city}, {state} – Near Airport"><?php echo isset( $_POST['bcp_address'] ) ? esc_textarea( wp_unslash( $_POST['bcp_address'] ) ) : ''; ?></textarea>
                                </label>
                                <span class="description">Placeholders supported: {city}, {state}</span>
                            </p>
                            <p>
                                <label>Phone<br>
                                    <input type="text" name="bcp_phone" value="<?php echo isset( $_POST['bcp_phone'] ) ? esc_attr( wp_unslash( $_POST['bcp_phone'] ) ) : ''; ?>" placeholder="+91-XXXXXXXXXX">
                                </label>
                                <span class="description">Placeholders supported.</span>
                            </p>
                            <p>
                                <label>WhatsApp Number<br>
                                    <input type="text" name="bcp_whatsapp" value="<?php echo isset( $_POST['bcp_whatsapp'] ) ? esc_attr( wp_unslash( $_POST['bcp_whatsapp'] ) ) : ''; ?>" placeholder="+91-XXXXXXXXXX">
                                </label>
                                <span class="description">Placeholders supported.</span>
                            </p>
                            <p>
                                <label>Website<br>
                                    <input type="url" name="bcp_website" value="<?php echo isset( $_POST['bcp_website'] ) ? esc_attr( wp_unslash( $_POST['bcp_website'] ) ) : ''; ?>" placeholder="https://example.com">
                                </label>
                                <span class="description">Placeholders supported (will be sanitized as URL).</span>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bcp_featured_image">Featured Image (optional)</label></th>
                        <td>
                            <input type="file" id="bcp_featured_image" name="bcp_featured_image" accept="image/*">
                            <p>
                                <label for="bcp_featured_image_url">Or Image URL (fallback)</label><br>
                                <input type="url" id="bcp_featured_image_url" name="bcp_featured_image_url"
                                    class="regular-text" placeholder="https://example.com/image.jpg"
                                    value="<?php echo isset( $_POST['bcp_featured_image_url'] ) ? esc_attr( wp_unslash( $_POST['bcp_featured_image_url'] ) ) : ''; ?>">
                                <span class="description">If file upload fails or is empty, the plugin will try to fetch this URL.</span>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Create Listings', 'primary', 'bcp_submit' ); ?>
            </form>
        </div>
        <?php
    }

    private function create_listing( $city, $state, $title_tpl, $content_tpl, $post_status, $category_term_id, $author_id, $extra ) {
        $city  = sanitize_text_field( (string) $city );
        $state = sanitize_text_field( (string) $state );

        if ( $city === '' ) {
            return new WP_Error( 'bcp_empty_city', 'Empty city provided.' );
        }

        // Duplicate check: Same City + Same Author = SKIP
        // Different City or Different Author = CREATE
        $meta_query = [
            'relation' => 'AND',
            [
                'key'   => self::META_CITY,
                'value' => $city,
            ],
            [
                'key'     => self::META_STATE,
                'value'   => $state,
                'compare' => '=',
            ],
        ];

        // Only block duplicates by the SAME author.
        // Different authors can post the same City/State.
        $exists = get_posts( [
            'post_type'      => self::CPT,
            'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
            'author'         => $author_id,
            'meta_query'     => $meta_query,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        if ( ! empty( $exists ) ) {
            return 'exists'; // Skip duplicate for same user
        }

        // Prepare title/content
        $repl = [
            '{city}'  => $city,
            '{state}' => $state,
        ];
        $title   = strtr( $title_tpl, $repl );
        $content = strtr( $content_tpl, $repl );

        $postarr = [
            'post_title'   => wp_strip_all_tags( $title ),
            'post_content' => wp_kses_post( $content ),
            'post_status'  => in_array( $post_status, [ 'publish', 'draft', 'pending' ], true ) ? $post_status : 'draft',
            'post_type'    => self::CPT,
            'post_author'  => $author_id > 0 ? $author_id : get_current_user_id(),
        ];
        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Assign optional category
        if ( $category_term_id > 0 ) {
            wp_set_object_terms( $post_id, [ $category_term_id ], self::TAX_CAT, false );
        }

        // Ensure rtcl_location (state -> city) assignment
        $assigned_terms = [];
        $parent_term_id = 0;

        if ( $state !== '' ) {
            $state_term = term_exists( $state, self::TAX_LOC );
            if ( 0 === $state_term || null === $state_term ) {
                $state_term = wp_insert_term( $state, self::TAX_LOC, [ 'slug' => sanitize_title( $state ) ] );
            }
            if ( ! is_wp_error( $state_term ) ) {
                $parent_term_id = is_array( $state_term ) ? $state_term['term_id'] : $state_term;
                $assigned_terms[] = intval( $parent_term_id );
            }
        }

        // City term (child of state if present)
        $city_args = [ 'slug' => sanitize_title( $city ) ];
        if ( $parent_term_id ) {
            $city_args['parent'] = $parent_term_id;
        }
        $city_term = term_exists( $city, self::TAX_LOC );
        if ( 0 === $city_term || null === $city_term ) {
            $city_term = wp_insert_term( $city, self::TAX_LOC, $city_args );
        }
        if ( ! is_wp_error( $city_term ) ) {
            $city_term_id = is_array( $city_term ) ? $city_term['term_id'] : $city_term;
            $assigned_terms[] = intval( $city_term_id );
        }

        if ( $assigned_terms ) {
            wp_set_object_terms( $post_id, $assigned_terms, self::TAX_LOC, false );
        }

        // Save extras (support placeholders in these fields)
        $repl = [
            '{city}'  => $city,
            '{state}' => $state,
        ];

        if ( ! empty( $extra['age'] ) ) {
            $age_val = strtr( (string) $extra['age'], $repl );
            update_post_meta( $post_id, 'age', sanitize_text_field( $age_val ) );
        }
        if ( ! empty( $extra['address'] ) ) {
            $addr_val = strtr( (string) $extra['address'], $repl );
            update_post_meta( $post_id, 'address', sanitize_textarea_field( $addr_val ) );
        }
        if ( ! empty( $extra['phone'] ) ) {
            $phone_val = strtr( (string) $extra['phone'], $repl );
            update_post_meta( $post_id, 'phone', sanitize_text_field( $phone_val ) );
        }
        if ( ! empty( $extra['whatsapp'] ) ) {
            $wa_val = strtr( (string) $extra['whatsapp'], $repl );
            update_post_meta( $post_id, '_rtcl_whatsapp_number', sanitize_text_field( $wa_val ) );
        }
        if ( ! empty( $extra['website'] ) ) {
            $web_val = strtr( (string) $extra['website'], $repl );
            update_post_meta( $post_id, 'website', esc_url_raw( $web_val ) );
        }

        // === Featured image + RTCL gallery ===
        if ( ! empty( $extra['thumb_id'] ) && intval( $extra['thumb_id'] ) > 0 ) {
            $original_att_id = intval( $extra['thumb_id'] );

            // CRITICAL: Duplicate the attachment for THIS listing
            // This prevents the post_parent conflict when creating 600+ listings with same image
            try {
                $att_id = $this->duplicate_attachment( $original_att_id, $post_id );
            } catch ( Exception $e ) {
                error_log( sprintf( '[BULK-POST] Exception during duplicate_attachment: %s', $e->getMessage() ) );
                $att_id = 0;
            }

            if ( ! $att_id ) {
                error_log( sprintf( '[BULK-POST] Failed to duplicate attachment for listing %d', $post_id ) );
            } else {
                error_log( sprintf( 
                    '[BULK-POST] Using duplicated image %d for listing %d (City: %s)', 
                    $att_id, 
                    $post_id, 
                    $city 
                ) );

                // Featured image (already set via post_parent in duplicate_attachment)
                $thumb_result = set_post_thumbnail( $post_id, $att_id );
                error_log( sprintf( '[BULK-POST] set_post_thumbnail result: %s', $thumb_result ? 'SUCCESS' : 'FAILED' ) );

                // RTCL gallery meta (slider/templates read this)
                update_post_meta( $post_id, self::META_GALLERY, $att_id );
                error_log( sprintf( '[BULK-POST] Set %s = %d for post %d', self::META_GALLERY, $att_id, $post_id ) );

                // RTCL attachments order (critical for image sorting/display)
                update_post_meta( $post_id, '_rtcl_attachments_order', [ $att_id ] );
                error_log( sprintf( '[BULK-POST] Set _rtcl_attachments_order for post %d', $post_id ) );
                
                // Verify thumbnail was set
                $verify_thumb = get_post_thumbnail_id( $post_id );
                error_log( sprintf( '[BULK-POST] VERIFY: get_post_thumbnail_id(%d) = %s', $post_id, $verify_thumb ?: 'EMPTY' ) );
            }
        } else {
            error_log( sprintf( '[BULK-POST] NO IMAGE for listing %d (thumb_id was empty or 0)', $post_id ) );
        }

        // City/state meta for duplicates
        update_post_meta( $post_id, self::META_CITY, $city );
        update_post_meta( $post_id, self::META_STATE, $state );

        return $post_id;
    }

    public function enqueue_admin_assets( $hook ) {
        // Only load assets on our page
        if ( $hook !== 'tools_page_bcp-rtcl-bulk-listings' ) {
            return;
        }

        // Inline script configuration
        $handle = 'bcp-rtcl-admin';
        wp_register_script( $handle, '', [], false, true );
        wp_enqueue_script( $handle );

        $data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bcp_user_search' ),
        ];
        wp_add_inline_script( $handle, 'window.BCP_RTCL_ADMIN = ' . wp_json_encode( $data ) . ';', 'before' );

        // Author live search script
        $js = <<<JS
(function(){
    const d = document;
    const input = d.querySelector('#bcp_author_search');
    const the_hidden = d.querySelector('#bcp_author_id');
    const results = d.querySelector('#bcp_author_results');
    const selected = d.querySelector('#bcp_author_selected');

    if(!input || !results || !the_hidden) return;

    let timer = null;

    function clearResults(){
        results.innerHTML = '';
        results.style.display = 'none';
    }

    function setSelected(user){
        the_hidden.value = user.ID;
        selected.textContent = user.display + ' (ID: ' + user.ID + ')';
        clearResults();
    }

    function renderResults(list){
        results.innerHTML = '';
        if(!list || !list.length){ clearResults(); return; }
        list.forEach(function(u){
            const li = d.createElement('li');
            li.textContent = u.display + ' — ' + u.user_email + ' (ID: ' + u.ID + ')';
            li.style.cursor = 'pointer';
            li.addEventListener('click', function(){ setSelected(u); });
            results.appendChild(li);
        });
        results.style.display = 'block';
    }

    function search(term){
        const payload = new URLSearchParams();
        payload.set('action', 'bcp_user_search');
        payload.set('nonce', (window.BCP_RTCL_ADMIN && window.BCP_RTCL_ADMIN.nonce) || '');
        payload.set('term', term);

        fetch((window.BCP_RTCL_ADMIN && window.BCP_RTCL_ADMIN.ajax_url) || '', {
            method:'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: payload.toString()
        }).then(r=>r.json()).then(function(res){
            if(res && Array.isArray(res)){ renderResults(res); } else { clearResults(); }
        }).catch(function(){ clearResults(); });
    }

    input.addEventListener('input', function(){
        clearTimeout(timer);
        const term = input.value.trim();
        if(term.length < 2){ clearResults(); return; }
        timer = setTimeout(function(){ search(term); }, 250);
    });

    d.addEventListener('click', function(e){
        if(!results.contains(e.target) && e.target !== input){
            clearResults();
        }
    });
})();
JS;
        wp_add_inline_script( $handle, $js, 'after' );

        // Basic styles for dropdown
        $css = <<<CSS
#bcp_author_results {
    display:none;
    border:1px solid #ccd0d4;
    max-height:220px;
    overflow:auto;
    background:#fff;
    margin-top:4px;
    padding:0;
}
#bcp_author_results li {
    list-style:none;
    margin:0;
    padding:6px 10px;
    border-bottom:1px solid #f0f0f0;
}
#bcp_author_results li:hover {
    background:#f6f7f7;
}
#bcp_author_selected {
    margin-top:6px;
    font-style:italic;
}
CSS;
        wp_register_style( 'bcp-rtcl-admin', false );
        wp_enqueue_style( 'bcp-rtcl-admin' );
        wp_add_inline_style( 'bcp-rtcl-admin', $css );
    }
}

new BCP_Bulk_RTCL_Listings_Advanced();

require_once __DIR__ . '/bulk-promote.php';

<?php
/**
 * Plugin Name: Bulk Promote Listings
 * Description: Adds a "Bulk Promote" feature to the My Account > Listings page.
 * Version: 1.0.0
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RTCL_Bulk_Promote {

    public function __construct() {
        add_action( 'wp_ajax_rtcl_bulk_promote_listings', [ $this, 'bulk_promote_listings' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts() {
        if ( class_exists( '\Rtcl\Helpers\Functions' ) && \Rtcl\Helpers\Functions::is_account_page() ) { // Only load on account pages
            wp_enqueue_script( 'rtcl-bulk-promote', plugin_dir_url( __FILE__ ) . 'bulk-promote.js', [ 'jquery' ], '1.0.0', true );
            wp_localize_script( 'rtcl-bulk-promote', 'rtcl_bulk_promote_vars', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'rtcl_bulk_promote_nonce' ),
                'confirm_msg' => __( 'Are you sure you want to promote all your eligible listings? This will consume your membership credits.', 'classified-listing' )
            ] );
        }
    }

    public function bulk_promote_listings() {
        check_ajax_referer( 'rtcl_bulk_promote_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'You must be logged in.', 'classified-listing' ) );
        }

        $user_id = get_current_user_id();
        
        // Debug log
        error_log( 'Bulk Promote: User ID: ' . $user_id );

        // Get user's membership
        $member = null;
        if ( class_exists( 'RtclStore\Models\Membership' ) ) {
            try {
                $member = rtclStore()->factory->get_membership( $user_id );
            } catch ( Exception $e ) {
                error_log( 'Bulk Promote: Error getting membership: ' . $e->getMessage() );
            }
        }

        if ( ! $member || $member->is_expired() ) {
            wp_send_json_error( __( 'No active membership found. Please check your membership status.', 'classified-listing' ) );
        }

        // Get available promotions
        $promotions = $member->get_promotions();
        if ( empty( $promotions ) ) {
            wp_send_json_error( __( 'No promotions available in your membership.', 'classified-listing' ) );
        }

        // Check if we have the required promotions
        $featured_available = isset( $promotions['featured']['ads'] ) ? intval( $promotions['featured']['ads'] ) : 0;
        $top_available = isset( $promotions['_top']['ads'] ) ? intval( $promotions['_top']['ads'] ) : 0;
        $bump_up_available = isset( $promotions['_bump_up']['ads'] ) ? intval( $promotions['_bump_up']['ads'] ) : 0;
        
        // Fetch User's Listings
        $args = [
            'post_type'      => 'rtcl_listing',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1, // Get all
            'fields'         => 'ids'
        ];
        
        $listings = get_posts( $args );
        
        if ( empty( $listings ) ) {
            wp_send_json_error( __( 'No listings found.', 'classified-listing' ) );
        }

        $promoted_count = 0;
        $skipped_count = 0;

        foreach ( $listings as $post_id ) {
            // Check if already promoted
            $is_top = get_post_meta( $post_id, '_top', true );
            $is_bump_up = get_post_meta( $post_id, '_bump_up', true );
            $is_featured = get_post_meta( $post_id, 'featured', true );

            if ( $is_top && $is_bump_up && $is_featured ) {
                $skipped_count++;
                continue; // Already fully promoted
            }

            // Check if we have enough credits
            if ( $featured_available <= 0 || $top_available <= 0 || $bump_up_available <= 0 ) {
                $skipped_count++;
                continue; // Not enough credits
            }

            // Apply All Promotions: Top, Bump Up, and Featured
            $promotions_data = [];
            
            if ( ! $is_featured && $featured_available > 0 ) {
                $promotions_data['featured'] = isset( $promotions['featured']['validate'] ) ? intval( $promotions['featured']['validate'] ) : 30;
            }
            if ( ! $is_top && $top_available > 0 ) {
                $promotions_data['_top'] = isset( $promotions['_top']['validate'] ) ? intval( $promotions['_top']['validate'] ) : 30;
            }
            if ( ! $is_bump_up && $bump_up_available > 0 ) {
                $promotions_data['_bump_up'] = isset( $promotions['_bump_up']['validate'] ) ? intval( $promotions['_bump_up']['validate'] ) : 30;
            }

            if ( ! empty( $promotions_data ) ) {
                // Apply promotions using the built-in function
                $promotion_status = \Rtcl\Helpers\Functions::update_listing_promotions( $post_id, $promotions_data );
                
                if ( $promotion_status ) {
                    // Deduct credits
                    foreach ( $promotions_data as $promo_key => $promo_validate ) {
                        if ( isset( $promotions[$promo_key]['ads'] ) ) {
                            $promotions[$promo_key]['ads'] = intval( $promotions[$promo_key]['ads'] ) - 1;
                            
                            // Update local counters
                            if ( $promo_key === 'featured' ) {
                                $featured_available--;
                            } elseif ( $promo_key === '_top' ) {
                                $top_available--;
                            } elseif ( $promo_key === '_bump_up' ) {
                                $bump_up_available--;
                            }
                        }
                    }
                    
                    $promoted_count++;
                }
            }
        }

        // Update membership promotions
        if ( $promoted_count > 0 ) {
            $member->update_meta( '_rtcl_promotions', $promotions );
            $member->cacheClear();
        }

        $message = sprintf( 
            __( 'Successfully promoted %d listings with Top, Bump Up, and Featured.', 'classified-listing' ), 
            $promoted_count 
        );
        
        if ( $skipped_count > 0 ) {
            $message .= ' ' . sprintf( __( '%d listings were skipped (already promoted or insufficient credits).', 'classified-listing' ), $skipped_count );
        }

        wp_send_json_success( $message );
    }
}

new RTCL_Bulk_Promote();

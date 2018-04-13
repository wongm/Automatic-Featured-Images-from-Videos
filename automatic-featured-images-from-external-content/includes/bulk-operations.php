<?php
/**
 * Created by PhpStorm.
 * User: garykovar
 * Date: 11/21/16
 * Time: 10:51 PM
 */

/**
 * Admin-ajax kickoff the bulk processing.
 *
 * @author Gary Kovar
 *
 * @since  1.1.0
 */
function mtw_queue_bulk_processing() {

	/**
	 * Allow developers to pass in custom post types to process.
	 *
	 * @since 1.1.0
	 *
	 * @param array $value Array of post types to process.
	 */
	$type_array = apply_filters( 'mtw_featured_images_from_external_content_post_types', array( 'post', 'page' ) );

	if ( ! in_array( $_POST['posttype'], $type_array ) ) {
		return;
	}
	wp_schedule_single_event( time() + 60, 'mtw_bulk_process_external_content_query_init', array( $_POST['posttype'] ) );
}

/**
 * Process the scheduled post-type.
 *
 * If there are more to do when it's done...do it.
 *
 * @author Gary Kovar
 *
 * @since  1.1.0
 *
 * @param string $post_type Post type to process.
 */
function mtw_bulk_process_external_content_query( $post_type ) {

	$post_count = 10;

	$posts_to_process = apply_filters( 'mtw_featured_images_from_external_content_posts_bulk_quantity', $post_count );

	// Check if we have any to process.
	$query = mtw_automatic_featured_images_from_external_content_wp_query( $post_type, $posts_to_process );

	// Process these jokers.
	foreach ( $query->posts as $post_id ) {
		mtw_check_if_content_contains_video( $post_id, get_post( $post_id ) );
	}

	$reschedule_task = mtw_automatic_featured_images_from_external_content_wp_query( $post_type, $posts_to_process );
	if ( $reschedule_task->post_count > 1 ) {
		wp_schedule_single_event( time() + ( 60 * 10 ), 'mtw_bulk_process_video_query_init', array( $post_type ) );
	}
}

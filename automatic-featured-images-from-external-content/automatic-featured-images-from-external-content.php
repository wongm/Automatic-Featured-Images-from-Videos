<?php
/*
 * Plugin Name: Automatic Featured Images from External Content
 * Plugin URI: https://github.com/wongm/automatic-featured-images-from-external-content
 * Description: If a Flickr or Wikipedia image or YouTube or Vimeo video exists in the first few paragraphs of a post, automatically set the post's featured image to that video's thumbnail.
 * Version: 1.2
 * Author: Marcus Wong
 * Author URI: https://github.com/wongm/automatic-featured-images-from-external-content
 * License: GPLv2
 * Text Domain: automatic-featured-images-from-external-content
 *
 * Forked Plugin Name: Automatic Featured Images from YouTube / Vimeo
 * Forked Plugin URI: http://webdevstudios.com
 * Forked Description: If a YouTube or Vimeo video exists in the first few paragraphs of a post, automatically set the post's featured image to that video's thumbnail.
 * Forked Version: 1.1.1
 * Forked Author: WebDevStudios
 * Forked Author URI: http://webdevstudios.com
 * Forked Text Domain: automatic-featured-images-from-videos
 */

/*
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// Used for js loading elsewhere.
define( 'WDSAFI_DIR', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'mtw_load_afi' );

// Check on save if content contains external content.
add_action( 'save_post', 'mtw_check_if_content_contains_external_content', 10, 2 );

// Add a meta box to the post types we are checking for external content on.
add_action( 'add_meta_boxes', 'mtw_register_display_video_metabox' );

// Create an endpoint that receives the params to start bulk processing.
add_action( 'wp_ajax_mtw_queue_bulk_processing', 'mtw_queue_bulk_processing' );

// Handle scheduled bulk request.
add_action( 'mtw_bulk_process_external_content_query_init_init', 'mtw_bulk_process_external_content_query' );

// Slip in the jquery to append the button for bulk processing.
add_action( 'admin_enqueue_scripts', 'mtw_customize_post_buttons' );

// Bulk actions from manage edit posts page
add_filter( 'bulk_actions-edit-post', 'register_bulk_load_featured_image' );

add_filter( 'handle_bulk_actions-edit-post', 'bulk_load_featured_image_action_handler', 10, 3 );

add_action( 'admin_notices', 'bulk_load_featured_image_action_admin_notice' );

/**
 * Register 'Bulk load featured image' action on edit post page
 */
function register_bulk_load_featured_image($bulk_actions) {
  $bulk_actions['bulk_load_featured_image'] = __( 'Bulk load featured image', 'bulk_load_featured_image');
  return $bulk_actions;
}
 
/**
 * Handle 'Bulk load featured image' action from edit post page
 */
function bulk_load_featured_image_action_handler( $redirect_to, $doaction, $post_ids ) {
  if ( $doaction !== 'bulk_load_featured_image' ) {
    return $redirect_to;
  }
  foreach ( $post_ids as $post_id ) {
    echo $post_id;
	$post = get_post($post_id);
	mtw_check_if_content_contains_external_content( $post_id, $post );
	
  }
  $redirect_to = add_query_arg( 'bulk_loaded_featured_image', count( $post_ids ), $redirect_to );
  return $redirect_to;
}
 
/**
 * Display message after 'Bulk load featured image' action is complete
 */
function bulk_load_featured_image_action_admin_notice() {
  if ( ! empty( $_REQUEST['bulk_loaded_featured_image'] ) ) {
    $emailed_count = intval( $_REQUEST['bulk_loaded_featured_image'] );
    printf( '<div id="message" class="updated fade"><p>' .
      _n( 'Updated featured image on %s post.',
        'Updated featured images on %s posts.',
        $emailed_count,
        'bulk_load_featured_image'
      ) . '</p></div>', $emailed_count );
  }
}

/**
 * Load....automatically...LOL.
 *
 * I need tacos. Send help.
 *
 * @since 1.1.0
 */
function mtw_load_afi() {
	require_once( plugin_dir_path( __FILE__ ) . 'includes/ajax.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'includes/bulk-operations.php' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once( plugin_dir_path( __FILE__ ) . 'includes/cli.php' );
	}
}

/**
 * Check if a post contains external content.  Maybe set a thumbnail, store the external content URL as post meta.
 *
 * @author Gary Kovar
 *
 * @since  1.0.5
 *
 * @param int    $post_id ID of the post being saved.
 * @param object $post    Post object.
 */
function mtw_check_if_content_contains_external_content( $post_id, $post ) {

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// We need to prevent trying to assign when trashing or untrashing posts in the list screen.
	// get_current_screen() was not providing a unique enough value to use here.
	if ( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'trash', 'untrash' ) )  ) {
		return;
	}

	$content = isset( $post->post_content ) ? $post->post_content : '';

	/**
	 * Only check the first 800 characters of our post, by default.
	 *
	 * @since 1.0.0
	 *
	 * @param int $value Character limit to search.
	 */
	$content = substr( $content, 0, apply_filters( 'mtw_featured_images_character_limit', 1200 ) );
	
	// Add post except if it exists
	$content = ( isset( $post->post_excerpt ) ? $post->post_excerpt : '' ) . $content;

	// Allow developers to filter the content to allow for searching in postmeta or other places.
	$content = apply_filters( 'mtw_featured_images_from_external_content_filter_content', $content, $post_id );

	// Set the external content id.
	$wongmRailGallery_id = mtw_check_for_wongmRailGallery( $content );
	$railGeelong_id      = mtw_check_for_railGeelong( $content );
	$flickr_id           = mtw_check_for_flickr( $content );
	$wikipedia_id        = mtw_check_for_wikipedia( $content );
	$wordpress_id        = mtw_check_for_wordpress( $content );
	$youtube_id          = mtw_check_for_youtube( $content );
	$vimeo_id            = mtw_check_for_vimeo( $content );
	$external_image_url  = '';
	
	if ( $wongmRailGallery_id ) {
		$external_image_url  = mtw_get_wongmRailGallery_details( $content );
	}
	
	if ( $railGeelong_id && $external_image_url == '' ) {
		$external_image_url  = mtw_get_railGeelong_details( $content );
	}

	if ( $flickr_id && $external_image_url == '' ) {
		$external_image_url  = mtw_get_flickr_details( $content );
	}
	
	if ( $wikipedia_id && $external_image_url == '' ) {
		$external_image_url  = mtw_get_wikipedia_details( $content );
	}

	if ( $youtube_id && $external_image_url == '' ) {
		$youtube_details     = mtw_get_youtube_details( $youtube_id );
		$external_image_url  = $youtube_details['external_image_url'];
		$video_url           = $youtube_details['video_url'];
		$video_embed_url     = $youtube_details['video_embed_url'];
	}

	if ( $vimeo_id && $external_image_url == '' ) {
		$vimeo_details       = mtw_get_vimeo_details( $vimeo_id );
		$external_image_url  = $vimeo_details['external_image_url'];
		$video_url           = $vimeo_details['video_url'];
		$video_embed_url     = $vimeo_details['video_embed_url'];
	}

	if ( $post_id
	     && ! has_post_thumbnail( $post_id )
	     && $content
	     && ( $youtube_details || $vimeo_details || $wongmRailGallery_id || $railGeelong_id || $flickr_id || $wordpress_id || $wikipedia_id )
	) {
		$external_image_id = '';
		
		if ( $wongmRailGallery_id ) {
			$external_image_id = $wongmRailGallery_id;
		}
		if ( $railGeelong_id && $external_image_id == '' ) {
			$external_image_id = $railGeelong_id;
		}
		if ( $flickr_id && $external_image_id == '' ) {
			$external_image_id = $flickr_id;
		}		
		if ( $wordpress_id && $external_image_id == '' ) {
			// Woot! We got an image, so set it as the post thumbnail.
			set_post_thumbnail( $post_id, $wordpress_id );
			return;
		}
		if ( $wikipedia_id && $external_image_id == '' ) {
			$external_image_id = $wikipedia_id;
		}
		if ( $youtube_id && $external_image_id == '' ) {
			$external_image_id = $youtube_id;
		}
		if ( $vimeo_id && $external_image_id == '' ) {
			$external_image_id = $vimeo_id;
		}
		if ( ! wp_is_post_revision( $post_id ) ) {
			
			mtw_set_external_image_as_featured_image( $post_id, $external_image_url, $external_image_id );
		}
	}

	if ( $post_id
	     && $content
	     && ( $youtube_id || $vimeo_id )
	) {
		update_post_meta( $post_id, '_is_video', true );
		update_post_meta( $post_id, '_video_url', $video_url );
		update_post_meta( $post_id, '_video_embed_url', $video_embed_url );
	} else {
		// Need to set because we don't have one, and we can skip on future iterations.
		// Need way to potentially force check ALL.
		update_post_meta( $post_id, '_is_video', false );
		delete_post_meta( $post_id, '_video_url' );
		delete_post_meta( $post_id, '_video_embed_url' );
	}

}

/**
 * If a external content is added in the post content, grab its thumbnail and set it as the featured image.
 *
 * @since 1.0.0
 *
 * @param int    $post_id             ID of the post being saved.
 * @param string $external_image_url URL of the image thumbnail.
 * @param string $external_image_id            External content ID from embed.
 */
function mtw_set_external_image_as_featured_image( $post_id, $external_image_url, $external_image_id = '' ) {

	// Bail if no valid external content URL.
	if ( ! $external_image_url || is_wp_error( $external_image_url ) ) {
		return;
	}

	$post_title = sanitize_title( preg_replace( '/[^a-zA-Z0-9\s]/', '-', get_the_title() ) ) . '-' . $external_image_id;

		//todo fix $post_title
		//echo $post_title;
		//die();
		
	global $wpdb;

	$stmt = "SELECT ID FROM {$wpdb->posts}";
	$stmt .= $wpdb->prepare(
		' WHERE post_type = %s AND guid LIKE %s',
        'attachment',
	    '%' . $wpdb->esc_like( $external_image_id ) . '%'
    );
	$attachment = $wpdb->get_col( $stmt );
	if ( !empty( $attachment[0] ) ) {
		$attachment_id = $attachment[0];
	} else {
		// Try to sideload the image.
		
		
		$attachment_id = mtw_ms_media_sideload_image_with_new_filename( $external_image_url, $post_id, $post_title, $external_image_id );
	}

	// Bail if unable to sideload (happens if the URL or post ID is invalid, or if the URL 404s).
	if ( is_wp_error( $attachment_id ) ) {
		return;
	}

	// Woot! We got an image, so set it as the post thumbnail.
	set_post_thumbnail( $post_id, $attachment_id );
}

function mtw_check_for_wordpress( $content ) {
	if ( preg_match( '#wp-image-([0-9]+)#', $content, $wordpress_matches ) ) {
		return $wordpress_matches[1];
	}

	return false;
}

function mtw_check_for_wikipedia( $content ) {
	if ( preg_match( '#\/\/(upload\.wikimedia\.org\/wikipedia\/commons\/thumb)\/([a-zA-Z0-9\-\_\/\.\%]+)\/([0-9]+)(px-)([a-zA-Z0-9\-\_\.\%]+)\.([a-zA-Z]+)#', $content, $wikipedia_matches ) ) {
		return $wikipedia_matches[5];
	}

	return false;
}

function mtw_get_wikipedia_details( $content ) {
	if ( preg_match( '#\/\/(upload\.wikimedia\.org\/wikipedia\/commons\/thumb)\/([a-zA-Z0-9\-\_\/\.\%]+)\/([0-9]+)(px-)([a-zA-Z0-9\-\_\.\%]+)\.([a-zA-Z]+)#', $content, $wikipedia_matches ) ) {
		return "https://" . $wikipedia_matches[1] . "/" . $wikipedia_matches[2] . "/1024" . $wikipedia_matches[4] . "." . $wikipedia_matches[5] . "." . $wikipedia_matches[6];
	}

	return "";
}

function mtw_check_for_wongmRailGallery( $content ) {
	if ( preg_match( '#\/\/(www\.)?(railgallery.wongm.com)?\/(cache\/)?\/?(\?v=)?([a-zA-Z0-9\-\_\/]+)\/([a-zA-Z0-9\-\_ ]+)_([0-9])+\.([a-zA-Z]+)"#', $content, $wongmRailGallery_matches ) ) {
		return $wongmRailGallery_matches[6];
	}

	return false;
}

function mtw_get_wongmRailGallery_details( $content ) {
	if ( preg_match( '#\/\/(www\.)?(railgallery.wongm.com)?\/(cache\/)?\/?(\?v=)?([a-zA-Z0-9\-\_\/]+)\/([a-zA-Z0-9\-\_ ]+)_([0-9])+\.([a-zA-Z]+)"#', $content, $wongmRailGallery_matches ) ) {
		
		if ($wongmRailGallery_matches[5] == 'metro-trains-melbourne')
			$wongmRailGallery_matches[5] = 'metro-trains-melbourne-tofix';
		
		return "https://" . $wongmRailGallery_matches[2] . "/albums/" . $wongmRailGallery_matches[5] . "/" . $wongmRailGallery_matches[6] . "." . $wongmRailGallery_matches[8];
	}

	return "";
}

function mtw_check_for_railGeelong( $content ) {
	if ( preg_match( '#\/\/(www\.)?(railgeelong.com)?\/(gallery\/)?(cache)\/?(\?v=)?([a-zA-Z0-9\-\/\_]+)\/([a-zA-Z0-9\-\_]+)_([0-9])+\.([a-zA-Z]+)"#', $content, $railGeelong_matches ) ) {
		return $railGeelong_matches[7];
	}

	return false;
}

function mtw_get_railGeelong_details( $content ) {
	if ( preg_match( '#\/\/(www\.)?(railgeelong.com)?\/(gallery\/)?(cache)\/?(\?v=)?([a-zA-Z0-9\-\/\_]+)\/([a-zA-Z0-9\-\_]+)_([0-9])+\.([a-zA-Z]+)"#', $content, $railGeelong_matches ) ) {
		return "https://" . $railGeelong_matches[2] . "/albums/" . $railGeelong_matches[6] . "/" . $railGeelong_matches[7] . "." . $railGeelong_matches[9];
	}

	return "";
}

function mtw_check_for_flickr( $content ) {
	if ( preg_match( '#\/\/([a-zA-Z0-9\-\/\_]+).(static)(\.)?(flickr.com)?\/([0-9\/]+)?\/([0-9]+)_([a-zA-Z0-9]+)(_[a-zA-Z0-9]+)?\.jpg#', $content, $flickr_matches ) ) {
		return $flickr_matches[6];
	}

	return false;
}

function mtw_get_flickr_details( $content ) {
	if ( preg_match( '#\/\/([a-zA-Z0-9\-\/\_]+).(static)(\.)?(flickr.com)?\/([0-9\/]+)?\/([0-9]+)_([a-zA-Z0-9]+)(_[a-zA-Z0-9]+)?\.jpg#', $content, $flickr_matches ) ) {
		
		$size = "_o";
		if ($flickr_matches[8] != $size) {
			$size = "_b";
		}
		
		return "https://" . $flickr_matches[1] . "." . $flickr_matches[2] . $flickr_matches[3] . $flickr_matches[4] . "/" . $flickr_matches[5] . "/" . $flickr_matches[6] . "_" . $flickr_matches[7] . $size . ".jpg";
	}

	return "";
}


/**
 * Check if the content contains a youtube url.
 *
 * Props to @rzen for lending his massive brain smarts to help with the regex.
 *
 * @author Gary Kovar
 *
 * @param $content
 *
 * @return string The value of the youtube id.
 *
 */
function mtw_check_for_youtube( $content ) {
	if ( preg_match( '#\/\/(www\.)?(youtu|youtube|youtube-nocookie)\.(com|be)\/(?!.*user)(watch|embed)?\/?(\?v=)?([a-zA-Z0-9\-\_]+)#', $content, $youtube_matches ) ) {
		return $youtube_matches[6];
	}

	return false;
}

/**
 * Check if the content contains a vimeo url.
 *
 * Props to @rzen for lending his massive brain smarts to help with the regex.
 *
 * @author Gary Kovar
 *
 * @param $content
 *
 * @return string The value of the vimeo id.
 *
 */
function mtw_check_for_vimeo( $content ) {
	if ( preg_match( '#\/\/(.+\.)?(vimeo\.com)\/(\d*)#', $content, $vimeo_matches ) ) {
		return $vimeo_matches[3];
	}

	return false;
}

/**
 * Handle the upload of a new image.
 *
 * @since 1.0.0
 *
 * @param string      $url      URL to sideload.
 * @param int         $post_id  Post ID to attach to.
 * @param string|null $filename Filename to use.
 * @param string      $external_image_id External content ID.
 *
 * @return mixed
 */
function mtw_ms_media_sideload_image_with_new_filename( $url, $post_id, $filename = null, $external_image_id ) {

	if ( ! $url || ! $post_id ) {
		return new WP_Error( 'missing', esc_html__( 'Need a valid URL and post ID...', 'automatic-featured-images-from-external-content' ) );
	}

	require_once( ABSPATH . 'wp-admin/includes/file.php' );

	// Download file to temp location, returns full server path to temp file, ex; /home/user/public_html/mysite/wp-content/26192277_640.tmp.
	$tmp = download_url( $url );

	// If error storing temporarily, unlink.
	if ( is_wp_error( $tmp ) ) {
		// And output wp_error.
		return $tmp;
	}

	// Fix file filename for query strings.
	preg_match( '/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $url, $matches );
	// Extract filename from url for title.
	$url_filename = basename( $matches[0] );
	// Determine file type (ext and mime/type).
	$url_type = wp_check_filetype( $url_filename );

	// Override filename if given, reconstruct server path.
	if ( ! empty( $filename ) ) {
		$filename = sanitize_file_name( $filename );
		// Extract path parts.
		$tmppath = pathinfo( $tmp );
		// Build new path.
		$new = $tmppath['dirname'] . '/' . $filename . '.' . $tmppath['extension'];
		// Renames temp file on server.
		rename( $tmp, $new );
		// Push new filename (in path) to be used in file array later.
		$tmp = $new;
	}

	/* Assemble file data (should be built like $_FILES since wp_handle_sideload() will be using). */

	// Full server path to temp file.
	$file_array['tmp_name'] = $tmp;

	if ( ! empty( $filename ) ) {
		// User given filename for title, add original URL extension.
		$file_array['name'] = $filename . '.' . $url_type['ext'];
	} else {
		// Just use original URL filename.
		$file_array['name'] = $url_filename;
	}

	$post_data = array(
		// Just use the original filename (no extension).
		'post_title'  => get_the_title( $post_id ),
		// Make sure gets tied to parent.
		'post_parent' => $post_id,
	);

	// Required libraries for media_handle_sideload.
	require_once( ABSPATH . 'wp-admin/includes/file.php' );
	require_once( ABSPATH . 'wp-admin/includes/media.php' );
	require_once( ABSPATH . 'wp-admin/includes/image.php' );

	// Do the validation and storage stuff.
	// $post_data can override the items saved to wp_posts table, like post_mime_type, guid, post_parent, post_title, post_content, post_status.
	$att_id = media_handle_sideload( $file_array, $post_id, null, $post_data );

	// If error storing permanently, unlink.
	if ( is_wp_error( $att_id ) ) {
		// Clean up.
		@unlink( $file_array['tmp_name'] );

		// And output wp_error.
		return $att_id;
	}

	return $att_id;
}

/**
 * Get the image thumbnail and the video url from a youtube id.
 *
 * @author Gary Kovar
 *
 * @since 1.0.5
 *
 * @param string $youtube_id Youtube video ID.
 * @return array Video data.
 */
function mtw_get_youtube_details( $youtube_id ) {
	$video = array();
	$external_image_url_string = 'http://img.youtube.com/vi/%s/%s';

	$video_check                      = wp_remote_head( 'https://www.youtube.com/oembed?format=json&url=http://www.youtube.com/watch?v=' . $youtube_id );
	if ( 200 === wp_remote_retrieve_response_code( $video_check ) ) {
		$remote_headers               = wp_remote_head(
			sprintf(
				$external_image_url_string,
				$youtube_id,
				'maxresdefault.jpg'
			)
		);
		$video['external_image_url'] = ( 404 === wp_remote_retrieve_response_code( $remote_headers ) ) ?
			sprintf(
				$external_image_url_string,
				$youtube_id,
				'hqdefault.jpg'
			) :
			sprintf(
				$external_image_url_string,
				$youtube_id,
				'maxresdefault.jpg'
			);
		$video['video_url']           = 'https://www.youtube.com/watch?v=' . $youtube_id;
		$video['video_embed_url']     = 'https://www.youtube.com/embed/' . $youtube_id;
	}

	return $video;
}

/**
 * Get the image thumbnail and the video url from a vimeo id.
 *
 * @author Gary Kovar
 *
 * @since 1.0.5
 *
 * @param string $vimeo_id Vimeo video ID.
 * @return array Video information.
 */
function mtw_get_vimeo_details( $vimeo_id ) {
	$video = array();

	// @todo Get remote checking matching with mtw_get_youtube_details.
	$vimeo_data = wp_remote_get( 'http://www.vimeo.com/api/v2/video/' . intval( $vimeo_id ) . '.php' );
	if ( 200 === wp_remote_retrieve_response_code( $vimeo_data ) ) {
		$response                     = unserialize( $vimeo_data['body'] );
		$video['external_image_url'] = isset( $response[0]['thumbnail_large'] ) ? $response[0]['thumbnail_large'] : false;
		$video['video_url']           = $response[0]['url'];
		$video['video_embed_url']     = 'https://player.vimeo.com/video/' . $vimeo_id;
	}

	return $video;
}

/**
 * Check if the post is a video.
 *
 * @author Gary Kovar
 *
 * @since 1.0.5
 *
 * @param int $post_id WP post ID to check for video on.
 * @return bool
 */
function mtw_post_has_video( $post_id ) {
	if ( ! metadata_exists( 'post', $post_id, '_is_video' ) ) {
		mtw_check_if_content_contains_external_content( $post_id, get_post( $post_id ) );
	}

	return get_post_meta( $post_id, '_is_video', true );
}

/**
 * Get the URL for the video.
 *
 * @author Gary Kovar
 *
 * @since 1.0.5
 *
 * @param int $post_id Post ID to get video url for.
 * @return string
 */
function mtw_get_video_url( $post_id ) {
	if ( mtw_post_has_video( $post_id ) ) {
		if ( ! metadata_exists( 'post', $post_id, '_video_url' ) ) {
			mtw_check_if_content_contains_external_content( $post_id, get_post( $post_id ) );
		}

		return get_post_meta( $post_id, '_video_url', true );
	}
	return '';
}

/**
 * Get the embeddable URL
 *
 * @author Gary Kovar
 *
 * @since 1.0.5
 *
 * @param int $post_id Post ID to grab video for.
 * @return string
 */
function mtw_get_embeddable_video_url( $post_id ) {
	if ( mtw_post_has_video( $post_id ) ) {
		if ( ! metadata_exists( 'post', $post_id, '_video_embed_url' ) ) {
			mtw_check_if_content_contains_external_content( $post_id, get_post( $post_id ) );
		}

		return get_post_meta( $post_id, '_video_embed_url', true );
	}
	return '';
}

/**
 * Register a metabox to display the video on post edit view.
 * @author Gary Kovar
 * @since 1.1.0
 */
function mtw_register_display_video_metabox() {
	global $post;

	if ( get_post_meta( $post->ID, '_is_video', true ) ) {
		add_meta_box(
			'mtw_display_video_urls_metabox',
			esc_html__( 'Video Files found in Content', 'mtw-automatic-featured-images-from-external-content' ),
			'mtw_video_thumbnail_meta'
		);
	}
}

/**
 * Populate the metabox.
 * @author Gary Kovar
 * @since 1.1.0
 */
function mtw_video_thumbnail_meta() {
	global $post;

	echo '<h3>' . esc_html__( 'Video URL', 'mtw_automatic_featured_images_from_external_content' ) . '</h3>';
	echo mtw_get_video_url($post->ID);
	echo '<h3>' . esc_html__( 'Video Embed URL', 'mtw_automatic_featured_images_from_external_content' ) . '</h3>';
	echo mtw_get_embeddable_video_url( $post->ID );
}

/**
 * Run a WP Query.
 *
 * @since 1.1.0
 *
 * @param string $post_type      Post type to query for.
 * @param int    $posts_per_page Posts per page to query for.
 * @return WP_Query WP_Query object
 */
function mtw_automatic_featured_images_from_external_content_wp_query( $post_type, $posts_per_page ) {
	$args  = array(
		'post_type'      => $post_type,
		'meta_query'     => array(
			array(
				'key'     => '_is_video',
				'compare' => 'NOT EXISTS',
			),
		),
		'posts_per_page' => $posts_per_page,
		'fields'         => 'ids',
	);
	return new WP_Query( $args );
}
	
/**
 * Remove 'Quick Featured Images' menu item
 */ 
add_action( 'admin_menu', 'mtw_remove_menu_pages', 999 );
function mtw_remove_menu_pages() {
    remove_menu_page('quick-featured-images-overview');
}
	
	
?>
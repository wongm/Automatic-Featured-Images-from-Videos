/**
 * Created by garykovar on 11/21/16.
 */

jQuery(document).ready(function () {

	function mtw_ajax_maybe_update_post_thumbnail() {
		jQuery("body.edit-php.post-type-" + mtw_featured_image_from_vid_args.post_type + " .wrap h1").append('<a href="#" class="page-title-action bulk-add-video">' + mtw_featured_image_from_vid_args.bulk_text + '</a>');
		jQuery(".bulk-add-video").on('click',function (e) {
			e.preventDefault();
			jQuery(".bulk-add-video").hide();
			jQuery("body.edit-php.post-type-" + mtw_featured_image_from_vid_args.post_type + " .wrap h1").append('<a class="page-title-action bulk-add-video-status">' + mtw_featured_image_from_vid_args.processing_text + '</a>');
			jQuery.ajax({
				type: "POST",
				url : ajaxurl,
				data: {action: 'mtw_queue_bulk_processing', posttype: mtw_featured_image_from_vid_args.post_type}
			});
		});
	}

	if ('running' == mtw_featured_image_from_vid_args.status) {
		jQuery("body.edit-php.post-type-" + mtw_featured_image_from_vid_args.post_type + " .wrap h1").append('<a class="page-title-action bulk-add-video-status">' + mtw_featured_image_from_vid_args.processing_text + '</a>');
	}

	if ('ready_to_process' == mtw_featured_image_from_vid_args.status) {
		mtw_ajax_maybe_update_post_thumbnail();
	}
});

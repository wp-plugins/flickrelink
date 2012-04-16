<?php
/**
 * @package FlickRelink
 * @version 1.0.1
 */

/*
Plugin Name: FlickRelink
Description: Scan your posts to find and fix broken/unavailable Flickr photos.
Version: 1.0.1
Author: Jérôme Augé
Author URI: http://locallost.net/
License: GPLv2 or later
*/

function flickrelink_ini_set() {
	ini_set('max_execution_time', -1);
	ini_set('output_buffering', 0);
	ob_implicit_flush();
	ob_end_flush();
}

/**
 * Detect broken Flickr images by doing a HEAD request and checking for a 302
 * response with a location pointing to a "photo_unavailable_x.gif"
 */
function flickrelink_is_link_broken($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_NOBODY, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);

	$data = curl_exec($ch);
	if (curl_errno($ch)) {
		curl_close($ch);
		return null;
	}

	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($code == 302 && preg_match('%^Location: .*photo_unavailable(_.)?\.gif\r$%m', $data)) {
		curl_close($ch);
		return true;
	}

	curl_close($ch);
	return false;
}

/**
 * Get the URL for a given photo Id and size
 * Fetch `flickr.com/photo.gne?id=<photoId>` and grep the page for a
 * `<link rel="image_src" ...>` which contains the URL.
 */
function flickrelink_get_photo_url($photo, $size) {
	$url = sprintf('http://www.flickr.com/photo.gne?id=%s', $photo);

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_NOBODY, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	$data = curl_exec($ch);
	if (curl_errno($ch)) {
		curl_close($ch);
		return null;
	}

	if (preg_match('%<link\s+rel\s*=\s*"image_src"\s+href\s*=\s*"(?P<url>[^"]+)"%', $data, $m)) {
		$url = $m['url'];
		$url = preg_replace('%(.*?)(?:_.)?(\.jpg)$%', sprintf('\1%s\2', $size), $url);
		return $url;
	}
	
	return null;
}

function flickrelink_fix() {
	flickrelink_ini_set();

	check_admin_referer();

	$postId = '';
	$oldUrl = '';
	$newUrl = '';
	if (isset($_REQUEST['postId'])) {
		$postId = $_REQUEST['postId'];
	}
	if (isset($_REQUEST['oldUrl'])) {
		$oldUrl = $_REQUEST['oldUrl'];
	}
	if (isset($_REQUEST['newUrl'])) {
		$newUrl = $_REQUEST['newUrl'];
	}

	if ($postId == '' || $oldUrl == '' || $newUrl == '') {
		echo sprintf("<p>Missing or empty arguments :(</p>");
		return;
	}

	$post = get_post($postId);
	if ($post === null) {
		echo sprintf("<p>Error: post &quot;%s&quot; not found!</p>", $postId);
		return;
	}

	echo sprintf("<p>Fixing post &quot;<a href=\"%s\" target=\"_blank\">%s</a>&quot;: replacing image &quot;%s&quot; with &quot;%s&quot;...</p>", get_permalink($post->ID), $post->post_title, $oldUrl, $newUrl);

	$content = $post->post_content;
	$content = preg_replace('%' . preg_quote($oldUrl, '%') . '%', $newUrl, $content);
	$post = array(
		'ID' => $postId,
		'post_content' => $content
	);
	if (wp_update_post($post) != $postId) {
		echo sprintf("<p>Error: could not update post &quot;%s&quot;!</p>", $postId);
		return;
	}
	echo sprintf("<p>Done.</p>");
}

function flickrelink_search_broken_links() {
	flickrelink_ini_set();

	$post_status = 'all';
	$numberposts = -1;
	$offset = 0;
	if (isset($_REQUEST['flickrelink_post_status']) && $_REQUEST['flickrelink_post_status'] != '') {
		$post_status = $_REQUEST['flickrelink_post_status'];
	}
	if (isset($_REQUEST['flickrelink_numberposts']) && is_numeric($_REQUEST['flickrelink_numberposts'])) {
		$numberposts = $_REQUEST['flickrelink_numberposts'];
	}
	if (isset($_REQUEST['flickrelink_offset']) && is_numeric($_REQUEST['flickrelink_offset'])) {
		$offset = $_REQUEST['flickrelink_offset'];
	}

	echo sprintf("<p>Scanning %s posts for unavailable Flickr images...</p>", htmlspecialchars($_REQUEST['flickrelink_post_status']));

	$imgRegex = '%<img[^>]*\s+src\s*=\s*"(?P<url>[^"]+(?P<host>farm\d+\.static\.flickr\.com|farm\d+\.staticflickr\.com)/\d+/(?P<photo>\d+)_(?P<hash>[a-f0-9]+)(?P<size>_.)?\.jpg)%';

	$postList = get_posts(
		array(
			'numberposts' => $numberposts,
			'offset' => $offset,
			'post_status' => $post_status
		)
	);

	echo "<ul>";
	$foundBrokenLinks = false;
	foreach ($postList as &$post) {
		if (preg_match_all($imgRegex, $post->post_content, $links, PREG_SET_ORDER) <= 0) {
			continue;
		}

		foreach ($links as $link) {
			$isBroken = flickrelink_is_link_broken($link['url']);
			if ($isBroken === false) {
				continue;
			}
			$foundBrokenLinks = true;

			$permalink = get_permalink($post->ID);
			echo sprintf("<li>Post &quot;<a href=\"%s\" target=\"_blanck\">%s</a>&quot; (%s)", $permalink, htmlspecialchars($post->post_title), htmlspecialchars($post->post_status));

			if ($isBroken === null) {
				echo sprintf(": error checking if image &quot;<a href=\"%s\" target=\"_blank\">%s</a>&quot; is broken or not...", $link['url'], $link['url']);
				echo "</li>";
				continue;
			}

			$newUrl = flickrelink_get_photo_url($link['photo'], $link['size']);
			if ($newUrl === null) {
				echo sprintf(": could not get new image location for &quot;<a href=\"%s\" target=\"_blank\">%s</a>&quot;...", $link['url'], $link['url']);
				echo "</li>";
				continue;
			}

			echo sprintf(": image &quot;<a href=\"%s\" target=\"_blank\">%s</a>&quot; is unavailable, new image is &quot;<a href=\"%s\" target=\"_blank\">%s</a>&quot;",
				$link['url'],
				$link['url'],
				$newUrl,
				$newUrl
			);

			echo sprintf("&nbsp;(<a href=\"%s\" target=\"_blank\"><span style=\"font-weight: bold\">fix it</span></a>)",
				add_query_arg(
					array(
						'flickrelink_command' => 'fix',
						'postId' => $post->ID,
						'oldUrl' => urlencode($link['url']),
						'newUrl' => urlencode($newUrl)
					)
				)
			);

			echo "</li>";
		}
	}
	unset($post);
	echo "</ul>";

	if (!$foundBrokenLinks) {
		echo "<p>Nice! Found no broken links :)</p>";
		return;
	}

	echo "<p>Done.</p>";
}

function flickrelink_do($command) {
	switch ($command) {
		case 'scan':
			return flickrelink_search_broken_links();
			break;
		case 'fix':
			return flickrelink_fix();
			break;
		default:
			echo sprintf("<p>Unknown command &quot;%s&quot;</p>", htmlspecialchars($command));
	}
}

function flickrelink_admin() {
	if (!function_exists('curl_init')) {
		echo "<p>FlickRelink requires the PHP curl extension!</p>";
		echo "<p>Please install php-curl extension and retry.</p>";
		return;
	}

	if (isset($_REQUEST['flickrelink_command'])) {
		return flickrelink_do($_REQUEST['flickrelink_command']);
	}

	$countPosts = wp_count_posts();
	$countPosts->all = 0;
	foreach (get_object_vars($countPosts) as $var => $value) {
		if ($var == 'all') {
			continue;
		}
		$countPosts->all += $countPosts->$var;
	}

	echo "<h1>FlickRelink</h1>";
	echo sprintf("<form action=\"%s\" method=\"post\">", $_SERVER["REQUEST_URI"]);
	echo sprintf("<input name=\"flickrelink_command\" type=\"hidden\" value=\"scan\" />");

	echo sprintf("<ul>");

	echo sprintf("<li><span style=\"text-decoration: underline\">Scan posts with status:</span><br />");
	echo sprintf("<select name=\"flickrelink_post_status\">");
	foreach (array(
		'all' => 'All posts',
		'new' => 'New posts',
		'publish' => 'Published posts',
		'pending' => 'Pending posts',
		'draft' => 'Draft posts',
		'auto-draft' => 'Auto-draft posts',
		'future' => 'Future posts',
		'private' => 'Private posts',
		'inherit' => 'Inherited posts',
		'trash' => 'Trashed posts'
		) as $key => $label) {
		 echo sprintf("<option value=\"%s\">%s (%s posts)</option>", htmlspecialchars($key), htmlspecialchars($label), (isset($countPosts->$key)?$countPosts->$key:'0'));
	}
	echo sprintf("</select></li>");

	echo sprintf("<li><span style=\"text-decoration: underline\">Number of posts to scan:</span><br /><input name=\"flickrelink_numberposts\" type=\"text\" value=\"-1\" /><br/ >(Posts are ordered from newest to oldest. Enter &quot;-1&quot; to scan all your posts with the selected status)</li>");

	echo sprintf("<li><span style=\"text-decoration: underline\">Offset:</span><br /><input name=\"flickrelink_offset\" type=\"text\" value=\"0\" /><br />(Start scan at this post number)</li>");

	echo sprintf("</ul>");
	echo sprintf("<input type=\"submit\" />");

	echo sprintf("</form>");
}

function flickrelink_admin_menu() {
	add_management_page('FlickRelink', 'FlickRelink', 'administrator', basename( __FILE__ ), 'flickrelink_admin');
}

if (is_admin()) {
	add_action('admin_menu', 'flickrelink_admin_menu');
}

?>

=== Plugin Name ===
Contributors: eguaj
Tags: flickr, image, photo, broken, unavailable, link, correct, fix
Requires at least: 3.0.0
Tested up to: 4.2
Stable tag: 1.0.3

Scan your posts to find and fix broken/unavailable Flickr photos.

== Description ==

When you update an image in Flickr, the link to this image is also changed. So,
in your posts, links to these images will show a generic image with the message
"This photo is currently unavailable".

This plugins allows you to scan your posts for links to Flickr image which are
unavailable, and try to guess the new photo location, and allows you to
automatically correct the link in the post.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `flickrelink` directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. The plugin main interface will appears in the Tools menu

== Frequently Asked Questions ==

== Changelog ==

= 1.0.3 =
* Updated Flickr screen-scraping.

= 1.0.2 =
* Oops... SSL peer verification was disabled in previous versions. So, it's now
  left enabled.

= 1.0.1 =
* Corrected detection of images without size code
* Raised curl timeout from 5 sec. to 15 sec.

= 1.0.0 =
* First release

== Upgrade Notice ==

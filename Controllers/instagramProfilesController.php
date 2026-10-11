<?php

declare(strict_types=1);

/**
 * Serves the photos of Instagram posts and stories to FreshRSS’s pages, from FreshRSS’s cache ({@see InstagramProfilesMedia}):
 * browsers refuse those of Instagram’s CDN on other sites. A post’s photo missing from the cache is downloaded first.
 * Like any user extension, this one is only enabled for a logged-in user, or for anonymous readers if FreshRSS allows them.
 */
final class FreshExtension_instagramProfiles_Controller extends Minz_ActionController {
	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess() && !FreshRSS_Auth::allowAnonymous()) {
			Minz_Error::error(403);
		}
	}

	/** `?c=instagramProfiles&a=media&post=<shortcode>`, `&story=<id>` (its picture) or `&video=<id>` (a story’s video) */
	public function mediaAction(): void {
		require_once dirname(__DIR__) . '/lib/InstagramProfilesPage.php';
		require_once dirname(__DIR__) . '/lib/InstagramProfilesMedia.php';
		$code = Minz_Request::paramString('post');
		$id = Minz_Request::paramString('story');
		$video = Minz_Request::paramString('video');
		if (preg_match('/^' . InstagramProfilesPage::CODE_PATTERN . '$/', $code) === 1) {
			$path = InstagramProfilesMedia::postPath($code);
			if (!InstagramProfilesMedia::cached($path)) {
				InstagramProfilesMedia::download(InstagramProfilesPage::mediaUrl($code), $path);
			}
		} elseif (preg_match('/^\d{1,30}$/', $id) === 1) {
			$path = InstagramProfilesMedia::storyPath($id);	// kept when the story was read: its link has expired since
		} elseif (preg_match('/^\d{1,30}$/', $video) === 1) {
			$path = InstagramProfilesMedia::storyVideoPath($video);
		} else {
			header('HTTP/1.1 400 Bad Request');
			exit('Bad Request');
		}
		InstagramProfilesMedia::send($path);
		exit();	// a picture, not a page of FreshRSS
	}
}

<?php

declare(strict_types=1);

/**
 * Photos of Instagram posts and stories, kept in FreshRSS’s cache and served by FreshRSS
 * ({@see FreshExtension_instagramProfiles_Controller}).
 *
 * Instagram’s CDN forbids other sites to show its pictures (`Cross-Origin-Resource-Policy: same-origin`, but for some links
 * of its embeds, which expire), so browsers refuse them in FreshRSS’s pages. FreshRSS downloads them instead: a post’s photo
 * when it is first shown, through Instagram’s redirect {@see InstagramProfilesPage::mediaUrl()}; a story’s picture when the
 * story is read, as its link expires with it. FreshRSS empties its cache of files older than 30 days; a post’s photo is then
 * downloaded again when shown, a story’s is gone.
 */
final class InstagramProfilesMedia {
	/** Larger answers are not kept: a picture is far smaller */
	private const MAX_BYTES = 20 * 1024 * 1024;
	/** How long browsers may keep a picture, in seconds */
	private const BROWSER_CACHE = 7 * 86_400;

	public static function postPath(string $code): string {
		return CACHE_PATH . '/instagram-post-' . $code . '.img';
	}

	public static function storyPath(string $id): string {
		return CACHE_PATH . '/instagram-story-' . $id . '.img';
	}

	/** Whether the cache has a picture there */
	public static function cached(string $path): bool {
		return @filesize($path) > 0;
	}

	/**
	 * Downloads a picture into the cache.
	 * @param non-empty-string $url
	 * @return bool false when the download failed or did not give a picture
	 */
	public static function download(string $url, string $path): bool {
		// A file of its own as FreshRSS’s cache file (which FreshRSS 1.28 requires), renamed once checked
		$temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$response = FreshRSS_http_Util::httpGet($url, $temporary, 'ico');
		$bytes = $response['body'];
		if ($response['fail'] || strlen($bytes) > self::MAX_BYTES || self::type($bytes) === null
			|| @file_put_contents($temporary, $bytes) === false || !@rename($temporary, $path)) {
			@unlink($temporary);
			return false;
		}
		return true;
	}

	/** The MIME type of a picture from its first bytes, or null when it is none of those browsers show */
	public static function type(string $bytes): ?string {
		return match (true) {
			str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
			str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
			str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
			str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
			default => null,
		};
	}

	/** Answers the request with a cached picture, or with 404 */
	public static function send(string $path): void {
		$bytes = @file_get_contents($path);
		$type = is_string($bytes) ? self::type($bytes) : null;
		$mtime = @filemtime($path);
		if (!is_string($bytes) || $type === null || $mtime === false) {
			header('HTTP/1.1 404 Not Found');
			header('Content-Type: text/plain; charset=UTF-8');
			echo 'Not Found';
			return;
		}
		header('Content-Type: ' . $type);
		header('X-Content-Type-Options: nosniff');
		require_once LIB_PATH . '/http-conditional.php';
		if (!httpConditional($mtime, self::BROWSER_CACHE, 2)) {	// 2: private, the browser’s cache only
			header('Content-Length: ' . strlen($bytes));
			echo $bytes;
		}
	}

	/**
	 * Points the photos in an entry’s HTML at FreshRSS: Instagram’s redirects to the photos of posts, and the links to the
	 * pictures of stories marked `#ig-story-<id>` when the cache has them.
	 * @param callable(string,string):string $proxyUrl gives the HTML-escaped link to the picture, from `post` or `story` and its code or ID
	 */
	public static function rewrite(string $html, callable $proxyUrl): string {
		// Possessive repeats, which never backtrack: FreshRSS lowers pcre.backtrack_limit to 10000
		if (str_contains($html, '/media/?size=l')) {
			$html = preg_replace_callback('#https://www\.instagram\.com/p/([A-Za-z0-9_-]{5,40}+)/media/\?size=l#',
				static fn(array $match): string => $proxyUrl('post', $match[1]), $html) ?? $html;
		}
		if (str_contains($html, '#ig-story-')) {
			$html = preg_replace_callback('~https://[^"\'\s<>#]++\#ig-story-(\d{1,30}+)~',
				static fn(array $match): string => self::cached(self::storyPath($match[1])) ? $proxyUrl('story', $match[1]) : $match[0], $html) ?? $html;
		}
		return $html;
	}

	/** The HTML-escaped link with which FreshRSS serves a picture: `post` and a shortcode, or `story` and an ID */
	public static function proxyUrl(string $kind, string $id): string {
		return Minz_Url::display(['c' => 'instagramProfiles', 'a' => 'media', 'params' => [$kind => $id]]);
	}
}

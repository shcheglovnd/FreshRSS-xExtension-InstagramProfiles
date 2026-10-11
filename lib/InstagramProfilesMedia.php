<?php

declare(strict_types=1);

/**
 * Photos of Instagram posts, and pictures and videos of stories, kept in FreshRSS’s cache and served by FreshRSS
 * ({@see FreshExtension_instagramProfiles_Controller}).
 *
 * Instagram’s CDN forbids other sites to show its pictures (`Cross-Origin-Resource-Policy: same-origin`, but for some links
 * of its embeds, which expire), so browsers refuse them in FreshRSS’s pages. FreshRSS downloads them instead: a post’s photo
 * when it is first shown, through Instagram’s redirect {@see InstagramProfilesPage::mediaUrl()}; a story’s picture, and its
 * video if so set, when the story is read, as their links expire with it. FreshRSS empties its cache of files older than
 * 30 days; a post’s photo is then downloaded again when shown, a story’s picture or video is gone.
 */
final class InstagramProfilesMedia {
	/** Larger pictures are not kept: they are far smaller */
	private const MAX_PICTURE_BYTES = 20 * 1024 * 1024;
	/** Larger videos are not kept: a story’s video, at most a minute long, is usually 1 to 10 MB */
	private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;
	/** How long browsers may keep a picture or video, in seconds */
	private const BROWSER_CACHE = 7 * 86_400;

	public static function postPath(string $code): string {
		return CACHE_PATH . '/instagram-post-' . $code . '.img';
	}

	public static function storyPath(string $id): string {
		return CACHE_PATH . '/instagram-story-' . $id . '.img';
	}

	public static function storyVideoPath(string $id): string {
		return CACHE_PATH . '/instagram-story-' . $id . '.video';
	}

	/** Whether the cache has a file there */
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
		if ($response['fail'] || strlen($bytes) > self::MAX_PICTURE_BYTES || !str_starts_with(self::type($bytes) ?? '', 'image/')
			|| @file_put_contents($temporary, $bytes) === false || !@rename($temporary, $path)) {
			@unlink($temporary);
			return false;
		}
		return true;
	}

	/**
	 * Downloads a video into the cache, written to disk as it arrives rather than held in memory, with FreshRSS’s proxy
	 * settings; FreshRSS’s HTTP client returns the whole answer in memory.
	 * @return bool false when the download failed, was larger than {@see MAX_VIDEO_BYTES}, or did not give a video
	 */
	public static function downloadVideo(string $url, string $path): bool {
		$temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$file = @fopen($temporary, 'wb');
		$ch = curl_init($url);
		if ($file === false || $ch === false) {
			if ($file !== false) {
				fclose($file);
			}
			@unlink($temporary);
			return false;
		}
		curl_setopt_array($ch, FreshRSS_Context::systemConf()->curl_options);	// e.g. a proxy
		curl_setopt_array($ch, [
			CURLOPT_FILE => $file,
			CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_MAXFILESIZE => self::MAX_VIDEO_BYTES,	// when the size is announced
			CURLOPT_NOPROGRESS => false,	// otherwise, as it arrives
			CURLOPT_PROGRESSFUNCTION => static fn(mixed $ch, int|float $total, int|float $done): int => $done > self::MAX_VIDEO_BYTES ? 1 : 0,
		]);
		if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
		}
		$done = curl_exec($ch) === true && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
		unset($ch);
		fclose($file);
		$head = (string)@file_get_contents($temporary, false, null, 0, 16);
		if (!$done || !str_starts_with(self::type($head) ?? '', 'video/') || !@rename($temporary, $path)) {
			@unlink($temporary);
			return false;
		}
		return true;
	}

	/** The MIME type of a picture or video from its first bytes, or null when it is none of those browsers show */
	public static function type(string $bytes): ?string {
		return match (true) {
			str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
			str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
			str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
			str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
			substr($bytes, 4, 4) === 'ftyp' => 'video/mp4',
			str_starts_with($bytes, "\x1A\x45\xDF\xA3") => 'video/webm',
			default => null,
		};
	}

	/**
	 * Answers the request with a cached picture or video, or with 404. Supports a single byte range (`Range: bytes=…`),
	 * without which browsers on phones do not play videos.
	 */
	public static function send(string $path): void {
		$file = @fopen($path, 'rb');
		$head = $file === false ? false : fread($file, 16);
		$type = is_string($head) ? self::type($head) : null;
		$size = @filesize($path);
		$mtime = @filemtime($path);
		if ($file === false || $type === null || $size === false || $size === 0 || $mtime === false) {
			if ($file !== false) {
				fclose($file);
			}
			header('HTTP/1.1 404 Not Found');
			header('Content-Type: text/plain; charset=UTF-8');
			echo 'Not Found';
			return;
		}
		header('Content-Type: ' . $type);
		header('X-Content-Type-Options: nosniff');
		header('Accept-Ranges: bytes');
		$range = self::range(is_string($_SERVER['HTTP_RANGE'] ?? null) ? $_SERVER['HTTP_RANGE'] : '', $size);
		if ($range === false) {
			header('HTTP/1.1 416 Range Not Satisfiable');
			header('Content-Range: bytes */' . $size);
		} elseif ($range !== null) {
			[$start, $end] = $range;
			header('HTTP/1.1 206 Partial Content');
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
			header('Content-Length: ' . ($end - $start + 1));
			header('Cache-Control: private, max-age=' . self::BROWSER_CACHE);
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
			self::output($file, $start, $end - $start + 1);
		} else {
			require_once LIB_PATH . '/http-conditional.php';
			if (!httpConditional($mtime, self::BROWSER_CACHE, 2)) {	// 2: private, the browser’s cache only
				header('Content-Length: ' . $size);
				self::output($file, 0, $size);
			}
		}
		fclose($file);
	}

	/**
	 * The bytes a `Range` header asks for, as [first, last], within a file of `$size` bytes: one range, from-to, from- or -suffix.
	 * @return array{0:int,1:int}|false|null null for the whole file (no or another kind of range), false when unsatisfiable
	 */
	public static function range(string $header, int $size): array|false|null {
		if (preg_match('/^\s*bytes=(\d{0,18})-(\d{0,18})\s*$/i', $header, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
			return null;
		}
		if ($matches[1] === '') {	// the last N bytes
			$length = (int)$matches[2];
			return $length === 0 ? false : [max(0, $size - $length), $size - 1];
		}
		$start = (int)$matches[1];
		$end = $matches[2] === '' ? $size - 1 : min((int)$matches[2], $size - 1);
		return $start >= $size || $start > $end ? false : [$start, $end];
	}

	/** @param resource $file */
	private static function output($file, int $start, int $length): void {
		fseek($file, $start);
		while ($length > 0 && !feof($file)) {
			$chunk = fread($file, min(1 << 16, $length));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$length -= strlen($chunk);
		}
	}

	/**
	 * Points the photos and videos in an entry’s HTML at FreshRSS: Instagram’s redirects to the photos of posts, and the links
	 * to the pictures and videos of stories, marked `#ig-story-<id>` and `#ig-story-video-<id>`, when the cache has them.
	 * @param callable(string,string):string $proxyUrl gives the HTML-escaped link to the file, from `post`, `story` or `video`
	 *  and the post’s code or the story’s ID
	 */
	public static function rewrite(string $html, callable $proxyUrl): string {
		// Possessive repeats, which never backtrack: FreshRSS lowers pcre.backtrack_limit to 10000
		if (str_contains($html, '/media/?size=l')) {
			$html = preg_replace_callback('#https://www\.instagram\.com/p/([A-Za-z0-9_-]{5,40}+)/media/\?size=l#',
				static fn(array $match): string => $proxyUrl('post', $match[1]), $html) ?? $html;
		}
		if (str_contains($html, '#ig-story-')) {
			$html = preg_replace_callback('~https://[^"\'\s<>#]++\#ig-story-(video-)?+(\d{1,30}+)~',
				static function (array $match) use ($proxyUrl): string {
					$video = $match[1] !== '';
					$path = $video ? self::storyVideoPath($match[2]) : self::storyPath($match[2]);
					return self::cached($path) ? $proxyUrl($video ? 'video' : 'story', $match[2]) : $match[0];
				}, $html) ?? $html;
		}
		return $html;
	}

	/** The HTML-escaped link with which FreshRSS serves a file: `post` and a shortcode, `story` or `video` and a story’s ID */
	public static function proxyUrl(string $kind, string $id): string {
		return Minz_Url::display(['c' => 'instagramProfiles', 'a' => 'media', 'params' => [$kind => $id]]);
	}
}

<?php

declare(strict_types=1);

/**
 * Reads what Instagram serves about a profile. All but the last are what it shows logged-out visitors:
 * * the profile’s embed, https://www.instagram.com/<user>/embed/, which websites use to show a profile: the profile and its
 *   six latest posts in full (caption, time, every photo of an album), and whether a story is up;
 * * the profile page, which says when the latest story was posted;
 * * a post’s page or embed, which say whose post it is;
 * * with a logged-in Instagram session, the API’s answer listing a profile’s current stories.
 *
 * Pages are scanned with string functions rather than regular expressions over the whole page: FreshRSS lowers
 * pcre.backtrack_limit to 10000, which such a regex exhausts silently on pages of this size.
 *
 * @phpstan-type Media array{code:string,video:bool,width:int,height:int,alt:string}
 * @phpstan-type Post array{code:string,date:int,kind:'photo'|'video'|'album',caption:string,media:list<Media>,owner:string,
 *   coauthors:list<string>,sponsors:list<string>,partnership:bool,location:array{name:string,url:string}|null}
 * @phpstan-type Profile array{user:string,name:string,id:string,avatar:string,story:bool,count:int,posts:list<Post>}
 * @phpstan-type Story array{id:string,date:int,video:bool,image:string,video_url:string,width:int,height:int,alt:string,
 *   links:list<array{url:string,title:string}>,posts:list<string>,mentions:list<string>,hashtags:list<string>,locations:list<string>}
 */
final class InstagramProfilesPage {
	/** Instagram usernames: letters, digits, `.` and `_`, at most 30 */
	public const USER_PATTERN = '[A-Za-z0-9._]{1,30}';
	/** Shortcodes of posts, as in https://www.instagram.com/p/<code>/ */
	public const CODE_PATTERN = '[A-Za-z0-9_-]{5,40}';
	/** The app ID that Instagram’s own pages send with their API requests */
	public const WEB_APP_ID = '936619743392459';

	/** First path segments of instagram.com that are not profiles */
	private const RESERVED = ['about', 'accounts', 'ajax', 'api', 'ar', 'challenge', 'developer', 'direct', 'download', 'emails',
		'explore', 'graphql', 'legal', 'lite', 'oauth', 'p', 'privacy', 'reel', 'reels', 's', 'session', 'share', 'static', 'stories',
		'terms', 'topics', 'tv', 'web'];
	/** Pages of a profile, as in https://www.instagram.com/<user>/<page>/ */
	private const PROFILE_PAGES = ['embed', 'feed', 'guides', 'reels', 'tagged'];
	/** Cookies of an Instagram session sent with requests: the session, and what Instagram’s pages send along */
	private const SESSION_COOKIES = ['sessionid', 'ds_user_id', 'csrftoken'];

	/** @return non-empty-string */
	public static function profileUrl(string $user): string {
		return 'https://www.instagram.com/' . strtolower($user) . '/';
	}

	/** @return non-empty-string */
	public static function embedUrl(string $user): string {
		return self::profileUrl($user) . 'embed/';
	}

	/** @return non-empty-string */
	public static function postUrl(string $code): string {
		return 'https://www.instagram.com/p/' . $code . '/';
	}

	/**
	 * A post’s embed, which says whose post it is.
	 * @return non-empty-string
	 */
	public static function postEmbedUrl(string $code): string {
		return self::postUrl($code) . 'embed/';
	}

	/**
	 * A photo of a post, or the cover of a video, 1080 px wide. Instagram redirects this link to its CDN at each request,
	 * whereas the CDN links in its pages stop working after a few days.
	 * @return non-empty-string
	 */
	public static function mediaUrl(string $code): string {
		return self::postUrl($code) . 'media/?size=l';
	}

	/** @return non-empty-string */
	public static function storiesUrl(string $user): string {
		return 'https://www.instagram.com/stories/' . strtolower($user) . '/';
	}

	/**
	 * The API listing a profile’s current stories, for a logged-in session; `$userId` is the profile’s numeric ID.
	 * @return non-empty-string
	 */
	public static function storiesApiUrl(string $userId): string {
		return 'https://www.instagram.com/api/v1/feed/reels_media/?reel_ids=' . $userId;
	}

	/**
	 * What an instagram.com link points at: ['profile', <user>] for a profile, one of its pages or its stories, ['post', <code>]
	 * for a post or reel that names no author, or null.
	 * @return array{0:'profile'|'post',1:string}|null
	 */
	public static function parseUrl(string $url): ?array {
		if (preg_match('#^(?:https?://)?(?:(?:www|m)\.)?instagram\.com(?::443)?(/[^?\#]*)?(?:[?\#].*)?$#i', trim($url), $matches) !== 1) {
			return null;
		}
		$parts = array_values(array_filter(explode('/', $matches[1] ?? ''), static fn(string $part): bool => $part !== ''));
		$first = strtolower($parts[0] ?? '');
		$second = $parts[1] ?? '';
		if (in_array($first, ['p', 'reel', 'reels', 'tv'], true) && preg_match('/^' . self::CODE_PATTERN . '$/', $second) === 1) {
			return ['post', $second];
		}
		if (in_array($first, ['stories', '_u'], true) && self::isUser($second) && strtolower($second) !== 'highlights') {
			return ['profile', strtolower($second)];
		}
		if (!self::isUser($first) || in_array($first, self::RESERVED, true)) {
			return null;
		}
		// The profile itself, one of its pages, or one of its posts as linked with its author’s name: /<user>/p/<code>/
		if ($second === '' || in_array(strtolower($second), self::PROFILE_PAGES, true)
			|| (in_array(strtolower($second), ['p', 'reel', 'tv'], true) && preg_match('/^' . self::CODE_PATTERN . '$/', $parts[2] ?? '') === 1)) {
			return ['profile', $first];
		}
		return null;
	}

	/**
	 * A profile and its latest posts, from its embed page.
	 * @return Profile|null null when the page shows no profile: an unknown, private or suspended account, or a page that
	 *   Instagram changed
	 */
	public static function parseEmbed(string $html): ?array {
		$context = self::get(self::contextJson($html), 'context');
		$user = strtolower(self::str($context, 'username'));
		$media = self::get($context, 'graphql_media');
		if (!self::isUser($user) || !is_array($media)) {
			return null;
		}
		$posts = [];
		foreach ($media as $node) {
			$post = self::post(self::get($node, 'shortcode_media'), $user);
			if ($post !== null && !isset($posts[$post['code']])) {
				$posts[$post['code']] = $post;
			}
		}
		$posts = array_values($posts);
		usort($posts, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);
		$id = self::str($context, 'owner_id');
		return [
			'user' => $user,
			'name' => self::clean(self::str($context, 'full_name')),
			'id' => ctype_digit($id) ? $id : '',
			'avatar' => self::httpsUrl(self::str($context, 'profile_pic_url')),
			'story' => self::get($context, 'has_public_story') === true,
			'count' => self::int($context, 'posts_count'),
			'posts' => $posts,
		];
	}

	/** The username of a post’s author, from the post’s page, whose `og:url` is https://www.instagram.com/<user>/p/<code>/ */
	public static function postPageOwner(string $html): ?string {
		$key = 'property="og:url" content="';
		$start = strpos($html, $key);
		$end = $start === false ? false : strpos($html, '"', $start + strlen($key));
		if ($start === false || $end === false) {
			return null;
		}
		$start += strlen($key);
		$link = self::parseUrl(html_entity_decode(substr($html, $start, $end - $start), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		return $link !== null && $link[0] === 'profile' ? $link[1] : null;
	}

	/** The username of a post’s author, from the post’s embed page, which some posts do not have (`"contextJSON":null`) */
	public static function postEmbedOwner(string $html): ?string {
		$user = strtolower(self::str(self::contextJson($html), 'gql_data', 'shortcode_media', 'owner', 'username'));
		return self::isUser($user) ? $user : null;
	}

	/**
	 * When the profile’s latest story was posted, from the profile page as Instagram serves it to a logged-out browser opening
	 * it (with `Sec-Fetch-*` headers: otherwise it leaves the profile’s data out of the page).
	 * @return int|null a Unix time, 0 when no story is up, null when the page does not say
	 */
	public static function latestStory(string $html, string $user): ?int {
		$key = '"xig_user_by_username":{';
		$offset = 0;
		while (($start = strpos($html, $key, $offset)) !== false) {
			$offset = $start + strlen($key);
			$next = strpos($html, $key, $offset);
			$profile = substr($html, $start, ($next === false ? strlen($html) : $next) - $start);
			// {"pk":"…","username":"<user>",…,"latest_reel_media":1791656143,…}; another one lists the posts
			if (stripos(substr($profile, 0, 300), '"username":"' . $user . '"') === false) {
				continue;
			}
			$field = strpos($profile, '"latest_reel_media":');
			if ($field === false) {
				continue;
			}
			$value = substr($profile, $field + strlen('"latest_reel_media":'), 24);
			if (str_starts_with($value, 'null')) {
				return 0;
			}
			$digits = substr($value, 0, strspn($value, '0123456789'));
			return $digits === '' ? null : (int)$digits;
		}
		return null;
	}

	/**
	 * A profile’s current stories, from the API’s answer for a logged-in session.
	 * @param string $userId the profile’s numeric ID
	 * @return array{error:string,stories:list<Story>} `error` is empty when the answer is a success, else why it is not
	 */
	public static function parseStories(string $json, string $userId): array {
		$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
		if (!is_array($data)) {
			return ['error' => 'not JSON', 'stories' => []];
		}
		if (self::str($data, 'status') !== 'ok') {
			$message = self::clean(self::str($data, 'message'));
			return ['error' => $message !== '' ? mb_substr($message, 0, 200) : 'status “' . self::str($data, 'status') . '”', 'stories' => []];
		}
		$reel = self::get($data, 'reels', $userId);
		if (!is_array($reel)) {
			foreach (self::items($data, 'reels_media') as $candidate) {
				if (self::str($candidate, 'id') === $userId) {
					$reel = $candidate;
					break;
				}
			}
		}
		$stories = [];
		foreach (self::items($reel, 'items') as $item) {
			$story = self::story($item);
			if ($story !== null) {
				$stories[$story['id']] = $story;
			}
		}
		$stories = array_values($stories);
		usort($stories, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);
		return ['error' => '', 'stories' => $stories];
	}

	/**
	 * The cookies to send for an Instagram session, as a Cookie header’s value, from what the user pasted: the value of the
	 * `sessionid` cookie, or cookies as in a Cookie header, of which only those Instagram needs are kept.
	 * @return string|null null when there is no session in it
	 */
	public static function sessionCookie(string $input): ?string {
		$input = trim(preg_replace('/^\s*cookie\s*:/i', '', $input) ?? '');
		$cookies = [];
		if (str_contains($input, '=')) {
			foreach (explode(';', $input) as $pair) {
				$pair = explode('=', $pair, 2);
				$name = trim($pair[0]);
				if (in_array($name, self::SESSION_COOKIES, true) && isset($pair[1])) {
					$cookies[$name] = trim(trim($pair[1]), '"');
				}
			}
		} else {
			$cookies['sessionid'] = trim($input, '"');
		}
		$cookies = array_filter($cookies, static fn(string $value): bool => preg_match('/^[A-Za-z0-9%:._-]+$/', $value) === 1);
		$session = $cookies['sessionid'] ?? '';
		if (strlen($session) < 16) {
			return null;
		}
		// The session starts with the account’s ID, which Instagram’s pages also send as `ds_user_id`
		if (!isset($cookies['ds_user_id']) && preg_match('/^(\d+)(?:%3A|:)/i', $session, $matches) === 1) {
			$cookies['ds_user_id'] = $matches[1];
		}
		$header = [];
		foreach (self::SESSION_COOKIES as $name) {
			if (isset($cookies[$name])) {
				$header[] = $name . '=' . $cookies[$name];
			}
		}
		return implode('; ', $header);
	}

	/** The value of a cookie in a Cookie header’s value, or '' */
	public static function cookieValue(string $cookies, string $name): string {
		foreach (explode(';', $cookies) as $pair) {
			$pair = explode('=', $pair, 2);
			if (trim($pair[0]) === $name) {
				return trim($pair[1] ?? '');
			}
		}
		return '';
	}

	/** @return Post|null */
	private static function post(mixed $node, string $user): ?array {
		$code = self::str($node, 'shortcode');
		if (preg_match('/^' . self::CODE_PATTERN . '$/', $code) !== 1) {
			return null;
		}
		$album = self::str($node, '__typename') === 'GraphSidecar';
		$media = [];
		if ($album) {
			foreach (self::items($node, 'edge_sidecar_to_children', 'edges') as $edge) {
				$item = self::media(self::get($edge, 'node'));
				if ($item !== null) {
					$media[] = $item;
				}
			}
		}
		if ($media === []) {
			$item = self::media($node);
			if ($item !== null) {
				$media[] = $item;
			}
		}
		$owner = strtolower(self::str($node, 'owner', 'username'));
		$owner = self::isUser($owner) ? $owner : $user;
		$coauthors = [];
		foreach (self::items($node, 'coauthor_producers') as $producer) {
			$coauthors[] = strtolower(self::str($producer, 'username'));
		}
		$sponsors = [];
		foreach (self::items($node, 'edge_media_to_sponsor_user', 'edges') as $edge) {
			$sponsors[] = strtolower(self::str($edge, 'node', 'sponsor', 'username'));
		}
		$location = null;
		$place = self::clean(self::str($node, 'location', 'name'));
		if ($place !== '') {
			$id = self::str($node, 'location', 'id');
			$slug = self::str($node, 'location', 'slug');
			$location = ['name' => $place, 'url' => !ctype_digit($id) ? '' : 'https://www.instagram.com/explore/locations/' . $id . '/'
				. (preg_match('/^[a-z0-9-]+$/', $slug) === 1 ? $slug . '/' : '')];
		}
		return [
			'code' => $code,
			'date' => self::int($node, 'taken_at_timestamp'),
			'kind' => $album ? 'album' : (self::get($node, 'is_video') === true ? 'video' : 'photo'),
			'caption' => self::str($node, 'edge_media_to_caption', 'edges', 0, 'node', 'text'),
			'media' => $media,
			'owner' => $owner,
			'coauthors' => self::users($coauthors, [$owner]),
			'sponsors' => self::users($sponsors),
			'partnership' => self::get($node, 'is_paid_partnership') === true,
			'location' => $location,
		];
	}

	/** @return Media|null */
	private static function media(mixed $node): ?array {
		$code = self::str($node, 'shortcode');
		if (preg_match('/^' . self::CODE_PATTERN . '$/', $code) !== 1) {
			return null;
		}
		return [
			'code' => $code,
			'video' => self::get($node, 'is_video') === true,
			'width' => self::int($node, 'dimensions', 'width'),
			'height' => self::int($node, 'dimensions', 'height'),
			'alt' => self::clean(self::str($node, 'accessibility_caption')),
		];
	}

	/** @return Story|null */
	private static function story(mixed $item): ?array {
		$id = self::str($item, 'pk');
		if ($id === '') {
			$id = explode('_', self::str($item, 'id'))[0];
		}
		if (!ctype_digit($id)) {
			return null;
		}
		$image = self::best(self::items($item, 'image_versions2', 'candidates'));
		$video = self::best(self::items($item, 'video_versions'));
		if ($image === '' && $video === '') {
			return null;
		}
		$links = [];
		foreach (self::items($item, 'story_link_stickers') as $sticker) {
			$url = self::httpsUrl(self::unwrapLink(self::str($sticker, 'story_link', 'url')), allowHttp: true);
			if ($url !== '' && !in_array($url, array_column($links, 'url'), true)) {
				$links[] = ['url' => $url, 'title' => self::clean(self::str($sticker, 'story_link', 'link_title'))];
			}
		}
		$posts = [];
		foreach (self::items($item, 'story_feed_media') as $media) {
			$code = self::str($media, 'media_code');
			if (preg_match('/^' . self::CODE_PATTERN . '$/', $code) === 1 && !in_array($code, $posts, true)) {
				$posts[] = $code;
			}
		}
		$mentions = [];
		foreach (self::items($item, 'reel_mentions') as $mention) {
			$mentions[] = strtolower(self::str($mention, 'user', 'username'));
		}
		$hashtags = [];
		foreach (self::items($item, 'story_hashtags') as $hashtag) {
			$name = self::str($hashtag, 'hashtag', 'name');
			if (preg_match('/^[\p{L}\p{N}\p{M}_]{1,100}$/u', $name) === 1 && !in_array($name, $hashtags, true)) {
				$hashtags[] = $name;
			}
		}
		$locations = [];
		foreach (self::items($item, 'story_locations') as $location) {
			$name = self::clean(self::str($location, 'location', 'name'));
			if ($name !== '' && !in_array($name, $locations, true)) {
				$locations[] = $name;
			}
		}
		return [
			'id' => $id,
			'date' => self::int($item, 'taken_at'),
			'video' => $video !== '',
			'image' => $image,
			'video_url' => $video,
			'width' => self::int($item, 'original_width'),
			'height' => self::int($item, 'original_height'),
			'alt' => self::clean(self::str($item, 'accessibility_caption')),
			'links' => $links,
			'posts' => $posts,
			'mentions' => self::users($mentions),
			'hashtags' => $hashtags,
			'locations' => $locations,
		];
	}

	/**
	 * The link of the widest version up to 1080 px wide, else of the narrowest one.
	 * @param list<mixed> $versions
	 */
	private static function best(array $versions): string {
		$best = '';
		$bestWidth = 0;
		foreach ($versions as $version) {
			$url = self::httpsUrl(self::str($version, 'url'));
			$width = self::int($version, 'width');
			if ($url !== '' && ($best === '' || ($bestWidth > 1080 ? $width < $bestWidth : $width <= 1080 && $width > $bestWidth))) {
				$best = $url;
				$bestWidth = $width;
			}
		}
		return $best;
	}

	/** The target of Instagram’s link shim, https://l.instagram.com/?u=<target>&e=… */
	private static function unwrapLink(string $url): string {
		if (preg_match('#^https?://l\.instagram\.com/?\?#i', $url) === 1) {
			parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
			$target = $query['u'] ?? '';
			if (is_string($target) && $target !== '') {
				return $target;
			}
		}
		return $url;
	}

	/** The link if it is an absolute https (or with `$allowHttp`, http) link without spaces, else '' */
	private static function httpsUrl(string $url, bool $allowHttp = false): string {
		return preg_match($allowHttp ? '#^https?://[^\s"<>]+$#i' : '#^https://[^\s"<>]+$#i', $url) === 1 ? $url : '';
	}

	/**
	 * Valid usernames, once each, other than those to leave out.
	 * @param list<string> $users
	 * @param list<string> $except
	 * @return list<string>
	 */
	private static function users(array $users, array $except = []): array {
		$valid = [];
		foreach ($users as $user) {
			if (self::isUser($user) && !in_array($user, $except, true) && !in_array($user, $valid, true)) {
				$valid[] = $user;
			}
		}
		return $valid;
	}

	private static function isUser(string $user): bool {
		return preg_match('/^' . self::USER_PATTERN . '$/', $user) === 1 && trim($user, '.') !== '';
	}

	/**
	 * The object that a page’s `contextJSON` holds, in the embeds of profiles and of posts: a JSON string inside a script.
	 * @return array<mixed>|null
	 */
	private static function contextJson(string $html): ?array {
		$key = '"contextJSON":"';
		$start = strpos($html, $key);
		if ($start === false) {
			return null;	// `"contextJSON":null` for an unknown account
		}
		$start += strlen($key) - 1;	// the opening quote
		$length = strlen($html);
		$end = $start + 1;
		while (true) {
			$end += strcspn($html, '\\"', $end);
			if ($end >= $length) {
				return null;
			}
			if ($html[$end] === '"') {
				break;
			}
			$end += 2;	// an escaped character
		}
		$json = json_decode(substr($html, $start, $end - $start + 1));
		$context = is_string($json) ? json_decode($json, true, 512, JSON_BIGINT_AS_STRING) : null;
		return is_array($context) ? $context : null;
	}

	private static function get(mixed $data, string|int ...$path): mixed {
		foreach ($path as $key) {
			if (!is_array($data) || !array_key_exists($key, $data)) {
				return null;
			}
			$data = $data[$key];
		}
		return $data;
	}

	private static function str(mixed $data, string|int ...$path): string {
		$value = self::get($data, ...$path);
		return is_string($value) ? $value : (is_int($value) ? (string)$value : '');
	}

	private static function int(mixed $data, string|int ...$path): int {
		$value = self::get($data, ...$path);
		return match (true) {
			is_int($value) => $value,
			is_float($value) => (int)$value,
			is_string($value) && ctype_digit($value) => (int)$value,
			default => 0,
		};
	}

	/** @return list<mixed> */
	private static function items(mixed $data, string|int ...$path): array {
		$value = self::get($data, ...$path);
		return is_array($value) ? array_values($value) : [];
	}

	private static function clean(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}
}

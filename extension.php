<?php

declare(strict_types=1);

/**
 * Follow public Instagram profiles in FreshRSS, without an Instagram account.
 *
 * A profile is subscribed to as https://www.instagram.com/<user>/. Whenever FreshRSS loads such a feed, this extension reads
 * the profile’s embed, which Instagram serves anyone for websites to show a profile, with its six latest posts
 * ({@see InstagramProfilesPage}), and hands SimplePie an RSS document of them ({@see InstagramProfilesFeed}).
 * When a story is up, the feed also gets an alert linking to it, dated from the profile page. With an Instagram session in
 * the settings, it gets the stories themselves instead.
 *
 * @phpstan-import-type Profile from InstagramProfilesPage
 * @phpstan-import-type Story from InstagramProfilesPage
 * @phpstan-import-type Item from InstagramProfilesFeed
 */
final class InstagramProfilesExtension extends Minz_Extension {
	/** Refresh intervals offered for new profiles, in minutes; 0 keeps the FreshRSS default */
	public const TTL_CHOICES = [0, 15, 30, 60, 120, 240, 480, 1440];
	/** A browser’s user agent, for the requests made with an Instagram session, which a browser created */
	private const BROWSER_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
	/**
	 * How long a feed keeps the link to its avatar, in seconds. Instagram gives a new link at each request, valid a few days,
	 * and FreshRSS downloads a feed’s icon whenever the link changes, so a new one is taken only now and then.
	 */
	private const AVATAR_MAX_AGE = 7 * 86_400;
	/** The file of the user’s data that says whether the Instagram session worked the last time */
	private const SESSION_STATUS_FILE = 'session.json';
	/** The file of the user’s data with the cookies of the Instagram session, kept between refreshes like a browser’s */
	private const COOKIE_JAR_FILE = 'cookies.txt';

	#[\Override]
	public function init(): void {
		parent::init();
		$this->loadLibraries();
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('feed_before_insert', [$this, 'feedBeforeInsert']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
		$this->registerHook('entry_before_display', [$this, 'entryBeforeDisplay']);
		$this->registerController('instagramProfiles');
	}

	private function loadLibraries(): void {
		require_once __DIR__ . '/lib/InstagramProfilesPage.php';
		require_once __DIR__ . '/lib/InstagramProfilesFeed.php';
		require_once __DIR__ . '/lib/InstagramProfilesMedia.php';
	}

	/**
	 * “Add a feed” accepts a link to a profile, to one of its pages or stories, or to one of its posts or reels; all become
	 * https://www.instagram.com/<user>/.
	 * @throws FreshRSS_Feed_Exception when the author of a post cannot be found
	 */
	public function checkUrlBeforeAdd(string $url): string {
		$link = InstagramProfilesPage::parseUrl($url);
		if ($link === null) {
			return $url;
		}
		[$kind, $value] = $link;
		return InstagramProfilesPage::profileUrl($kind === 'post' ? $this->postOwner($value) : $value);
	}

	/** New profiles get the refresh interval chosen in this extension’s settings. */
	public function feedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		if ((InstagramProfilesPage::parseUrl(self::plainUrl($feed))[0] ?? '') === 'profile' && $feed->ttl() === FreshRSS_Feed::TTL_DEFAULT) {
			$minutes = $this->ttlMinutes();
			if ($minutes > 0) {
				$feed->_ttl($minutes * 60);
			}
		}
		return $feed;
	}

	/**
	 * Before SimplePie fetches an Instagram profile, give it the RSS of the profile’s latest posts, and of its story.
	 * @throws FreshRSS_Feed_Exception when the profile’s embed cannot be loaded or shows no profile
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = self::plainUrl($feed);
		$link = InstagramProfilesPage::parseUrl($url);
		if ($link === null || $link[0] !== 'profile') {
			return;
		}
		$user = $link[1];

		$embedUrl = InstagramProfilesPage::embedUrl($user);
		$cache = $feed->cacheFilename($embedUrl);
		// As `json` rather than `html`: FreshRSS would re-serialise an HTML page through DOMDocument, which turns the non-ASCII
		// characters of the data in its scripts into HTML entities (’ into &rsquo;). Instagram serves the same page.
		$response = FreshRSS_http_Util::httpGet($embedUrl, $cache, 'json', $feed->attributes(), $feed->curlOptions());
		if ($response['fail'] || $response['body'] === '') {
			throw new FreshRSS_Feed_Exception(_t('ext.instagram_profiles.error.fetch', $embedUrl, self::failure($response)), self::status($response));
		}
		$profile = InstagramProfilesPage::parseEmbed($response['body']);
		if ($profile === null || ($profile['posts'] === [] && $profile['count'] > 0)) {
			@unlink($cache);	// Do not let FreshRSS’s cache serve this page again at the next refresh
			throw new FreshRSS_Feed_Exception(_t('ext.instagram_profiles.error.no_profile', $user, $embedUrl));
		}

		$render = new InstagramProfilesFeed($this->labels());
		$items = array_map(static fn(array $post): array => $render->post($profile, $post), $profile['posts']);
		if ($profile['story']) {
			$items = array_merge($items, $this->storyItems($feed, $profile, $render));
		}
		$rss = $render->rss($profile, $this->avatar($feed, $profile['avatar']), $items);

		// SimplePie would answer a refresh within its cache duration (800 s) from its cache rather than from this RSS, which
		// reflects the current settings. Drop the cache as FreshRSS’s own option to clear it does, without its favicon rebuild.
		@unlink($feed->cacheFilename());

		// With a Content-Type rather than force_feed(true), which would make SimplePie cache the feed under another name
		// than the one FreshRSS reads the time of the last refresh from
		$response = (new \SimplePie\HTTP\RawTextResponse($rss, $url))->with_header('content-type', 'application/rss+xml; charset=UTF-8');
		$file = \SimplePie\File::fromResponse($response);
		$simplePie->set_file($file);	// takes a reference
	}

	/**
	 * Items for a profile with a story up: its stories that the feed does not have yet when the Instagram session works, their
	 * pictures kept in the cache, as their links expire; otherwise an alert dated by the latest story, if alerts are on.
	 * @param Profile $profile
	 * @return list<Item>
	 */
	private function storyItems(FreshRSS_Feed $feed, array $profile, InstagramProfilesFeed $render): array {
		$session = $this->session();
		if ($session !== '' && $profile['id'] !== '') {
			$stories = $this->stories($profile, $session);
			if ($stories !== null) {
				$items = [];
				foreach ($stories as $story) {
					$items[$story['id']] = $render->story($profile, $story);
				}
				$items = $this->newItems($feed, $items);
				foreach ($stories as $story) {
					$path = InstagramProfilesMedia::storyPath($story['id']);
					if (isset($items[$story['id']]) && $story['image'] !== '' && !InstagramProfilesMedia::cached($path)) {
						InstagramProfilesMedia::download($story['image'], $path);
					}
				}
				return array_values($items);
			}
		}
		if (!$this->storyAlerts()) {
			return [];
		}
		$time = $this->latestStory($feed, $profile['user']);
		return $time > 0 ? [$render->storyAlert($profile, $time)] : [];
	}

	/**
	 * The profile’s current stories, read from its stories page with the user’s Instagram session; null when that fails, which
	 * is noted for the settings page and in the log.
	 * @param Profile $profile
	 * @return list<Story>|null
	 */
	private function stories(array $profile, string $session): ?array {
		[$html, $error] = $this->storiesPage($profile['user'], $session);
		$result = $error !== '' ? ['error' => $error, 'stories' => []] : InstagramProfilesPage::parseStoriesPage($html, $profile['id']);
		$this->saveSessionStatus($result['error']);
		if ($result['error'] !== '') {
			Minz_Log::warning('Instagram Profiles: the stories of @' . $profile['user'] . ' could not be read with the Instagram session: '
				. $result['error']);
			return null;
		}
		return $result['stories'];
	}

	/**
	 * A profile’s stories page, requested as a logged-in browser opens it: Instagram serves its stories there, whereas its API
	 * refuses such requests. Its first answer is a redirect that sets cookies of the device, which the page needs, so the session
	 * lives in a cookie jar of the user’s data, kept between refreshes like a browser’s: the same device each time, and the
	 * cookies that Instagram renews. FreshRSS’s HTTP client drops cookies between redirects, hence cURL itself, with FreshRSS’s
	 * proxy settings.
	 * @return array{0:string,1:string} the page, and why it could not be read ('' when it could)
	 */
	private function storiesPage(string $user, string $session): array {
		$dir = $this->getExtensionUserPath();
		if (!is_dir($dir)) {
			@mkdir($dir, 0770, true);
		}
		$jar = $dir . '/' . self::COOKIE_JAR_FILE;
		$lock = @fopen($jar . '.lock', 'c');
		if ($lock !== false) {
			flock($lock, LOCK_EX);	// refreshes may overlap
		}
		try {
			if (!is_file($jar) && !self::writeCookieJar($jar, $session)) {
				return ['', 'could not write ' . $jar];
			}
			$ch = curl_init(InstagramProfilesPage::storiesUrl($user));
			if ($ch === false) {
				return ['', 'cURL'];
			}
			$timeout = FreshRSS_Context::systemConf()->limits['timeout'] ?? 20;
			curl_setopt_array($ch, FreshRSS_Context::systemConf()->curl_options);	// e.g. a proxy
			curl_setopt_array($ch, [
				CURLOPT_HTTPHEADER => [
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language: en-US,en;q=0.9',
					'Sec-Fetch-Dest: document',
					'Sec-Fetch-Mode: navigate',
					'Sec-Fetch-Site: none',
					'Sec-Fetch-User: ?1',
					'Upgrade-Insecure-Requests: 1',
				],
				CURLOPT_USERAGENT => self::BROWSER_USER_AGENT,
				CURLOPT_COOKIEFILE => $jar,
				CURLOPT_COOKIEJAR => $jar,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 3,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_CONNECTTIMEOUT => $timeout,
				CURLOPT_TIMEOUT => $timeout,
			]);
			if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
				curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
				curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
			}
			$body = curl_exec($ch);
			$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			unset($ch);	// which writes the jar
			@chmod($jar, 0600);
			if (!is_string($body) || $status !== 200) {
				return ['', $status > 0 ? 'HTTP ' . $status : ($error !== '' ? $error : '?')];
			}
			return [$body, ''];
		} finally {
			if ($lock !== false) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/** Starts a cookie jar (Netscape format) with the cookies of a session, as a Cookie header’s value */
	private static function writeCookieJar(string $jar, string $session): bool {
		$lines = "# Netscape HTTP Cookie File\n";
		foreach (explode(';', $session) as $pair) {
			$pair = explode('=', trim($pair), 2);
			if (count($pair) === 2 && $pair[0] !== '') {
				$lines .= implode("\t", ['.instagram.com', 'TRUE', '/', 'TRUE', (string)(time() + 365 * 86_400), $pair[0], $pair[1]]) . "\n";
			}
		}
		$written = @file_put_contents($jar, $lines) !== false;
		@chmod($jar, 0600);
		return $written;
	}

	/**
	 * Leaves out the items that the feed already has. Instagram’s links to the pictures of stories change at each request,
	 * so FreshRSS would otherwise update those entries at each refresh, and mark them unread again if so set.
	 * @param array<string,Item> $items
	 * @return array<string,Item>
	 */
	private function newItems(FreshRSS_Feed $feed, array $items): array {
		if ($feed->id() <= 0 || $items === []) {
			return $items;
		}
		$known = FreshRSS_Factory::createEntryDao()->listHashForFeedGuids($feed->id(), array_values(array_column($items, 'guid')));
		return array_filter($items, static fn(array $item): bool => !isset($known[$item['guid']]));
	}

	/**
	 * In FreshRSS’s pages, shows the photos of posts and stories through FreshRSS ({@see FreshExtension_instagramProfiles_Controller}):
	 * browsers refuse those of Instagram’s CDN on other sites. Elsewhere (RSS output, API for apps, export), entries keep
	 * Instagram’s links, which apps load as they are.
	 */
	public function entryBeforeDisplay(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (Minz_Request::controllerName() !== 'index' || !in_array(Minz_Request::actionName(), ['normal', 'reader', 'global'], true)) {
			return $entry;
		}
		$content = $entry->content(false);
		$proxied = InstagramProfilesMedia::rewrite($content, InstagramProfilesMedia::proxyUrl(...));
		if ($proxied !== $content) {
			$entry->_content($proxied);
		}
		$thumbnail = $entry->attributeArray('thumbnail') ?? [];
		if (is_string($thumbnail['url'] ?? null)) {
			$url = InstagramProfilesMedia::rewrite($thumbnail['url'], InstagramProfilesMedia::proxyUrl(...));
			if ($url !== $thumbnail['url']) {
				$entry->_attribute('thumbnail', ['url' => $url] + $thumbnail);
			}
		}
		return $entry;
	}

	/** When the profile’s latest story was posted, read from its profile page; 0 when the page does not say */
	private function latestStory(FreshRSS_Feed $feed, string $user): int {
		$url = InstagramProfilesPage::profileUrl($user);
		$cache = $feed->cacheFilename($url);
		// Instagram leaves the profile’s data out of its page unless the request looks like a browser opening a page
		$response = FreshRSS_http_Util::httpGet($url, $cache, 'json', $feed->attributes(), [
			CURLOPT_HTTPHEADER => [
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Sec-Fetch-Dest: document',
				'Sec-Fetch-Mode: navigate',
				'Sec-Fetch-Site: none',
			],
		] + $feed->curlOptions());
		$time = $response['fail'] ? null : InstagramProfilesPage::latestStory($response['body'], $user);
		if ($time === null) {
			@unlink($cache);
			Minz_Log::warning('Instagram Profiles: no time of the latest story of @' . $user . ' on ' . $url
				. ($response['fail'] ? ' (' . self::failure($response) . ')' : ''));
			return 0;
		}
		return $time;
	}

	/**
	 * The author of a post, from the post’s page, else from its embed.
	 * @throws FreshRSS_Feed_Exception
	 */
	private function postOwner(string $code): string {
		foreach (['page' => InstagramProfilesPage::postUrl($code), 'embed' => InstagramProfilesPage::postEmbedUrl($code)] as $kind => $url) {
			$response = FreshRSS_http_Util::httpGet($url, CACHE_PATH . '/instagram-' . $kind . '-' . $code . '.html', 'json');
			$owner = $response['fail'] ? null : ($kind === 'page'
				? InstagramProfilesPage::postPageOwner($response['body']) : InstagramProfilesPage::postEmbedOwner($response['body']));
			if ($owner !== null) {
				return $owner;
			}
		}
		throw new FreshRSS_Feed_Exception(_t('ext.instagram_profiles.error.post', InstagramProfilesPage::postUrl($code)));
	}

	/** The link to the avatar for the feed’s icon: kept on the feed for {@see AVATAR_MAX_AGE} */
	private function avatar(FreshRSS_Feed $feed, string $current): string {
		$kept = $feed->attributeArray('instagram_profiles') ?? [];
		$avatar = is_string($kept['avatar'] ?? null) ? $kept['avatar'] : '';
		$since = is_int($kept['since'] ?? null) ? $kept['since'] : 0;
		if ($current !== '' && ($avatar === '' || $since < time() - self::AVATAR_MAX_AGE)) {
			$avatar = $current;
			$feed->_attribute('instagram_profiles', ['avatar' => $avatar, 'since' => time()]);
			if ($feed->id() > 0) {	// a feed being added is saved with its attributes anyway
				FreshRSS_Factory::createFeedDao()->updateFeed($feed->id(), ['attributes' => $feed->attributes()]);
			}
		}
		return $avatar;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->loadLibraries();	// FreshRSS also configures a disabled extension, without init()
		$this->registerTranslates();
		if (!Minz_Request::isPost()) {
			return;
		}
		$minutes = Minz_Request::paramInt('ttl_minutes');
		$session = $this->session();
		$pasted = Minz_Request::paramString('session', plaintext: true);
		$cookie = $pasted === '' ? null : InstagramProfilesPage::sessionCookie($pasted);
		if (Minz_Request::paramBoolean('session_remove')) {
			$session = '';
		} elseif ($cookie !== null) {
			$session = $cookie;
		}
		if ($session !== $this->session()) {
			$this->saveSessionStatus(null);
			@unlink($this->getExtensionUserPath() . '/' . self::COOKIE_JAR_FILE);	// a new session starts a new jar
		}
		$this->setUserConfiguration([
			'ttl_minutes' => in_array($minutes, self::TTL_CHOICES, true) ? $minutes : 0,
			'story_alerts' => Minz_Request::paramBoolean('story_alerts'),
			'session' => $session,
		]);
		$back = ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
		if ($pasted !== '' && $cookie === null) {
			Minz_Request::bad(_t('ext.instagram_profiles.session.invalid'), $back);
		} else {
			Minz_Request::good(_t('feedback.conf.updated'), $back);
		}
	}

	// getUserConfigurationValue() rather than the typed getters, which need FreshRSS 1.29
	public function ttlMinutes(): int {
		$minutes = $this->getUserConfigurationValue('ttl_minutes');
		return is_int($minutes) ? $minutes : 0;
	}

	public function storyAlerts(): bool {
		$enabled = $this->getUserConfigurationValue('story_alerts');
		return is_bool($enabled) ? $enabled : true;
	}

	/** The Instagram session’s cookies, as a Cookie header’s value, or '' */
	public function session(): string {
		$session = $this->getUserConfigurationValue('session');
		return is_string($session) ? $session : '';
	}

	/**
	 * Whether the Instagram session worked the last time it was used.
	 * @return array{ok:bool,since:int,error:string}|null null when it has not been used since it was saved
	 */
	public function sessionStatus(): ?array {
		$json = @file_get_contents($this->getExtensionUserPath() . '/' . self::SESSION_STATUS_FILE);
		$status = is_string($json) ? json_decode($json, true) : null;
		if (!is_array($status) || !is_bool($status['ok'] ?? null) || !is_int($status['since'] ?? null)) {
			return null;
		}
		return ['ok' => $status['ok'], 'since' => $status['since'], 'error' => is_string($status['error'] ?? null) ? $status['error'] : ''];
	}

	/** Notes whether the session worked (`$error` empty) or not; with null, forgets it. Written only when it changes. */
	private function saveSessionStatus(?string $error): void {
		$path = $this->getExtensionUserPath() . '/' . self::SESSION_STATUS_FILE;
		if ($error === null) {
			@unlink($path);
			return;
		}
		$status = $this->sessionStatus();
		if ($status !== null && $status['ok'] === ($error === '') && $status['error'] === $error) {
			return;
		}
		if (!is_dir(dirname($path))) {
			@mkdir(dirname($path), 0777, true);
		}
		$json = json_encode(['ok' => $error === '', 'since' => time(), 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || @file_put_contents($path, $json) === false) {
			Minz_Log::warning('Instagram Profiles: could not save ' . $path);
		}
	}

	/** @return array<string,string> labels for generated content, in the user’s language */
	private function labels(): array {
		$labels = [];
		foreach (array_keys(InstagramProfilesFeed::LABELS) as $key) {
			$labels[$key] = _t('ext.instagram_profiles.label.' . $key);
		}
		return $labels;
	}

	private static function plainUrl(FreshRSS_Feed $feed): string {
		return htmlspecialchars_decode($feed->url(), ENT_QUOTES);
	}

	/** @param array<string,mixed> $response from FreshRSS_http_Util::httpGet(), whose `status` and `error` exist since FreshRSS 1.29 */
	private static function failure(array $response): string {
		$status = self::status($response);
		$error = $response['error'] ?? '';
		return $status > 0 ? 'HTTP ' . $status : (is_string($error) && $error !== '' ? $error : '?');
	}

	/** @param array<string,mixed> $response */
	private static function status(array $response): int {
		$status = $response['status'] ?? 0;
		return is_int($status) ? abs($status) : 0;
	}
}

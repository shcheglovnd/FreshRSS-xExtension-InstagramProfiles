<?php

declare(strict_types=1);

/**
 * Renders what {@see InstagramProfilesPage} read as an RSS 2.0 document: a profile’s posts, plus its stories or an alert
 * for its latest story.
 *
 * Photos are linked through Instagram’s redirect ({@see InstagramProfilesPage::mediaUrl()}), which keeps working, rather than
 * through the CDN links of its pages, which change at each request and expire. A post therefore renders the same at every
 * refresh, and FreshRSS does not take it for updated.
 *
 * @phpstan-import-type Post from InstagramProfilesPage
 * @phpstan-import-type Profile from InstagramProfilesPage
 * @phpstan-import-type Story from InstagramProfilesPage
 * @phpstan-type Item array{guid:string,link:string,title:string,author:string,date:int,html:string,image:string}
 */
final class InstagramProfilesFeed {
	/** Labels of the generated content, in English; the extension passes them in the user’s language */
	public const LABELS = [
		'photo' => 'Photo',
		'video' => 'Video',
		'album' => 'Album',
		'watch' => 'Watch on Instagram',
		'with' => 'With %s',
		'partnership' => 'Paid partnership',
		'partnership_with' => 'Paid partnership with %s',
		'new_story' => 'New story',
		'story_posted' => '%s posted a story.',
		'story_open' => 'Watch it on Instagram',
		'story' => 'Story',
		'video_story' => 'Video story',
		'shared_post' => 'Shared post',
		'description' => 'Instagram posts of @%s',
	];
	private const TITLE_MAX = 140;
	private const TITLE_MIN_FIRST_LINE = 20;

	/** @var array<string,string> */
	private array $labels;

	/** @param array<string,string> $labels */
	public function __construct(array $labels = []) {
		$this->labels = $labels + self::LABELS;
	}

	/**
	 * @param Profile $profile
	 * @param Post $post
	 * @return Item
	 */
	public function post(array $profile, array $post): array {
		$link = InstagramProfilesPage::postUrl($post['code']);
		$html = '';
		foreach ($post['media'] as $index => $media) {
			$label = $this->labels[$media['video'] ? 'video' : 'photo'];
			$image = '<img src="' . self::esc(InstagramProfilesPage::mediaUrl($media['code'])) . '"'
				. ($media['width'] > 0 && $media['height'] > 0 ? ' width="' . $media['width'] . '" height="' . $media['height'] . '"' : '')
				. ' alt="' . self::esc($media['alt'] !== '' ? $media['alt'] : $label) . '" />';
			if ($media['video']) {
				// Instagram’s pages give no playable link to logged-out visitors: the cover, linked to the video
				$target = self::esc($post['kind'] === 'album' ? $link . '?img_index=' . ($index + 1) : $link);
				$html .= '<p><a href="' . $target . '">' . $image . '</a><br />▶ <a href="' . $target . '">'
					. self::esc($label . ' · ' . $this->labels['watch']) . "</a></p>\n";
			} else {
				$html .= '<p>' . $image . "</p>\n";
			}
		}
		$html .= $this->caption($post['caption']);
		$notes = [];
		if ($post['coauthors'] !== []) {
			$notes[] = $this->label('with', self::userLinks($post['coauthors']));
		}
		if ($post['location'] !== null) {
			$name = self::esc($post['location']['name']);
			$notes[] = '📍 ' . ($post['location']['url'] !== '' ? '<a href="' . self::esc($post['location']['url']) . '">' . $name . '</a>' : $name);
		}
		if ($post['sponsors'] !== []) {
			$notes[] = $this->label('partnership_with', self::userLinks($post['sponsors']));
		} elseif ($post['partnership']) {
			$notes[] = self::esc($this->labels['partnership']);
		}
		if ($notes !== []) {
			$html .= '<p>' . implode(' · ', $notes) . "</p>\n";
		}
		return [
			'guid' => $link,
			'link' => $link,
			'title' => $this->title($post['caption'], $this->labels[$post['kind']]),
			'author' => self::author($profile),
			'date' => $post['date'],
			'html' => $html,
			'image' => $post['media'] !== [] ? InstagramProfilesPage::mediaUrl($post['media'][0]['code']) : '',
		];
	}

	/**
	 * An entry saying that the profile posted a story, linked to its stories, which Instagram shows logged-in visitors only.
	 * One per time of the latest story: stories posted between two refreshes share one alert.
	 * @param Profile $profile
	 * @return Item
	 */
	public function storyAlert(array $profile, int $time): array {
		$link = InstagramProfilesPage::storiesUrl($profile['user']);
		return [
			'guid' => $link . '#' . $time,
			'link' => $link,
			'title' => $this->labels['new_story'],
			'author' => self::author($profile),
			'date' => $time,
			'html' => '<p>' . $this->label('story_posted', self::userLinks([$profile['user']])) . ' <a href="' . self::esc($link) . '">'
				. self::esc($this->labels['story_open']) . "</a></p>\n",
			'image' => '',
		];
	}

	/**
	 * A story, read with an Instagram session. Its picture is Instagram’s link, which expires after a few days, marked with
	 * `#ig-story-<id>` for FreshRSS to show the copy it keeps ({@see InstagramProfilesMedia}). A video is played from the copy
	 * FreshRSS keeps when `$video` says there is one (its link marked `#ig-story-video-<id>`), else is its cover, linked to it.
	 * @param Profile $profile
	 * @param Story $story
	 * @return Item
	 */
	public function story(array $profile, array $story, bool $video = false): array {
		$link = self::storyLink($profile['user'], $story['id']);
		$size = $story['width'] > 0 && $story['height'] > 0 ? ' width="' . $story['width'] . '" height="' . $story['height'] . '"' : '';
		$label = $this->labels[$story['video'] ? 'video_story' : 'story'];
		$image = $story['image'] !== '' ? $story['image'] . '#ig-story-' . $story['id'] : '';
		$html = $image === '' ? '' : '<img src="' . self::esc($image) . '"' . $size . ' alt="' . self::esc($story['alt'] !== '' ? $story['alt'] : $label) . '" />';
		if ($story['video'] && $video && $story['video_url'] !== '') {
			// Without width and height: FreshRSS limits a video’s width only, so its height would stay, with bands; the poster sizes it
			$html = '<p><video controls="controls" preload="none"' . ($image !== '' ? ' poster="' . self::esc($image) . '"' : '')
				. ' src="' . self::esc($story['video_url'] . '#ig-story-video-' . $story['id']) . '"></video></p>' . "\n";
		} elseif ($story['video']) {
			$html = '<p>' . ($html !== '' ? '<a href="' . self::esc($link) . '">' . $html . '</a><br />' : '')
				. '▶ <a href="' . self::esc($link) . '">' . self::esc($label . ' · ' . $this->labels['watch']) . "</a></p>\n";
		} elseif ($html !== '') {
			$html = '<p>' . $html . "</p>\n";
		}
		$details = [];
		foreach ($story['links'] as $sticker) {
			$shown = $sticker['title'] !== '' ? $sticker['title'] : self::shortUrl($sticker['url']);
			$html .= '<p>🔗 <a href="' . self::esc($sticker['url']) . '">' . self::esc($shown) . "</a></p>\n";
			$details[] = $shown;
		}
		foreach ($story['posts'] as $code) {
			$url = InstagramProfilesPage::postUrl($code);
			$html .= '<p>' . self::esc($this->labels['shared_post']) . ': <a href="' . self::esc($url) . '">' . self::esc(self::shortUrl($url)) . "</a></p>\n";
			$details[] = $this->labels['shared_post'];
		}
		$tags = self::userLinks($story['mentions']);
		foreach ($story['hashtags'] as $hashtag) {
			$tags .= ($tags !== '' ? ', ' : '') . '<a href="https://www.instagram.com/explore/tags/' . rawurlencode($hashtag) . '/">#' . self::esc($hashtag) . '</a>';
		}
		if ($tags !== '') {
			$html .= '<p>' . $tags . "</p>\n";
		}
		if ($story['locations'] !== []) {
			$html .= '<p>📍 ' . self::esc(implode(', ', $story['locations'])) . "</p>\n";
		}
		$details = array_merge($details, array_map(static fn(string $user): string => '@' . $user, $story['mentions']));
		return [
			'guid' => $link,
			'link' => $link,
			'title' => self::shorten($label . ($details !== [] ? ' · ' . implode(', ', array_unique($details)) : ''), self::TITLE_MAX),
			'author' => self::author($profile),
			'date' => $story['date'],
			'html' => $html,
			'image' => $image,
		];
	}

	/** A story’s link on Instagram, also its entry’s guid */
	public static function storyLink(string $user, string $id): string {
		return InstagramProfilesPage::storiesUrl($user) . $id . '/';
	}

	/**
	 * The RSS document of a profile, its items newest first.
	 * @param Profile $profile
	 * @param list<Item> $items
	 */
	public function rss(array $profile, string $avatar, array $items): string {
		$link = InstagramProfilesPage::profileUrl($profile['user']);
		$title = self::author($profile);
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
			. "<channel>\n"
			. self::element('title', $title)
			. self::element('link', $link)
			. self::element('description', sprintf($this->labels['description'], $profile['user']));
		if ($avatar !== '') {
			$xml .= self::element('atom:icon', $avatar);	// FreshRSS takes it as the feed’s icon
			$xml .= '<image>' . self::element('url', $avatar) . self::element('title', $title) . self::element('link', $link) . "</image>\n";
		}
		usort($items, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);
		foreach ($items as $item) {
			$xml .= "<item>\n"
				. self::element('title', $item['title'])
				. self::element('link', $item['link'])
				. '<guid isPermaLink="' . ($item['guid'] === $item['link'] ? 'true' : 'false') . '">' . self::escape($item['guid']) . "</guid>\n"
				. ($item['date'] > 0 ? self::element('pubDate', gmdate(DATE_RSS, $item['date'])) : '')
				. self::element('dc:creator', $item['author'])
				. '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlChars($item['html'])) . "]]></description>\n"
				. ($item['image'] !== '' ? '<media:thumbnail url="' . self::escape($item['image']) . '" />' . "\n" : '')
				. "</item>\n";
		}
		return $xml . "</channel>\n</rss>\n";
	}

	/** A caption as paragraphs, with its links, @mentions and #hashtags linked */
	private function caption(string $text): string {
		$html = '';
		foreach (preg_split('/\n[ \t]*\n/', str_replace(["\r\n", "\r"], "\n", $text)) ?: [] as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph !== '') {
				$html .= '<p>' . nl2br(self::richText($paragraph), false) . "</p>\n";
			}
		}
		return $html;
	}

	/** A title from the caption: its first line, or the whole caption when that line is short */
	private function title(string $caption, string $fallback): string {
		$caption = preg_replace('#https?://\S+#', '', $caption) ?? $caption;
		$lines = array_values(array_filter(array_map(static fn(string $line): string => self::clean($line), explode("\n", $caption)),
			static fn(string $line): bool => $line !== '' && preg_match('/^[\p{P}\p{S}\s]+$/u', $line) !== 1));
		if ($lines === []) {
			return $fallback;
		}
		$title = mb_strlen($lines[0]) >= self::TITLE_MIN_FIRST_LINE || count($lines) === 1 ? $lines[0] : implode(' ', $lines);
		return self::shorten($title, self::TITLE_MAX);
	}

	/** A label with its `%s` replaced by HTML */
	private function label(string $key, string $html): string {
		return str_replace('%s', $html, self::esc($this->labels[$key]));
	}

	/**
	 * Escaped text with links, @mentions and #hashtags linked.
	 * One pass over the text, which is a caption, so short enough for FreshRSS’s low pcre.backtrack_limit.
	 */
	private static function richText(string $text): string {
		$pattern = '~(https?://[^\s<>"]+)|(?<![\w.@/])@(' . InstagramProfilesPage::USER_PATTERN . ')|(?<![\w&#/])#([\p{L}\p{N}\p{M}_]+)~u';
		if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
			return self::esc($text);
		}
		$html = '';
		$offset = 0;
		foreach ($matches as $match) {
			[$whole, $start] = $match[0];
			$html .= self::esc(substr($text, $offset, $start - $offset));
			$offset = $start + strlen($whole);
			if (isset($match[1]) && $match[1][1] >= 0) {
				$url = rtrim($match[1][0], '.,;:!?)]}\'');	// trailing punctuation belongs to the sentence
				$offset = $start + strlen($url);
				$html .= '<a href="' . self::esc($url) . '">' . self::esc(self::shortUrl($url)) . '</a>';
			} elseif (isset($match[2]) && $match[2][1] >= 0) {
				$user = rtrim($match[2][0], '.');	// a username does not end with a dot; a sentence may
				$offset = $match[2][1] + strlen($user);
				$html .= self::userLinks([strtolower($user)], '@' . $user);
			} elseif (isset($match[3]) && $match[3][1] >= 0) {
				$html .= '<a href="https://www.instagram.com/explore/tags/' . rawurlencode(mb_strtolower($match[3][0])) . '/">'
					. self::esc('#' . $match[3][0]) . '</a>';
			}
		}
		return $html . self::esc(substr($text, $offset));
	}

	/**
	 * Links to profiles, separated by commas.
	 * @param list<string> $users
	 */
	private static function userLinks(array $users, string $shown = ''): string {
		return implode(', ', array_map(static fn(string $user): string =>
			'<a href="' . self::esc(InstagramProfilesPage::profileUrl($user)) . '">' . self::esc($shown !== '' ? $shown : '@' . $user) . '</a>', $users));
	}

	/** @param Profile $profile */
	private static function author(array $profile): string {
		return ($profile['name'] !== '' ? $profile['name'] . ' ' : '') . '(@' . $profile['user'] . ')';
	}

	/** A link as shown: without its scheme, `www.` and a trailing slash */
	private static function shortUrl(string $url): string {
		$shown = preg_replace('#^https?://(?:www\.)?#i', '', $url) ?? $url;
		$shown = str_ends_with($shown, '/') && substr_count($shown, '/') === 1 ? substr($shown, 0, -1) : $shown;
		return mb_strlen($shown) > 60 ? mb_substr($shown, 0, 59) . '…' : $shown;
	}

	private static function shorten(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false && $space > $max * 0.6) {
			$cut = mb_substr($cut, 0, $space);
		}
		return rtrim($cut, " \t,;:.-–—") . '…';
	}

	private static function clean(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	private static function element(string $name, string $value): string {
		return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
	}

	private static function escape(string $text): string {
		return htmlspecialchars(self::xmlChars($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function esc(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/** Drops characters XML 1.0 does not allow (control characters that sometimes appear in captions) */
	private static function xmlChars(string $text): string {
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}
}

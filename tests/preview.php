<?php

declare(strict_types=1);

/**
 * Manual check of the parser without FreshRSS: prints what a profile would look like as a feed.
 *
 *   php tests/preview.php natgeo                 # fetches https://www.instagram.com/natgeo/embed/
 *   php tests/preview.php natgeo --story         # also reads when the latest story was posted, from the profile page
 *   php tests/preview.php embed.html             # a saved copy of an embed page
 *   php tests/preview.php natgeo --rss           # the generated RSS instead of a summary
 */

require dirname(__DIR__) . '/lib/InstagramProfilesPage.php';
require dirname(__DIR__) . '/lib/InstagramProfilesFeed.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$rss = in_array('--rss', $argv, true);
if ($args === []) {
	fwrite(STDERR, "usage: php tests/preview.php <user> | <saved-embed.html> [--story] [--rss]\n");
	exit(2);
}
$get = static function (string $url, array $headers = []): string {
	$context = stream_context_create(['http' => ['header' => implode("\r\n", array_merge(['User-Agent: FreshRSS/1.30 (Linux; https://freshrss.org)'], $headers)) . "\r\n",
		'timeout' => 20, 'ignore_errors' => true]]);
	return (string)@file_get_contents($url, false, $context);
};
$html = is_file($args[0]) ? (string)file_get_contents($args[0]) : $get(InstagramProfilesPage::embedUrl(ltrim($args[0], '@')));

$profile = InstagramProfilesPage::parseEmbed($html);
if ($profile === null) {
	fwrite(STDERR, "no profile found in the embed\n");
	exit(1);
}
$feed = new InstagramProfilesFeed();
$items = array_map(static fn(array $post): array => $feed->post($profile, $post), $profile['posts']);
$latest = null;
if (in_array('--story', $argv, true) && $profile['story']) {
	$page = $get(InstagramProfilesPage::profileUrl($profile['user']), ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: none']);
	$latest = InstagramProfilesPage::latestStory($page, $profile['user']);
	if ($latest > 0) {
		$items[] = $feed->storyAlert($profile, $latest);
	}
}
if ($rss) {
	echo $feed->rss($profile, $profile['avatar'], $items);
	exit(0);
}
printf("@%s — %s — id %s, %d posts, story up: %s%s\n\n", $profile['user'], $profile['name'], $profile['id'], $profile['count'],
	$profile['story'] ? 'yes' : 'no', $latest === null ? '' : ', latest story ' . ($latest > 0 ? date('Y-m-d H:i', $latest) : var_export($latest, true)));
foreach ($items as $item) {
	printf("%s  %s\n  %s | %d image(s) | %d bytes of HTML\n", $item['date'] > 0 ? date('Y-m-d H:i', $item['date']) : '?', $item['guid'],
		$item['title'], substr_count($item['html'], '<img'), strlen($item['html']));
}

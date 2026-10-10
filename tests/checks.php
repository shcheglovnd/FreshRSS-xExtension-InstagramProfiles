<?php

declare(strict_types=1);

/**
 * Checks of the parser and the renderer without FreshRSS or network: php -d pcre.backtrack_limit=10000 tests/checks.php
 * (FreshRSS lowers that limit, so the checks run under it too).
 */

define('CACHE_PATH', sys_get_temp_dir() . '/instagram-profiles-checks-' . getmypid());
@mkdir(CACHE_PATH);
require dirname(__DIR__) . '/lib/InstagramProfilesPage.php';
require dirname(__DIR__) . '/lib/InstagramProfilesFeed.php';
require dirname(__DIR__) . '/lib/InstagramProfilesMedia.php';

date_default_timezone_set('UTC');
$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void {
	global $failures;
	if ($actual === $expected) {
		echo "ok   $name\n";
	} else {
		$failures++;
		echo "FAIL $name\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
	}
}

/** A page around a `contextJSON`, as in Instagram’s embeds: a JSON string inside the JSON of a script */
function embedPage(?array $context, int $padding = 30000): string {
	$value = $context === null ? 'null' : json_encode(json_encode($context));
	return '<!DOCTYPE html><html><head><script nonce="x">' . str_repeat('/* bootloader */ ', intdiv($padding, 17)) . '</script>'
		. '<script nonce="x">requireLazy(["TimeSliceImpl","ServerJS"],function(TimeSlice,ServerJS){var s=(new ServerJS());s.handle({"require":'
		. '[["PolarisEmbedSimple","init",[],[{"isRichEmbed":false,"isSidecar":false,"isGuideEmbed":false,"isProfileEmbed":true,"contextJSON":'
		. $value . '}]],["NavigationMetrics","setPage",[],[{"page":"XPolarisEmbedProfileController"}]]]});});</script></head><body></body></html>';
}

/** A post as the embed lists it */
function node(string $type, string $code, int $time, string $caption, array $extra = []): array {
	return ['shortcode_media' => $extra + [
		'__typename' => $type,
		'id' => '4005031452860935469',
		'shortcode' => $code,
		'is_video' => $type === 'GraphVideo',
		'dimensions' => ['height' => 1350, 'width' => 1080],
		'display_url' => 'https://scontent.cdninstagram.com/v/x.jpg?oe=6AD044E9',
		'edge_media_to_caption' => ['edges' => $caption === '' ? [] : [['node' => ['text' => $caption]]]],
		'taken_at_timestamp' => $time,
		'owner' => ['id' => '2461212201', 'username' => 'historycoolkids'],
		'location' => null,
		'coauthor_producers' => [],
		'is_paid_partnership' => false,
		'edge_media_to_sponsor_user' => ['edges' => []],
		'pinned_for_users' => [],
	]];
}

function child(string $code, bool $video): array {
	return ['node' => ['__typename' => $video ? 'GraphVideo' : 'GraphImage', 'shortcode' => $code, 'is_video' => $video,
		'dimensions' => ['height' => 1350, 'width' => 1080], 'display_url' => 'https://scontent.cdninstagram.com/v/y.jpg']];
}

function context(array $media, array $extra = []): array {
	return ['context' => $extra + [
		'username' => 'historycoolkids',
		'full_name' => 'History  Cool Kids',
		'verified' => false,
		'profile_pic_url' => 'https://scontent-dfw5-3.cdninstagram.com/v/t51.2885-19/1_n.jpg?stp=dst-jpg_s150x150&oe=6AD04859',
		'followers_count' => 2225473,
		'posts_count' => 4000,
		'has_public_story' => true,
		'graphql_media' => $media,
		'owner_id' => '2461212201',
		'type' => 'Profile',
	]];
}

// --- Links -------------------------------------------------------------------------------------------------------------
$urls = [
	'https://www.instagram.com/historycoolkids/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/HistoryCoolKids' => ['profile', 'historycoolkids'],
	'instagram.com/historycoolkids?igsh=MXRzcG9h' => ['profile', 'historycoolkids'],
	'http://m.instagram.com/historycoolkids/reels/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/historycoolkids/tagged/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/historycoolkids/embed/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/stories/historycoolkids/3456789012345678901/' => ['profile', 'historycoolkids'],
	'https://instagram.com/_u/historycoolkids/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/historycoolkids/p/DeUuuMGCu0t/' => ['profile', 'historycoolkids'],
	'https://www.instagram.com/p/DeUuuMGCu0t/?utm_source=ig_web_copy_link' => ['post', 'DeUuuMGCu0t'],
	'https://www.instagram.com/reel/DeUKkHjgMNR/' => ['post', 'DeUKkHjgMNR'],
	'https://www.instagram.com/tv/DeUKkHjgMNR' => ['post', 'DeUKkHjgMNR'],
	'https://www.instagram.com/first.last_99/' => ['profile', 'first.last_99'],
	'https://www.instagram.com/' => null,
	'https://www.instagram.com/explore/tags/history/' => null,
	'https://www.instagram.com/stories/highlights/17912345678901234/' => null,
	'https://www.instagram.com/accounts/login/' => null,
	'https://www.instagram.com/historycoolkids/followers/' => null,
	'https://www.instagram.com/.../' => null,
	'https://www.instagram.com/p/x/' => null,
	'https://notinstagram.com/historycoolkids/' => null,
	'https://www.instagram.com.evil.example/historycoolkids/' => null,
	'https://x.com/historycoolkids' => null,
	'@historycoolkids' => null,
];
foreach ($urls as $url => $expected) {
	check("parseUrl $url", InstagramProfilesPage::parseUrl($url), $expected);
}
check('profileUrl', InstagramProfilesPage::profileUrl('NatGeo'), 'https://www.instagram.com/natgeo/');
check('mediaUrl', InstagramProfilesPage::mediaUrl('DeUuuMGCu0t'), 'https://www.instagram.com/p/DeUuuMGCu0t/media/?size=l');

// --- Embed ------------------------------------------------------------------------------------------------------------
$tricky = "Quotes \" and \\ backslash, </script> and ’ and 🌿 \u{2028} done";
$media = [
	node('GraphImage', 'DeUqKlyCo7o', 1791654593, "First post\n\nSecond paragraph"),
	node('GraphVideo', 'DeUKkHjgMNR', 1791656982, $tricky, ['location' => ['id' => '212988663', 'has_public_page' => true,
		'name' => 'New  York', 'slug' => 'new-york-new-york']]),
	node('GraphSidecar', 'DeR4RetDg3c', 1791600000, '', ['edge_sidecar_to_children' => ['edges' => [child('DeR4NlXAHcB', true),
		child('DeR4M8ylyrT', false), ['node' => ['shortcode' => 'bad code']]]], 'coauthor_producers' => [['username' => 'natgeoscience'],
		['username' => 'historycoolkids'], ['username' => 'natgeoscience']], 'is_paid_partnership' => true,
		'edge_media_to_sponsor_user' => ['edges' => [['node' => ['sponsor' => ['username' => 'Rolex']]]]]]),
	node('GraphImage', 'DeUqKlyCo7o', 1791654593, 'duplicate'),
	node('GraphImage', '<bad>', 1791654000, 'invalid code'),
	['shortcode_media' => null],
	'garbage',
];
$profile = InstagramProfilesPage::parseEmbed(embedPage(context($media)));
check('embed parsed', $profile !== null, true);
if ($profile === null) {
	exit(1);
}
check('embed user', $profile['user'], 'historycoolkids');
check('embed name, spaces collapsed', $profile['name'], 'History Cool Kids');
check('embed id', $profile['id'], '2461212201');
check('embed story', $profile['story'], true);
check('embed count', $profile['count'], 4000);
check('embed avatar', str_starts_with($profile['avatar'], 'https://scontent-dfw5-3.cdninstagram.com/'), true);
check('embed posts, newest first, once each, valid only', array_column($profile['posts'], 'code'), ['DeUKkHjgMNR', 'DeUqKlyCo7o', 'DeR4RetDg3c']);
[$video, $image, $album] = $profile['posts'];
check('video kind', $video['kind'], 'video');
check('video caption decoded exactly', $video['caption'], $tricky);
check('video media', $video['media'], [['code' => 'DeUKkHjgMNR', 'video' => true, 'width' => 1080, 'height' => 1350, 'alt' => '']]);
check('location', $video['location'], ['name' => 'New York', 'url' => 'https://www.instagram.com/explore/locations/212988663/new-york-new-york/']);
check('image kind', $image['kind'], 'photo');
check('album kind', $album['kind'], 'album');
check('album children, valid only', array_column($album['media'], 'code'), ['DeR4NlXAHcB', 'DeR4M8ylyrT']);
check('album child video', array_column($album['media'], 'video'), [true, false]);
check('coauthors without the owner, once', $album['coauthors'], ['natgeoscience']);
check('sponsors', $album['sponsors'], ['rolex']);
check('paid partnership', $album['partnership'], true);

check('unknown account: contextJSON null', InstagramProfilesPage::parseEmbed(embedPage(null)), null);
check('no contextJSON', InstagramProfilesPage::parseEmbed('<html><body>Log in</body></html>'), null);
check('truncated contextJSON', InstagramProfilesPage::parseEmbed(substr(embedPage(context($media)), 0, 31000)), null);
check('no graphql_media', InstagramProfilesPage::parseEmbed(embedPage(['context' => ['username' => 'historycoolkids']])), null);
$empty = InstagramProfilesPage::parseEmbed(embedPage(context([], ['posts_count' => 0, 'has_public_story' => false, 'owner_id' => 'x1'])));
check('profile without posts', [$empty['posts'] ?? null, $empty['story'] ?? null, $empty['id'] ?? null], [[], false, '']);
// A big page, to check the scan under FreshRSS’s pcre.backtrack_limit
$big = context(array_fill(0, 6, node('GraphImage', 'DeUuuMGCu0t', 1791656982, str_repeat('Long caption with ünïcode. ', 80))));
check('big page', count(InstagramProfilesPage::parseEmbed(embedPage($big, 500000))['posts'] ?? []), 1);

$postPage = embedPage(['context' => ['type' => 'GraphSidecar', 'shortcode' => 'DeR4RetDg3c'], 'gql_data' => ['shortcode_media' =>
	['__typename' => 'GraphSidecar', 'owner' => ['username' => 'NatGeo']]]]);
check('post owner, embed', InstagramProfilesPage::postEmbedOwner($postPage), 'natgeo');
check('post owner, no embed', InstagramProfilesPage::postEmbedOwner(embedPage(null)), null);
check('post owner, page', InstagramProfilesPage::postPageOwner('<head><meta property="og:url" content="https://www.instagram.com/historycoolkids/p/DeUuuMGCu0t/" />'), 'historycoolkids');
check('post owner, page without it', InstagramProfilesPage::postPageOwner('<meta property="og:url" content="https://www.instagram.com/p/DeUuuMGCu0t/" />'), null);
check('post owner, login page', InstagramProfilesPage::postPageOwner('<html><title>Login • Instagram</title></html>'), null);

// --- Latest story on the profile page ---------------------------------------------------------------------------------
$posts = '"xig_user_by_username":{"pk":"2461212201","polaris_ordered_timeline_connection":{"edges":[{"node":{"user":{"username":"historycoolkids"}}}]}}';
$root = '"xig_user_by_username":{"pk":"2461212201","username":"historycoolkids","profile_pic_url":"https:\/\/x","is_private":false,'
	. '"latest_reel_media":1791656143,"biography":""}';
check('latest story', InstagramProfilesPage::latestStory('<script>' . $posts . str_repeat(' ', 50000) . $root . '</script>', 'historycoolkids'), 1791656143);
check('latest story, other user', InstagramProfilesPage::latestStory($root, 'natgeo'), null);
check('latest story null', InstagramProfilesPage::latestStory(str_replace('1791656143', 'null', $root), 'historycoolkids'), 0);
check('latest story absent', InstagramProfilesPage::latestStory($posts, 'historycoolkids'), null);
check('latest story, logged-out shell page', InstagramProfilesPage::latestStory('<html><title>Instagram</title></html>', 'historycoolkids'), null);

// --- Stories from the stories page, as served to a logged-in browser -------------------------------------------------
/** A logged-in stories page: the stories in the JSON of a script, as Instagram serializes its prefetched queries */
function storiesPage(array $reels, int $padding = 30000): string {
	$data = ['require' => [['ScheduledServerJS', 'handle', null, [['__bbox' => ['require' => [['RelayPrefetchedStreamCache', 'next', [],
		['adp_PolarisStoriesV3ReelPageStandaloneQueryRelayPreloader_1', ['__bbox' => ['complete' => true, 'result' => ['data' =>
		['xdt_api__v1__feed__reels_media' => ['reels_media' => $reels, 'unviewable_authors_infos' => []]]]]]]]]]]]]]];
	return '<!DOCTYPE html><html><head><script type="application/json" data-sjs>{"require":[["qplTimingsServerJS",null,null,["1","tierOne"]]]}</script>'
		. str_repeat('<script>/* bootloader */</script>', intdiv($padding, 33))
		. '<script type="application/json" data-content-len="1" data-sjs>' . json_encode($data, JSON_UNESCAPED_SLASHES) . '</script></head><body></body></html>';
}
$items = [
	['pk' => '3456789012345678901', 'id' => '3456789012345678901_2461212201', 'taken_at' => 1791650000, 'media_type' => 1, 'code' => 'DeU3E6uAn3S',
		'image_versions2' => ['candidates' => [['width' => 1440, 'height' => 2560, 'url' => 'https://scontent.cdninstagram.com/big.jpg'],
			['width' => 1080, 'height' => 1920, 'url' => 'https://scontent.cdninstagram.com/1080.jpg'],
			['width' => 640, 'height' => 1138, 'url' => 'https://scontent.cdninstagram.com/640.jpg']]],
		'original_width' => 1080, 'original_height' => 1920,
		'story_link_stickers' => [['story_link' => ['url' => 'https://l.instagram.com/?u=https%3A%2F%2Fexample.com%2Fa%3Fb%3D1&e=AT0', 'link_title' => 'Read more']],
			['story_link' => ['url' => 'javascript:alert(1)']]],
		'story_feed_media' => [['x' => 0.5, 'y' => 0.5, 'media_code' => 'DeUuuMGCu0t', 'id' => '18150353107514787', 'product_type' => 'feed']],
		'story_bloks_stickers' => [['bloks_sticker' => ['bloks_sticker_type' => 'mention', 'sticker_data' => ['ig_mention' => ['username' => 'NatGeo']]]]],
		'story_hashtags' => [['hashtag' => ['name' => 'history']]],
		'story_locations' => [['location' => ['name' => 'Boston']]]],
	['pk' => 3456789012345678999, 'taken_at' => 1791656143, 'media_type' => 2,
		'image_versions2' => ['candidates' => [['width' => 640, 'height' => 1138, 'url' => 'https://scontent.cdninstagram.com/cover.jpg']]],
		'video_versions' => [['type' => 101, 'url' => 'https://scontent.cdninstagram.com/v.mp4'], ['type' => 102, 'url' => 'https://scontent.cdninstagram.com/v480.mp4']],
		'reel_mentions' => [['user' => ['username' => 'historycoolkids']]], 'story_link_stickers' => null, 'story_hashtags' => null,
		'user' => ['pk' => '2461212201', 'username' => 'historycoolkids']],
	['pk' => 'not a number'],
	['pk' => '3456789012345678000', 'taken_at' => 1791640000],
];
$result = InstagramProfilesPage::parseStoriesPage(storiesPage([['id' => '787132', 'items' => [$items[1]]], ['id' => '2461212201', 'items' => $items]]), '2461212201');
check('stories ok', $result['error'], '');
check('stories of the profile, newest first, with media only', array_column($result['stories'], 'id'), ['3456789012345678999', '3456789012345678901']);
[$videoStory, $imageStory] = $result['stories'];
check('story picture: widest up to 1080', $imageStory['image'], 'https://scontent.cdninstagram.com/1080.jpg');
check('story link unwrapped, javascript dropped', $imageStory['links'], [['url' => 'https://example.com/a?b=1', 'title' => 'Read more']]);
check('story shared post, mention (sticker), hashtag, place', [$imageStory['posts'], $imageStory['mentions'], $imageStory['hashtags'], $imageStory['locations']],
	[['DeUuuMGCu0t'], ['natgeo'], ['history'], ['Boston']]);
check('video story', [$videoStory['video'], $videoStory['video_url'], $videoStory['image'], $videoStory['mentions']],
	[true, 'https://scontent.cdninstagram.com/v.mp4', 'https://scontent.cdninstagram.com/cover.jpg', []]);	// its author’s own mention left out
check('stories page without this profile’s stories', InstagramProfilesPage::parseStoriesPage(storiesPage([]), '2461212201'), ['error' => '', 'stories' => []]);
check('stories page logged out', InstagramProfilesPage::parseStoriesPage('<html><title>Instagram</title><script type="application/json">{}</script></html>', '1')['error'],
	'no stories data in the page, so the session is probably logged out');
check('stories page with broken data', InstagramProfilesPage::parseStoriesPage('<script type="application/json">{"xdt_api__v1__feed__reels_media":</script>', '1')['error'],
	'unreadable stories data');
check('big stories page', count(InstagramProfilesPage::parseStoriesPage(storiesPage([['id' => '2461212201', 'items' => array_fill(0, 30, $items[0])]], 800000),
	'2461212201')['stories']), 1);

// --- Session cookies --------------------------------------------------------------------------------------------------
$sid = '81234567890%3AAbCdEfGhIjKlMn%3A27%3AAYdLongerPart_x-Y';
check('session: bare value', InstagramProfilesPage::sessionCookie("  $sid\n"), "sessionid=$sid; ds_user_id=81234567890");
check('session: quoted', InstagramProfilesPage::sessionCookie("\"$sid\""), "sessionid=$sid; ds_user_id=81234567890");
check('session: cookie header', InstagramProfilesPage::sessionCookie("Cookie: mid=abc; csrftoken=Tok123; sessionid=$sid; ds_user_id=81234567890; rur=\"CLN\""),
	"sessionid=$sid; ds_user_id=81234567890; csrftoken=Tok123");
check('session: missing', InstagramProfilesPage::sessionCookie('csrftoken=Tok123; mid=abc'), null);
check('session: too short', InstagramProfilesPage::sessionCookie('abc'), null);
check('session: bad characters', InstagramProfilesPage::sessionCookie("sessionid=$sid\r\nX-Evil: 1"), null);
check('session: password-like text', InstagramProfilesPage::sessionCookie('my secret pass phrase'), null);

// --- Rendering --------------------------------------------------------------------------------------------------------
$feed = new InstagramProfilesFeed();
$itemImage = $feed->post($profile, $image);
check('post guid', $itemImage['guid'], 'https://www.instagram.com/p/DeUqKlyCo7o/');
check('post title: a short first line is joined with the rest', $itemImage['title'], 'First post Second paragraph');
check('post author', $itemImage['author'], 'History Cool Kids (@historycoolkids)');
check('post image', $itemImage['image'], 'https://www.instagram.com/p/DeUqKlyCo7o/media/?size=l');
check('post html', $itemImage['html'], '<p><img src="https://www.instagram.com/p/DeUqKlyCo7o/media/?size=l" width="1080" height="1350" alt="Photo" /></p>' . "\n"
	. "<p>First post</p>\n<p>Second paragraph</p>\n");
$itemVideo = $feed->post($profile, $video);
check('video html escapes the caption', str_contains($itemVideo['html'], '</script>') || str_contains($itemVideo['html'], '"  and'), false);
check('video html: cover linked, place', [str_contains($itemVideo['html'], '<a href="https://www.instagram.com/p/DeUKkHjgMNR/"><img'),
	str_contains($itemVideo['html'], '📍 <a href="https://www.instagram.com/explore/locations/212988663/new-york-new-york/">New York</a>')], [true, true]);
$itemAlbum = $feed->post($profile, $album);
check('album title without caption', $itemAlbum['title'], 'Album');
check('album: video child links to its slide', str_contains($itemAlbum['html'], 'href="https://www.instagram.com/p/DeR4RetDg3c/?img_index=1"'), true);
check('album: notes', str_contains($itemAlbum['html'], '<p>With <a href="https://www.instagram.com/natgeoscience/">@natgeoscience</a> · '
	. 'Paid partnership with <a href="https://www.instagram.com/rolex/">@rolex</a></p>'), true);

$render = static function (string $caption) use ($feed, $profile, $image): array {
	$item = $feed->post($profile, ['caption' => $caption] + $image);
	$html = $item['html'];
	$start = strpos($html, "</p>\n") + 5;
	return [$item['title'], substr($html, $start)];
};
[, $html] = $render('See https://example.com/a_(b). Mail me at me@example.com, ask @first.last. #History #Ünïcode #1 &#39; <b>x</b>');
check('rich text', $html, '<p>See <a href="https://example.com/a_(b">example.com/a_(b</a>). Mail me at me@example.com, ask '
	. '<a href="https://www.instagram.com/first.last/">@first.last</a>. <a href="https://www.instagram.com/explore/tags/history/">#History</a> '
	. '<a href="https://www.instagram.com/explore/tags/%C3%BCn%C3%AFcode/">#Ünïcode</a> <a href="https://www.instagram.com/explore/tags/1/">#1</a> '
	. '&amp;#39; &lt;b&gt;x&lt;/b&gt;</p>' . "\n");
[$title, $html] = $render("🇺🇸🦅\n.\nShort\nsecond line\n\n\nthird paragraph\r\nwith CRLF");
check('title skips symbol lines, joins short lines', $title, 'Short second line third paragraph with CRLF');
check('paragraphs and line breaks', $html, "<p>🇺🇸🦅<br>\n.<br>\nShort<br>\nsecond line</p>\n<p>third paragraph<br>\nwith CRLF</p>\n");
[$title] = $render(str_repeat('word ', 60));
check('long title shortened', [mb_strlen($title) <= 141, str_ends_with($title, '…')], [true, true]);
[$title] = $render('https://example.com only a link');
check('title without links', $title, 'only a link');

$alert = $feed->storyAlert($profile, 1791656143);
check('alert guid', $alert['guid'], 'https://www.instagram.com/stories/historycoolkids/#1791656143');
check('alert', [$alert['title'], $alert['link'], $alert['date']], ['New story', 'https://www.instagram.com/stories/historycoolkids/', 1791656143]);
$storyItem = $feed->story($profile, $imageStory);
check('story guid', $storyItem['guid'], 'https://www.instagram.com/stories/historycoolkids/3456789012345678901/');
check('story title', $storyItem['title'], 'Story · Read more, Shared post, @natgeo');
check('story html', $storyItem['html'], '<p><img src="https://scontent.cdninstagram.com/1080.jpg#ig-story-3456789012345678901" width="1080" height="1920" alt="Story" /></p>' . "\n"
	. '<p>🔗 <a href="https://example.com/a?b=1">Read more</a></p>' . "\n"
	. '<p>Shared post: <a href="https://www.instagram.com/p/DeUuuMGCu0t/">instagram.com/p/DeUuuMGCu0t/</a></p>' . "\n"
	. '<p><a href="https://www.instagram.com/natgeo/">@natgeo</a>, <a href="https://www.instagram.com/explore/tags/history/">#history</a></p>' . "\n"
	. "<p>📍 Boston</p>\n");
$videoItem = $feed->story($profile, $videoStory);
check('video story: its cover, linked', $videoItem['html'], '<p><a href="https://www.instagram.com/stories/historycoolkids/3456789012345678999/">'
	. '<img src="https://scontent.cdninstagram.com/cover.jpg#ig-story-3456789012345678999" alt="Video story" /></a><br />'
	. '▶ <a href="https://www.instagram.com/stories/historycoolkids/3456789012345678999/">Video story · Watch on Instagram</a></p>' . "\n");
check('story thumbnail marked', $videoItem['image'], 'https://scontent.cdninstagram.com/cover.jpg#ig-story-3456789012345678999');
check('video story title', $videoItem['title'], 'Video story');

$labels = (new InstagramProfilesFeed(['new_story' => 'Новая история']))->storyAlert($profile, 1);
check('labels in another language', $labels['title'], 'Новая история');

// --- RSS --------------------------------------------------------------------------------------------------------------
$bad = $feed->post($profile, ['caption' => "Control\x01char and ]]> end"] + $image);
$xml = $feed->rss($profile, $profile['avatar'], [$itemImage, $alert, $itemVideo, $bad, $storyItem]);
$doc = @simplexml_load_string($xml);
check('RSS is well-formed', $doc !== false, true);
if ($doc !== false) {
	check('RSS title', (string)$doc->channel->title, 'History Cool Kids (@historycoolkids)');
	check('RSS icon', (string)$doc->channel->children('http://www.w3.org/2005/Atom')->icon, $profile['avatar']);
	$guids = [];
	foreach ($doc->channel->item as $item) {
		$guids[] = (string)$item->guid . ((string)$item->guid['isPermaLink'] === 'false' ? ' (not a link)' : '');
	}
	check('RSS items newest first', $guids, ['https://www.instagram.com/p/DeUKkHjgMNR/', 'https://www.instagram.com/stories/historycoolkids/#1791656143 (not a link)',
		'https://www.instagram.com/p/DeUqKlyCo7o/', 'https://www.instagram.com/p/DeUqKlyCo7o/', 'https://www.instagram.com/stories/historycoolkids/3456789012345678901/']);
	check('RSS keeps ]]> and drops control characters', str_contains((string)$doc->channel->item[3]->description, 'Controlchar and ]]&gt; end'), true);
}

// --- Photos served by FreshRSS ------------------------------------------------------------------------------------------
$proxy = static fn(string $kind, string $id): string => "./?c=instagramProfiles&amp;a=media&amp;$kind=$id";
check('rewrite post photos', InstagramProfilesMedia::rewrite($itemAlbum['html'] . $itemImage['image'], $proxy) === str_replace(
	['https://www.instagram.com/p/DeR4NlXAHcB/media/?size=l', 'https://www.instagram.com/p/DeR4M8ylyrT/media/?size=l', 'https://www.instagram.com/p/DeUqKlyCo7o/media/?size=l'],
	['./?c=instagramProfiles&amp;a=media&amp;post=DeR4NlXAHcB', './?c=instagramProfiles&amp;a=media&amp;post=DeR4M8ylyrT', './?c=instagramProfiles&amp;a=media&amp;post=DeUqKlyCo7o'],
	$itemAlbum['html'] . $itemImage['image']), true);
check('rewrite leaves post links', str_contains(InstagramProfilesMedia::rewrite($itemAlbum['html'], $proxy), 'href="https://www.instagram.com/p/DeR4RetDg3c/?img_index=1"'), true);
$storyHtml = '<img src="https://scontent.cdninstagram.com/v/a.jpg?x=1&amp;oe=6AD0#ig-story-3456789012345678999" />';
check('rewrite story picture not kept', InstagramProfilesMedia::rewrite($storyHtml, $proxy), $storyHtml);
file_put_contents(InstagramProfilesMedia::storyPath('3456789012345678999'), "\xFF\xD8\xFF\xE0fake jpeg");
check('rewrite story picture kept', InstagramProfilesMedia::rewrite($storyHtml, $proxy), '<img src="./?c=instagramProfiles&amp;a=media&amp;story=3456789012345678999" />');
$many = str_repeat('<a href="https://www.instagram.com/explore/tags/' . str_repeat('x', 200) . '/">#x</a> ', 2000) . $storyHtml;
check('rewrite under the backtrack limit', str_ends_with(InstagramProfilesMedia::rewrite($many, $proxy), 'story=3456789012345678999" />'), true);
check('picture types', array_map(InstagramProfilesMedia::type(...), ["\xFF\xD8\xFF\xDB", "\x89PNG\r\n\x1A\nxx", 'GIF89a', 'RIFF1234WEBPVP8 ', '<!DOCTYPE html>', '']),
	['image/jpeg', 'image/png', 'image/gif', 'image/webp', null, null]);
array_map('unlink', glob(CACHE_PATH . '/*') ?: []);
@rmdir(CACHE_PATH);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);

# Instagram Profiles — FreshRSS extension

Follow **public Instagram profiles** in [FreshRSS](https://freshrss.org): no RSSHub, RSS-Bridge or other proxy, no API keys,
and for posts no Instagram account. Paste a profile link into *Add a feed*.

Instagram serves every public profile’s **embed** (`https://www.instagram.com/<user>/embed/`, what websites show to present
a profile) to anyone, with the profile’s latest posts in full. Whenever FreshRSS refreshes such a subscription, this
extension reads that embed and hands FreshRSS a proper RSS document built from it.

**Stories** are shown by Instagram to logged-in visitors only. Without an account, a feed gets an alert when a story is up,
which opens it in the Instagram app. With the session cookie of an Instagram account in the settings, it gets the stories
themselves.

## What you get

* **Each refresh sees the 6 latest posts of the profile.** FreshRSS keeps every post it has seen, so the feed grows over
  time; for accounts that post a lot, use a shorter refresh interval.
* **Photos** at 1080 px, **every photo of an album**, and the **cover of a video or reel**, linked to it on Instagram (Instagram
  gives logged-out visitors no playable link).
* **Captions** in full, in paragraphs, with links, `@mentions` and `#hashtags` linked; co-authors, place and paid
  partnerships.
* **Titles** from the caption’s first line, the author as *Name (@user)*, the exact time of the post, and the profile picture
  as the feed icon.
* **Stories:**
  * without a session, an entry **“New story”** for each refresh that finds a newer story, dated by it and linked to the
    profile’s stories (on a phone, they open in the Instagram app);
  * with a session, **each story as an entry**: its picture, or a video’s cover linked to it, with its links, mentions,
    hashtags, place and shared post.

### Photos are served by FreshRSS

Instagram’s CDN forbids other sites to show its pictures (`Cross-Origin-Resource-Policy: same-origin`), so browsers refuse
them in FreshRSS. In FreshRSS’s pages the extension therefore shows them through FreshRSS:
`./?c=instagramProfiles&a=media&post=<code>` downloads a post’s photo through Instagram’s own redirect
`https://www.instagram.com/p/<code>/media/?size=l`, which keeps working, and keeps it in FreshRSS’s cache. FreshRSS empties
its cache of files older than 30 days; a photo is then downloaded again when shown. A story’s picture is downloaded when the
story is read, as its link expires with it, and is kept 30 days.

This works for logged-in users (and anonymous readers if FreshRSS allows them). Elsewhere, entries keep Instagram’s links:
the RSS output and the API used by apps, which load them as they are.

## Install

1. Download this repository into a folder named `xExtension-InstagramProfiles`, either
   `git clone https://github.com/shcheglovnd/FreshRSS-xExtension-InstagramProfiles.git xExtension-InstagramProfiles`, or
   *Code → Download ZIP* and rename the extracted folder.
2. Put that folder into the `extensions/` directory of your FreshRSS. With the official Docker image that is the volume
   mounted at `/var/www/FreshRSS/extensions`.
3. In FreshRSS: *Settings → Extensions*, then enable **Instagram Profiles**. It is a per-user extension.

Requires FreshRSS **1.28 or newer**.

## Use

*Subscription management → Add a feed*, then paste any of these:

* `https://www.instagram.com/natgeo/`, also with `?igsh=…` as shared from the app
* a page of the profile: `…/natgeo/reels/`, `…/natgeo/tagged/`, `https://www.instagram.com/stories/natgeo/`
* a link to one of its posts or reels: `https://www.instagram.com/p/DeR4RetDg3c/`, `…/reel/…`; its author is looked up

All of them are stored as `https://www.instagram.com/natgeo/`.

### Settings

*Settings → Extensions → Instagram Profiles ⚙*:

* **Refresh interval for new profiles.** FreshRSS’s default unless you choose one.
* **Alert when a profile posts a story.** On by default.
* **Instagram session, for stories.** The value of the `sessionid` cookie of an Instagram account; a whole Cookie header
  works too. To get it, log in at instagram.com in a private window of a desktop browser, open the developer tools
  (*Application* or *Storage* → *Cookies* → `https://www.instagram.com`), copy `sessionid`, and close the window
  **without logging out**, which would end the session. The settings page says whether the session worked the last time;
  if it stops working, stories come as alerts again until you paste a new one.

**Use an account made for this.** Instagram may restrict, or ask to verify, an account that it sees reading automatically.
The session is only used to list the stories of a profile that has one up, at its refreshes, with a browser’s user agent.
Posts are always read without it. It is kept in your FreshRSS user configuration, like other settings, and never shown
back.

## Limitations

* **Public profiles only.** Private, unknown and suspended accounts show an error.
* **Six posts per refresh**, as explained above.
* **Tied to Instagram’s embed.** If Instagram changes it, or stops serving it, the extension needs an update; feeds then
  show an error instead of silently going empty.
* **Videos are not played in FreshRSS**: their cover links to Instagram.
* **Stories need a session**; without one, only alerts. Story pictures are kept 30 days.
* **Disabling the extension breaks these feeds**, and FreshRSS’s pages then show no photos.
* **Instagram’s terms.** Reading public pages this way is for personal use. Keep refresh intervals moderate.

## How it works

* `check_url_before_add` normalises every supported link to `https://www.instagram.com/<user>/`, looking up the author of a
  post on its page (`og:url`), else in its embed.
* `simplepie_before_init` downloads the profile’s embed with FreshRSS’s own HTTP client, so proxy settings, timeouts,
  caching and Retry-After handling are respected. `InstagramProfilesPage` reads the posts from the JSON in the page
  (`contextJSON`), with string scanning rather than regular expressions over the page, as FreshRSS lowers
  `pcre.backtrack_limit`. `InstagramProfilesFeed` renders RSS 2.0, which is given to SimplePie as the feed body.
  * When the embed says a story is up: with a session, the stories come from Instagram’s API
    (`/api/v1/feed/reels_media/`), and those the feed already has are left out, as Instagram’s links change at each
    request; otherwise the time of the latest story comes from the profile page, which Instagram renders with the
    profile’s data when the request looks like a browser opening it (`Sec-Fetch-*` headers).
  * Photos are linked through `https://www.instagram.com/p/<code>/media/?size=l`, so a post renders the same at every refresh
    and FreshRSS does not take it for updated.
* `entry_before_display` points the photos and thumbnails of FreshRSS’s pages at the extension’s controller
  (`Controllers/instagramProfilesController.php`), which serves them from FreshRSS’s cache (`InstagramProfilesMedia`).
* `feed_before_insert` applies the refresh interval to newly added profiles.

## Development

```sh
php -d pcre.backtrack_limit=10000 tests/checks.php   # parser and renderer, no FreshRSS or network
php tests/preview.php natgeo                         # live https://www.instagram.com/natgeo/embed/
php tests/preview.php natgeo --story                 # also the time of the latest story
php tests/preview.php saved-embed.html --rss         # print the generated RSS
```

## License

[MIT](LICENSE)

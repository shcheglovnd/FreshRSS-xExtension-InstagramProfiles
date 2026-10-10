# Changelog

## 0.1.0 — 2026-10-10

First release.

* Follow a public Instagram profile with “Add a feed” and its link, a page of it, its stories, or a link to one of its posts
  or reels; all become `https://www.instagram.com/<user>/`.
* Posts are read from the profile’s public embed, without an Instagram account: the 6 latest at each refresh, with every
  photo of an album, video covers linked to Instagram, full captions with links, mentions and hashtags, co-authors, place
  and paid partnerships, the exact time, and the profile picture as the feed icon.
* Photos are shown through FreshRSS, which keeps them in its cache: browsers refuse those of Instagram’s CDN on other sites.
* Stories: an alert entry when a newer story is up, without an account; with the session cookie of an Instagram account in
  the settings, the stories themselves, their pictures kept for 30 days. The settings page says whether the session works.
* Default refresh interval for new profiles: a setting.
* Works on FreshRSS 1.28 and newer.

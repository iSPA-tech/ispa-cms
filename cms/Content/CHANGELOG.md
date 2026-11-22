# Changelog

## 2026.11.8 - 2026-11-22
- Public API: `Post::slugCategoryFor()` is part of the Content API snapshot. It was added on 2026-11-21 (a post slug's category is the post type); the snapshot and version now record it.

## 2026.11.7 - 2026-11-21
- Tags need permissions: listing and searching need `tag.viewAny`, and create, save and delete need `tag.create`, `tag.update` and `tag.delete`; before, any admin user could change tags. On update, every role gets the tag rights matching the post rights it already had, so editors keep managing tags.
- The tags screen works again: a tag is created from its name alone (it was checked against the unit-of-measure form and always refused), and opening, saving and deleting a tag address it by uuid. The tag logo picker is gone: tags never stored images, so opening or saving a tag failed.
- The content admin works on a site without the GeoPlaces module (it failed with a server error there, which every public install hit). Content no longer handles place content specially: confirming a Vietnamese text no longer queues translations of its place's other languages, and saving no longer clears the place scan cache, which also failed for a content without a target.
- "Crawl post" reads any article page: its title, summary, cover image, headings, paragraphs and images. A module can add a better reader for particular sites. Needs Core 2026.16.0 or later.
- "Crawl post" shows Save only on sites where a module or project saves crawled articles. Elsewhere the page previews the article without Save, and the save address refuses instead of answering a success that stored nothing.

## 2026.11.6 - 2026-11-21
- The Content tables (posts, contents, bookmarks) are now created by the Content module itself. A new site that does not use Content no longer gets them; sites that already have them see no change.
- The post indexes for the public lists and the admin dashboard are now part of the Content module.
- Content code that lived outside the module moved in: the admin tag routes, the public `ajax/content-view` page part, the post count jobs and the `<x-best-view-of-post>` component (same addresses, names and tag).

## 2026.11.5 - 2026-11-17
- New for modules: `Post::publicAt()` gives the posts the public can see at a given moment, and `PostInterface::searchAgentEligible()` searches the posts approved for the content answer pilot. The search is never cached.
- Content can answer one question from approved posts with checked quotes (`content.answer_posts`). It is off until a site turns it on and approves posts.

## 2026.11.4 - 2026-11-17
- Two Content events are now part of the public API: `PostPublished` (a post was published) and `PostCrawlDataSubmitted` (an admin submitted a crawled article to be saved as a post). Modules may listen to them instead of Content calling them.

## 2026.11.3 - 2026-11-15
- The admin screens no longer read or send a hub domain token: translate and text to speech use the site's own server-held connection.
- Translating a content item no longer fails with "Token is required".

## 2026.11.2 - 2026-11-15
- Text to speech no longer needs the hub secret in the server settings file: it runs whenever the site is connected to the platform hub.

## 2026.11.1 - 2026-11-13
- Content can now be installed and upgraded online from the platform hub.
- Content declares which Core versions it works with (2026.11.0 up to, not including, 2027.0.0).
- The public Content API is marked, and modules that depend on Content now state the Content versions they need.
- The post list in the admin loads correctly after an online rebuild.
- The web pages and API routes of Content now live inside the Content module.

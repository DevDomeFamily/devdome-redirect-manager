=== DevDome Redirect Manager – Redirects, Link Rotation & Geo Targeting ===
Contributors: devdome
Tags: redirect, redirects, link rotator, geotargeting, 301 redirect
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redirects with link rotation, geo and device targeting, scheduling, multiple redirect methods and per-rule statistics.

== Description ==

DevDome Redirect Manager lets you build redirect rules and point traffic wherever you need it: a single destination, a rotating list of URLs, a link already on the page, or the same path on another domain. Each rule has its own targeting, timing, schedule and statistics, and you can run as many rules side by side as you like.

Everything is configured from one screen (Tools language aside, no coding). Rules are independent: when a request comes in, the highest-priority running rule that matches handles it.

Every feature is free and unlimited: unlimited rules, geo targeting, device targeting, scheduling, rotation and per-rule statistics. There is no paid tier of this plugin.

= What you can do =

* Redirect your entire site, only selected pages/posts/categories, a list of custom URL paths, or every 404 (not-found) page.
* Choose the redirect method per rule: JavaScript, 301 (permanent), 302 (temporary), 307, 308, or HTML meta refresh.
* Send traffic to a list of destination URLs, to a link/button already present on the page, or to the same path on a different domain.
* Rotate through multiple destinations in order (first to last), randomly, or with a weighted "click distribution" you control with a slider.
* Control how often a visitor is redirected: every visit, once per visitor, or again only after a delay you set (minutes/hours/days). Recognise a returning visitor by IP, or by IP + browser/device.
* Redirect only every N-th unique visitor.
* Only visitors arriving from outside: redirect visitors who land from another website or with no referrer, not those moving between your own pages. Also available to AI agents as the outside_only ability field.
* Known bots are never redirected: crawlers, monitors and scrapers see the page as usual and only real visitors are redirected (on by default, one checkbox to turn off).
* Open the destination in the same tab or a new tab (JavaScript method), with optional instant or randomized delays, and an optional "wait for a click" step.
* Target by device: desktop, mobile, tablet.
* Target by country with an optional geo filter (include only listed countries, or redirect everyone except listed countries).
* Schedule rules: always active, or a custom schedule with a run time, specific weekdays, and specific time windows.
* Decide what happens to visitors who do not match a rule: leave them on the page, or send them to a bypass link.
* Run multiple rules with drag-to-order priority; add, duplicate, delete, start and stop each one independently.
* See per-rule statistics, export every rule to a file, and import them back.

= Redirect Setup =

**What To Redirect**

* **Entire website** - the rule runs on every public page.
* **Selected existing URLs** - search your Categories, Pages and Posts and add them with a live picker (chips with per-item hit counts; drag to set priority for the "found" mode).
* **Custom URLs** - type exact paths, one per line (for example `/blog/` or `/blog/best-headphones/`), whether the page exists or not. A trailing slash matches that page and everything under it.
* **All 404's** - the rule runs on every not-found page (handy for catching dead links).
* **Referring websites** - the rule runs only for visitors who arrive from the websites you list. A domain (reddit.com) covers that site and its subdomains, a word (reddit) covers every referring site whose address contains it. Visitors whose browser sends no referrer, and visitors coming from your own site, are never redirected by such a rule. Tick Only on selected pages to redirect them only on the categories, pages or posts you pick.

**Redirect Method** - JavaScript Redirect, 301 Permanent, 302 Temporary, 307 Temporary, 308 Permanent, or Meta Refresh. New-tab opening and client-side delays are available with the JavaScript method only; server and meta methods always open in the same tab.

**Where To Send Traffic**

* **To provided links** - enter one or more destination URLs and rotate through them.
* **To links or buttons on the page** - enter a fragment (for example `amazon.com`) and the rule redirects to the matching link/button already on the page. You can target the N-th match.
* **To same path on another domain** - keep the visitor's path and query and send it to another domain (for example `yoursite.com/post/123` becomes `otherdomain.com/post/123`).

**Link rotation** (provided links) - First to last, Random, or Weighted distribution. The weighted mode adds a Click Distribution slider (first link gets most, even split, or last link gets most) with a live preview. "Repeat List" starts again from the first URL once the list is exhausted.

**How often to redirect** - Every visit, Once per visitor, or After a delay. When it is not "every visit", you choose how a returning visitor is identified (IP address, or IP + browser/device), can redirect only every N-th unique visitor, and (for the delay option) set the gap before the same visitor is redirected again.

**Known Bots** - "Don't redirect known bots" (on by default) keeps crawlers, monitors and scrapers on the page; only real visitors are redirected, so a search engine keeps indexing the page. The check uses a built-in list of user-agent tokens; no external service is called.

= Open Link Settings =

* **Open Link In** - Same Tab or New Tab (New Tab requires the JavaScript method).
* **Same Tab Link Delay** - instant, or a random delay (seconds) within a range.
* **Redirect On Click** - wait for the visitor to click/tap anywhere before redirecting, instead of redirecting automatically.
* **After Click Delay** - after the click, wait a random time (up to 4 seconds) before redirecting.
* **New Tab Link Delay** - instant or a random delay before the new tab is armed. A new tab can only open on a real click, so the destination opens on the visitor's next click after the delay (browsers block tabs that open by themselves).

= Geo Filter Settings =

* **Test geo service** - confirm the geo service is reachable from your server before you rely on it.
* **Geo Filtering** - turn the country filter on or off (off by default).
* **Filter mode** - redirect only the listed countries, or redirect everyone except the listed countries. If the visitor's country cannot be resolved, an "except listed" (blacklist) rule does not redirect that visitor (it fails safe).
* **Site Behind Proxy / CDN** - if your site sits behind Cloudflare or another proxy, enable "Trust forwarded IP headers" so the real visitor IP is used for country lookup. "Detect automatically" inspects the current request and ticks the box for you.

See the External services section below for what the geo filter sends and where.

= Optional Settings =

* **Devices to Redirect** - redirect only the checked device types (Desktop / Mobile / Tablet); other devices follow your "Not Redirected Visitors" setting.
* **Purge Page Cache On Save** - clear cached copies of the pages a rule targets when you save/run it, so the redirect takes effect immediately. Works with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, WP-Optimize, Cache Enabler, Hummingbird and Breeze.
* **Not Redirected Visitors** - Leave On Page (do nothing), or Send To Bypass Link (send non-matching visitors to a URL you choose).
* **Schedule Mode** - Always Active, or Custom Schedule with a run time, selected weekdays, and up to three specific time windows.

= Rules, run state and statistics =

* Add, duplicate, delete and reorder rules; each rule has its own nickname and priority.
* Start a rule with "Save & Run", stop it with "Stop"; a live status shows Running/Stopped, run time and time left.
* "Save Settings" saves without changing the run state; "Return to Default" resets the current rule's settings.
* Each rule keeps its own statistics (visitors, page views, unique users, unique IPs, redirects, bypassed visitors, per-device and per-country counts, and per-source/destination breakdowns), with a time-range filter. "Reset Stats" clears the current rule's counters and rotation position.
* **Export** downloads all rules and their configuration to a JSON file (no stats, no run state). **Import** replaces every rule on the site with the rules from a file (imported rules arrive stopped).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install it from the Plugins screen) and activate it.
2. Open **Tools -> DevDome Redirect Manager** (the DevDome Tools menu).
3. Pick what to redirect, choose a redirect method and where to send traffic, then set any targeting, timing and schedule you need.
4. Click **Save & Run** to start the rule. Use **Save Settings** to save changes without changing the run state.

No account is required for the redirect features. The geo filter is optional and off by default.

== Frequently Asked Questions ==

= Does the redirect work without JavaScript? =
Yes. Choose 301, 302, 307, 308 or Meta Refresh for a redirect that does not need JavaScript. The JavaScript method is only needed for new-tab opening, "wait for a click", and client-side delays. One exception: on a page served from a full-page cache, a Referring websites or Only visitors arriving from outside rule detects the landing with a small script, so those two need JavaScript on cached pages.

= My redirect does not fire immediately after saving. Why? =
A caching plugin or server cache may still be serving a stored copy of the page. Keep "Purge Page Cache On Save" enabled so the targeted pages are cleared when you save or run the rule.

= Can I run more than one rule at the same time? =
Yes. Rules are independent and ordered by priority. For a given request, the highest-priority running rule that matches handles it.

= What does "Once per visitor" mean? =
The visitor is redirected the first time only, and then left on the page on later visits, until you reset the rule's stats. Choose "After a delay" instead if you want the redirect to become available again after a set time. A visitor is identified by IP, or by IP + browser/device.

= Are search engines and other bots redirected? =
Not while "Don't redirect known bots" is ticked, which is the default. Crawlers, uptime monitors, scrapers and headless browsers are recognised by a built-in list of user-agent tokens (plus Spamhaus DROP addresses where the shared DevDome bot data is already present on the site; this build downloads none) and simply see the page. They are not redirected, not sent to the bypass link and not counted as visitors; the rule statistics show how many were skipped. Untick it if you really want bots redirected too.

= Why is "New Tab" greyed out / reverting to "Same Tab"? =
New-tab opening needs the JavaScript method. With a 301/302/307/308 or Meta Refresh method the redirect always happens in the same tab.

= Does the geo filter send any data off my site? =
Only when you enable Geo Filtering. See the External services section for exactly what is sent and where. With Geo Filtering off (the default), the plugin makes no external requests.

= How do I move my rules to another site? =
Use Export to download a JSON file of all rules, then Import on the other site. Imported rules arrive stopped, so you can review them before starting.

= AI and Agent Support =

On WordPress 6.9 and newer, DevDome Redirect Manager registers WordPress Abilities covering the whole plugin: list rules with their full configuration, rule details and statistics, 404 paths worth redirecting (with DevDome Link Monitor), search pages, posts and categories, geo service and proxy status, export the configuration, create a rule with every option (what to redirect, method, destinations and rotation, open mode and delays, frequency, schedule, geo, devices, fallback, custom domains), update, duplicate, reorder, start, stop and delete rules, reset a rule's statistics and purge page caches. Compatible AI agents and MCP clients can discover and use these abilities when the site exposes them, for example through the official WordPress MCP Adapter. New rules are created stopped unless the agent is told to start them; updates are all or nothing; delete and reset are marked destructive and require an explicit confirm flag. Every ability runs under the same administrator capability as the plugin screens.

== External services ==

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours, so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

This plugin can talk to two DevDome services, both optional and described below. Service provider for both: DevDome. Terms of service: https://devdome.com/terms-of-service . Privacy policy: https://devdome.com/privacy-policy .

= Geo lookup (api.devdome.com/geo-resolve) =

The plugin connects to the DevDome geo-resolution service only when you use the optional **Geo Filter** feature. With Geo Filtering turned off (the default), the plugin does not contact this service.

What it is used for: turning a visitor's IP address into a two-letter country code so a rule can include or exclude countries.

What is sent and when:

* When a rule with Geo Filtering enabled handles a front-end request, the visitor's IP address is sent to `https://api.devdome.com/geo-resolve/classify` (POST, body `{"ips":[<ip>]}`) to look up a country code. Results are cached so the same IP is not looked up repeatedly.
* The **Test geo service** button requests `https://api.devdome.com/geo-resolve/health` to check availability. No visitor data is sent.
* The **Detect automatically** (proxy/CDN) button runs locally on your server and sends no data to any external service.

= Bot detection feeds (api.devdome.com/bot-protection) =

The plugin bundles the shared DevDome bot-detection library, which can download three block lists so known bots can be matched locally on your server: `https://api.devdome.com/bot-protection/list` (bot user-agent patterns), `https://api.devdome.com/bot-protection/asns` (data-center network list) and `https://api.devdome.com/bot-protection/drop` (Spamhaus DROP IP ranges).

These requests would be scheduled downloads of the lists themselves; no visitor data is ever sent to these endpoints. On this WordPress.org build the feed downloads are disabled entirely: no request is made and no download is scheduled, whether or not a DevDome account is connected.

= Optional DevDome account connection (devdome.com and api.devdome.com) =

The bundled DevDome library can link this site to a free DevDome account. This is optional and nothing is sent until you press the Connect button on the DevDome screen. Connecting opens `devdome.com` in your browser to sign in; after approval the plugin stores your public DevDome Account ID and a site token, and verifies the link against `https://api.devdome.com/plugin/account` (sending the site domain and the site token). Disconnecting sends the site domain and site token once to `https://api.devdome.com/plugin/disconnect` to unlink the site. No visitor data, content or redirect rules are sent.


== Source code ==

All of this plugin's PHP and JavaScript ships unminified and human-readable.

One file is generated: `assets/devdome-tools-tw.css`, the admin screen's utility stylesheet (`assets/admin.css` is hand-written and ships as-is). It is a Tailwind CSS v3 utility bundle built from `src/tw.css` and `tailwind.config.cjs` with:

`npx tailwindcss -c tailwind.config.cjs -i src/tw.css -o assets/devdome-tools-tw.css --minify`

Those two build inputs are not included in the distributed package. Ask for them at https://devdome.com/contact and we will send them.

== Screenshots ==

1. DevDome Redirect Manager rules: several WordPress redirect rules side by side, each with its own priority, nickname and redirect statistics.
2. Redirect setup: entire site, selected URLs, custom URLs or all 404 pages; 301, 302, 307, 308, JavaScript or meta refresh; destination list, link rotation order and redirect frequency.
3. Geo targeting: redirect only visitors from listed countries, or everyone except them.
4. Device targeting and optional settings: desktop, mobile, tablet; purge page cache on save; fallback for visitors not redirected.
5. Scheduling: run a redirect rule between dates and times in your timezone.

== Changelog ==

= 1.5.3 =
* Fixed: country targeting redirected nobody. The plugin asked the DevDome geo service through a call reserved for connected accounts and always got no country back; it now uses the open lookup, so allow and block country lists work again on every site.

= 1.5.2 =
* New "Only visitors arriving from outside" checkbox under What To Redirect (every scope except Referring websites, which already works this way): the rule redirects a visitor who lands on the page from another website or with no referrer at all, and leaves a visitor who moves between pages of this site on the page. Works on cached pages through the same small footer script as Referring websites. The create-redirect and update-redirect abilities accept outside_only and get-redirect returns it.

= 1.5.1 =
* New "Don't redirect known bots" checkbox under How Often To Redirect, on by default: crawlers, monitors and scrapers see the page as usual and are never redirected, so a search engine keeps indexing the page while only real visitors are sent on. Skipped bots are not counted as visitors and appear as Bots Skipped in the rule statistics. The create-redirect and update-redirect abilities accept skip_bots and get-redirect returns it together with bots_skipped.

= 1.5.0 =
* What To Redirect: every option now carries a one-line hint under it, so the choice is clear without opening the info icon.
* New "What To Redirect" option: Referring websites. The rule runs only for visitors who arrive from the websites you list, one per line: a domain (reddit.com) covers that site and its subdomains, a word (reddit) covers every referring site whose address contains it. Works on cached pages through a small footer script. Tick Only on selected pages to redirect them only on the categories, pages or posts you pick. The websites are entered like the other lists: type, Add, remove one by one. The create-redirect and update-redirect abilities accept what = referring_sites with the list in from and the pages in referrer_pages.
* Picking a category under URLs To Redirect now redirects every post in it and in its subcategories, and picking a product category or the shop archive redirects every product in it. Before, only the category's own archive pages were redirected. On sites with plain permalinks a picked category or page no longer matches every page of the site.
* Fixed: on sites with plain permalinks the "Selected existing URLs" picker found nothing (its search request was built with a second question mark and answered 404).
* The URLs To Redirect picker now lists every grouping of the site under Categories: blog categories and tags, WooCommerce product categories, brands and tags, custom taxonomies and the shop archive (a shop whose product base is /product-reviews/ appears there). It finds a title, a slug, a path or a full address, a hyphen no longer ends the suggestions, matches from the other tabs show up too, marked Page, Post or Category, and the Posts tab also finds single WooCommerce products and other public content. The search-content ability returns the same results.
* Updates now work when the plugin folder belongs to another system user, for example after an install from a root shell or by an AI agent. Before, the update failed with Retry update, or an uploaded zip kept the old version (shared DevDome core 1.7.2).

= 1.4.1 =
* Connect fix (shared DevDome core 1.6.6): the connect claim now waits up to 30 seconds and keeps the handshake for 20 minutes so a refresh retries it, the DevDome hub shows why a connect failed with a Try again link, and the verify file is served through a query form for hosts that answer /.well-known/ before WordPress.

= 1.4.0 =
* WordPress Abilities API support (WordPress 6.9+): fifteen abilities for AI agents and MCP clients covering every feature: list-redirects, get-redirect-details, get-redirect-stats, find-404-redirect-candidates, search-site-content, get-geo-status, export-redirects, create-redirect (full option set), update-redirect, set-redirect-state, duplicate-redirect, reorder-redirects, delete-redirect, reset-redirect-stats, purge-redirect-cache.

= 1.3.5 =
* Settings: every option now shows a one line hint under the control, with the info icon holding the full explanation, the same layout as DevDome Malware Scanner.
* DevDome Dashboard: installing another DevDome plugin from the dashboard no longer activates it, you activate it yourself from its card. Output escaping tightened.

= 1.3.4 =
* DevDome Dashboard: plugin list, descriptions, logos and versions now come from devdome.com, one-click install of DevDome plugins from WordPress.org, Docs link and Fix buttons, Activate stays on the dashboard.

= 1.3.3 =
* Updated the bundled DevDome suite core: redesigned DevDome Dashboard with cleaner cards, your account email and plan on the overview, and update buttons shown only when an update really exists.
* One button system across the suite: the same Connect button and the same Save Settings button in every DevDome plugin.

= 1.3.2 =
* Every output buffer used to build the page scripts is now opened and closed inside a single function, so it can never be left open.
* Removed two manually fired activation/deactivation hook calls from the internal version check.

= 1.3.1 =
* The suite hub no longer installs or activates any plugin: its installer is removed from this build and the DevDome screen links out to the plugin's page instead.
* Removed the crawler-specific serving, search-engine hiding, referrer targeting, referrer stripping, back-button and daily-cap code paths entirely, along with the log-cleanup routine.
* The settings screen's styles and scripts are now enqueued instead of being written into the page, so caching and optimisation plugins can handle them properly.
* Importing a settings file now accepts only known settings and cleans every value for the type it stores; switching the rule you are editing is protected like every other action.

= 1.3.0 =
* Every feature is now free and unlimited: the rule limit and the Pro locks on geo targeting, device targeting and scheduling are gone.
* Internal identifier rename to a unique plugin prefix (WordPress.org requirement). Existing rules, statistics and settings are carried over automatically by a one-time migration on self-hosted builds.
* Unified DevDome suite icons and suite hub.

Older entries: see changelog.txt in the plugin folder.

== Upgrade Notice ==

= 1.3.3 =
Suite dashboard and button refresh. Rules and statistics are unaffected.

= 1.3.2 =
Internal housekeeping release. No functional changes; rules and statistics are unaffected.

= 1.3.1 =
Housekeeping release: the suite hub no longer installs plugins for you, and unused redirect code paths were removed. Your rules and statistics are unaffected.

= 1.3.0 =
Every feature is now free and unlimited. Existing rules, statistics and settings are migrated automatically.

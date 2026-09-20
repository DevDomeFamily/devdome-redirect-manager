=== DevDome Redirect Manager – Redirects, Link Rotation & Geo Targeting ===
Contributors: devdome
Tags: redirect, redirects, link rotator, geotargeting, 301 redirect
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage redirects with link rotation, geo and device targeting, visitor checks, daily limits, UTM source matching and per-rule statistics.

== Description ==

DevDome Redirect Manager sends visitors to a destination URL, a rotating list of links, a link on the page, or the same path on another domain. Each rule has its own targeting, schedule and statistics.

Configure everything from one screen without coding. The highest-priority running rule that matches handles the request.

All features are free. Create unlimited rules with no paid tier.

= What you can do =

* Redirect your entire site, only selected pages/posts/categories, a list of custom URL paths, or every 404 (not-found) page.
* Choose the redirect method per rule: JavaScript, 301 (permanent), 302 (temporary), 307, 308, or HTML meta refresh.
* Send traffic to a list of destination URLs, to a link/button already present on the page, or to the same path on a different domain.
* Rotate through multiple destinations in order (first to last), randomly, or with a weighted "click distribution" you control with a slider.
* Control how often a visitor is redirected: every visit, once per visitor, or again only after a delay you set (minutes/hours/days). Recognise a returning visitor by IP, or by IP + browser/device.
* Redirect only every N-th unique visitor.
* Redirect only visitors arriving from outside your site, including visits with no referrer.
* Match referring websites, with optional UTM source matching for tagged links from apps and other sources that send no referrer.
* Skip redirects for recognised bots and exclude them from visitor counts (on by default).
* Skip redirects for browsers reporting older desktop versions with the optional Outdated Browsers setting.
* Skip redirects for listed IP addresses and ranges, browser strings (User-Agent) and logged-in user roles, such as your own address or Administrator.
* Require the same IP address and browser to continue a JavaScript redirect to provided links or the same path on another domain.
* Set a daily redirect limit per rule, fixed or randomly chosen within a range.
* Open the destination in the same tab or a new tab (JavaScript method), with optional instant or randomized delays, and an optional "wait for a click" step.
* Target by device: desktop, mobile, tablet.
* Target by country with an optional geo filter (include only listed countries, or redirect everyone except listed countries).
* Schedule rules: always active, or a custom schedule with a run time, specific weekdays, and specific time windows.
* Decide what happens to visitors who do not match a rule: leave them on the page, or send them to a bypass link.
* Run multiple rules with drag-to-order priority; add, duplicate, delete, start and stop each one independently.
* See per-rule statistics and a Bots Skipped dashboard total. Export every rule to a file and import them back.

Visitor Check, Outdated Browsers, Daily Redirect Limit and UTM source matching are off by default. All four run on your site without an external service. The new dashboard count and developer hooks also run locally.

= Redirect Setup =

**What To Redirect**

* **Entire website** - the rule runs on every public page.
* **Selected existing URLs** - search your Categories, Pages and Posts and add them with a live picker (chips with per-item hit counts; drag to set priority for the "found" mode).
* **Custom URLs** - type exact paths, one per line (for example `/blog/` or `/blog/best-headphones/`), whether the page exists or not. A trailing slash matches that page and everything under it.
* **All 404's** - the rule runs on every not-found page (handy for catching dead links).
* **Referring websites** - match the websites you list against the browser's referrer. A domain (reddit.com) covers that site and its subdomains. A word (reddit) matches referring addresses containing it. Without UTM source matching, visits with no referrer or an internal referrer do not match. Tick "Only on selected pages" to restrict the rule to chosen categories, pages or posts.

**UTM Source** - "Also match the link's UTM source" lets a Referring websites rule also match tagged links. For a listed source of reddit.com, both `?utm_source=reddit.com` and `?utm_source=reddit` match, even without a referrer. Anyone can set this label. It does not verify where a visitor came from.

**Redirect Method** - JavaScript Redirect, 301 Permanent, 302 Temporary, 307 Temporary, 308 Permanent, or Meta Refresh. New-tab opening and client-side delays are available with the JavaScript method only; server and meta methods always open in the same tab.

**Where To Send Traffic**

* **To provided links** - enter one or more destination URLs and rotate through them.
* **To links or buttons on the page** - enter a fragment (for example `amazon.com`) and the rule redirects to the matching link/button already on the page. You can target the N-th match.
* **To same path on another domain** - keep the visitor's path and query and send it to another domain (for example `yoursite.com/post/123` becomes `otherdomain.com/post/123`).

**Link rotation** (provided links) - First to last, Random, or Weighted distribution. The weighted mode adds a Click Distribution slider (first link gets most, even split, or last link gets most) with a live preview. "Repeat List" starts again from the first URL once the list is exhausted.

**How often to redirect** - Every visit, Once per visitor, or After a delay. When it is not "every visit", you choose how a returning visitor is identified (IP address, or IP + browser/device), can redirect only every N-th unique visitor, and (for the delay option) set the gap before the same visitor is redirected again.

**Known Bots** - "Don't redirect known bots" is on by default. Requests matching the local bot checks are not redirected, sent to the bypass link or counted as visitors. The checks use built-in browser identifiers and any shared DevDome bot data already stored locally. This build downloads no bot feeds. Unrecognised bots can still be redirected.

**Outdated Browsers** - "Don't redirect outdated browsers" skips reported Chrome/Chromium versions below 125, except 109, and Firefox versions below 125, except 115. Edge is checked through its Chrome version identifier. Browser identifiers containing Mobile, Android, iPhone or iPad are excluded from this check. Older versions can belong to real visitors.

**Visitor Check** - "Require the same IP address to continue" checks that a single-use pass returns from the same IP address and browser. It works only with JavaScript redirects to provided links or the same path on another domain. It does not apply to links found on the page. Privacy: the pass record stays in your database with a keyed hash of the visitor's IP address and browser, never those raw values. It is deleted when used and expires within minutes, with extra time for configured delays.

**Daily Redirect Limit** - "Limit redirects per day" sets a cap for each rule. Enter two positive whole numbers: the daily cap is chosen randomly between them. Use the same number twice for a fixed cap. A redirect is counted when the rule makes it, not when someone reaches the destination. With Visitor Check on, it is counted when the visitor's pass is accepted, so a page that is only loaded costs nothing.

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
* **Excluded IP Addresses** - one IPv4 or IPv6 address or CIDR range per line, such as your own address. It cannot stop bots that change address on every visit.
* **Excluded Browser Strings** - skips visitors whose browser string (User-Agent) contains any listed text, ignoring upper and lower case. Entries under 5 characters are not saved.
* **Excluded User Roles** - skips logged-in users with a ticked role. A page cache may still serve a stored redirect.

The three exclusions are per rule and off by default. Matching visitors see the page as usual and are counted with the skipped bots.

= Rules, run state and statistics =

* Add, duplicate, delete and reorder rules; each rule has its own nickname and priority.
* Start a rule with "Save & Run", stop it with "Stop"; a live status shows Running/Stopped, run time and time left.
* "Save Settings" saves without changing the run state; "Return to Default" resets the current rule's settings.
* Each rule keeps its own statistics (visitors, page views, unique users, unique IPs, redirects, bypassed visitors, per-device and per-country counts, and per-source/destination breakdowns), with a time-range filter. "Reset Stats" clears the current rule's statistics and rotation position, but not today's daily-limit count.
* **Bots Skipped** shows skipped bot matches, outdated-browser matches, IP address, browser string and user role exclusions, and expired or mismatched Visitor Check passes. The DevDome dashboard totals these across rules. This is not a count of confirmed bots.
* **Export** downloads all rules and their configuration to a JSON file (no stats, no run state). **Import** replaces every rule on the site with the rules from a file (imported rules arrive stopped).

= Developer hooks =

Use `devdredi_redirect_target` to filter destinations for provided links and same-path redirects. Use `devdredi_pass_bind_address` to filter the address a Visitor Check pass is bound to, for example a network range instead of the exact address. Use `devdredi_redirected` to respond to redirect events. Neither hook confirms arrival at the destination.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install it from the Plugins screen) and activate it.
2. Open **Tools -> DevDome Redirect Manager** (the DevDome Tools menu).
3. Pick what to redirect, choose a redirect method and where to send traffic, then set any targeting, timing and schedule you need.
4. Click **Save & Run** to start the rule. Use **Save Settings** to save changes without changing the run state.

No account is required for the redirect features. The geo filter is optional and off by default.

== Frequently Asked Questions ==

= Does the redirect work without JavaScript? =
Yes. Choose 301, 302, 307, 308 or Meta Refresh. JavaScript is needed for Visitor Check, new-tab opening, "wait for a click" and client-side delays. On fully cached pages, Referring websites and Only visitors arriving from outside rules also use JavaScript to detect the landing.

= My redirect does not fire immediately after saving. Why? =
A caching plugin or server cache may still be serving a stored copy of the page. Keep "Purge Page Cache On Save" enabled so the targeted pages are cleared when you save or run the rule.

= Can I run more than one rule at the same time? =
Yes. Rules are independent and ordered by priority. For a given request, the highest-priority running rule that matches handles it.

= What does "Once per visitor" mean? =
The visitor is redirected the first time only, and then left on the page on later visits, until you reset the rule's stats. Choose "After a delay" instead if you want the redirect to become available again after a set time. A visitor is identified by IP, or by IP + browser/device.

= Does the plugin recognise every bot? =
No. "Don't redirect known bots" skips requests matching its local checks. Bots using unrecognised browser identifiers can still be redirected. Visitor Check compares the IP address and browser between two requests. It does not prove that a visitor is human.

= Bots are inflating my affiliate or ad clicks. What should I switch on? =
Keep "Don't redirect known bots" enabled. For supported JavaScript redirects, try Visitor Check. Consider Outdated Browsers if excluding older browsers suits your audience. These settings can reduce some automated redirects. They cannot prevent direct visits to the destination or guarantee valid clicks.

= Will Visitor Check slow real visitors down? =
It adds one request to your site before opening the destination, so some extra loading time is possible. There is no CAPTCHA. A visitor using the same IP address and browser continues automatically if the pass is still valid.

= What happens if a visitor's network changes? =
If the IP address or browser identifier changes between loading the page and returning the pass, the redirect is refused. The visitor sees an expired-link message asking them to go back and open the page again. This can happen to real visitors switching networks.

= What happens when the daily limit is reached? =
Further visitors handled by that rule stay on the page or go to its bypass link. A new daily allowance becomes available at midnight in the site's timezone, subject to the rule's schedule. Resetting statistics does not reset today's allowance.

= Why is "New Tab" greyed out / reverting to "Same Tab"? =
New-tab opening needs the JavaScript method. With a 301/302/307/308 or Meta Refresh method the redirect always happens in the same tab.

= Does the geo filter send any data off my site? =
Country lookups send visitor IP addresses to the geo service when Geo Filtering is enabled. "Test geo service" also contacts that service. The plugin catalog and optional account connection have separate external requests. See External services for details. The four new redirect settings run locally without an external service.

= How do I stop being redirected on my own site? =
Edit each rule that could redirect you. Under Optional Settings, tick "Don't redirect users with these roles", tick your role, such as Administrator or Editor, then save. This applies only while you are logged in. To skip redirects while logged out too, tick "Don't redirect these IP addresses or ranges" and add your public IPv4 or IPv6 address, one address or CIDR range per line. Update the entry if your public address changes; everyone sharing a listed address is also skipped. Matching visits stay on the page, never go to the bypass link and are counted with the skipped bots instead of visitors. Cached pages may still contain a redirect, so clear the page cache after saving if needed.

= How do I move my rules to another site? =
Use Export to download a JSON file of all rules, then Import on the other site. Imported rules arrive stopped, so you can review them before starting.

= AI and Agent Support =

On WordPress 6.9+, compatible AI agents and MCP clients can use WordPress Abilities when your site exposes them, for example through the WordPress MCP Adapter. Abilities cover rule configuration, statistics, content search, 404 suggestions with DevDome Link Monitor, geo status, export and rule management. Administrator access is required.

New fields are `skip_ips`, `skip_user_agents`, `skip_roles`, `visitor_check`, `skip_old_browsers`, `daily_limit_enabled`, `daily_limit_min`, `daily_limit_max` and `referrer_utm_scan`. A list entry that is not valid is refused and nothing is changed; a list with entries switches its exclusion on, an empty list switches it off. Statistics include `bots_skipped`. Agents must obtain user agreement and send `confirm: true` when turning an enabled Visitor Check or Outdated Browsers setting off. Turning Known Bots off does not currently require that flag.

New rules start stopped unless requested otherwise. Updates are all or nothing. Enabling geo targeting, deleting rules and resetting statistics also require confirmation.

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

The bundled DevDome library can link this site to a free DevDome account. This is optional and nothing is sent until you press the Connect button on the DevDome screen. Connecting opens `devdome.com` in your browser to sign in; after approval the plugin stores your public DevDome Account ID and a site token, and verifies the link against `https://api.devdome.com/plugin/account` (sending the site domain and the site token). When you connect from the DevDome Tools dashboard, whose Connect card states this before you press the button, those account checks also send the slug and version of each active DevDome plugin plus the DevDome library, WordPress and PHP versions, so your account can show which of your sites run which DevDome plugins. Sites connected earlier, or from a button without that text, do not send the list. Disconnecting sends the site domain and site token once to `https://api.devdome.com/plugin/disconnect` to unlink the site. Disconnecting also stops the plugin list. No visitor data, content or redirect rules are sent.

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

= 1.5.5 =
* New per-rule exclusions under Optional Settings, each with its own checkbox and off by default: IP addresses and CIDR ranges (IPv4 and IPv6), browser strings (User-Agent), and logged-in WordPress user roles.
* Excluded visitors see the page as usual, are not sent to the bypass link and are counted with the skipped bots instead of visitors.
* Browser string exclusions match any listed text, ignoring upper and lower case. IP and browser string exclusions depend on the visitor continuing to match; role exclusions apply only while logged in, and cached pages may still be served.
* The rule settings are also available to AI agents as `skip_ips`, `skip_user_agents` and `skip_roles`.
* For developers: new `devdredi_pass_bind_address` filter for the address a Visitor Check pass is bound to.
* Bundled DevDome library 1.7.6: the optional Connect card now says exactly what a connected site shares, including the list of active DevDome plugins. Sites already connected send nothing new. See External services.

= 1.5.4 =
* New "Require the same IP address to continue" checkbox (Visitor Check, off by default): a JavaScript redirect to a provided or transit destination continues only from the IP address and browser that opened the page. The destination no longer appears in the page; a single-use pass does, checked on your own site. A pass that comes back from another address is not redirected and is counted with the skipped bots.
* New "Don't redirect outdated browsers" checkbox (off by default): desktop Chrome below version 125 (Edge is checked through its Chrome version) and Firefox below version 125, except versions 109 and 115, see the page as usual and are counted with the skipped bots.
* New "Daily Redirect Limit" (off by default): the rule stops redirecting for the day once the limit is reached and starts again at midnight, site time. The limit is picked each day between two numbers; the same number twice is a fixed limit. Visitors over the limit stay on the page or go to the bypass link.
* New "Also match the link's UTM source" checkbox for Referring websites (off by default): the rule also fires for a tagged link such as ?utm_source=reddit.com, for sources that send no referrer.
* The DevDome dashboard tile and digest now also show Bots Skipped across all rules.
* All of these are also available to AI agents as the `visitor_check`, `skip_old_browsers`, `daily_limit_enabled`, `daily_limit_min`, `daily_limit_max` and `referrer_utm_scan` rule fields. Switching `visitor_check` or `skip_old_browsers` off needs `confirm: true`.
* For developers: new `devdredi_redirect_target` filter and `devdredi_redirected` action.
* Reworded the Known Bots texts: the switch keeps automated traffic out of your redirects and statistics.

= 1.5.3 =
* Fixed: country targeting redirected nobody. The plugin asked the DevDome geo service through a call reserved for connected accounts and always got no country back; it now uses the open lookup, so allow and block country lists work again on every site.

= 1.5.2 =
* New "Only visitors arriving from outside" checkbox under What To Redirect (every scope except Referring websites, which already works this way): the rule redirects a visitor who lands on the page from another website or with no referrer at all, and leaves a visitor who moves between pages of this site on the page. Works on cached pages through the same small footer script as Referring websites. The create-redirect and update-redirect abilities accept outside_only and get-redirect returns it.

= 1.5.1 =
* New "Don't redirect known bots" checkbox under How Often To Redirect, on by default: crawlers, monitors and scrapers see the page as usual and are never redirected, so automated traffic stays out of your redirects and statistics. Skipped bots are not counted as visitors and appear as Bots Skipped in the rule statistics. The create-redirect and update-redirect abilities accept skip_bots and get-redirect returns it together with bots_skipped.

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

=== DevDome Redirect Manager: Link Rotator, Geo Redirect & 302 Redirect ===
Contributors: devdome
Tags: url rotator, link rotator, geo redirect, geotargeting, device redirect
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Set up 301 and 302 redirects, URL rotation, geo redirect and device redirect rules, plus domain forwarding and per-rule statistics.

== Description ==

DevDome Redirect Manager handles URL redirect rules for your entire site, selected content, custom paths or 404 pages. Use a 302 redirect for temporary forwarding or a 301 redirect for permanent redirection, with separate schedules and statistics for each rule. Configure everything from one screen without coding; all features are free, with unlimited rules and no paid tier.

Use the link rotator to send visitors through a list of destination URLs in order, randomly or with weighted distribution. The URL rotator can split traffic between destinations for basic split testing. For domain forwarding, send visitors to the same path on another domain while preserving the query string.

Set up a geo redirect with optional geotargeting to redirect by country using IP lookup. Include or exclude listed countries for each country redirect rule. Choose Desktop, Mobile or Tablet for a device redirect, or select Mobile for a mobile redirect. Country filtering is off by default.

= Redirect Setup: choose what to redirect =

Use this redirector for a page redirect, post redirect or category redirect, or select your entire website for a site redirect. The highest-priority running rule that matches handles the request.

Under **What To Redirect**, choose:

* **Entire website** - runs on every public page for a website redirect.
* **Selected existing URLs** - search Categories, Pages and Posts with a live picker. Selected items appear as chips with per-item hit counts; drag them to set priority for the "found" mode.
* **Custom URLs** - enter exact paths, one per line, such as `/blog/` or `/blog/best-headphones/`, whether the page exists or not. A trailing slash matches that page and everything under it.
* **All 404's** - runs on every not-found page to catch dead links. For a 404 to homepage rule, set your homepage as the destination.
* **Referring websites** - match listed websites against the browser's referrer. A domain such as reddit.com covers that site and its subdomains; a word such as reddit matches referring addresses containing it. Without UTM source matching, visits with no referrer or an internal referrer do not match. "Only on selected pages" restricts the rule to chosen categories, pages or posts.

For a homepage redirect, target the homepage through Selected existing URLs. For short links, choose a custom path and its destination: a short URL or vanity URL uses a path you supply, without generated slugs.

= Referring websites and UTM Source =

"Only visitors arriving from outside" redirects external arrivals, including visits with no referrer, and leaves visitors moving between your own pages alone.

**UTM Source** - "Also match the link's UTM source" lets a Referring websites rule match tagged links from apps and sources without a referrer: for reddit.com, both `?utm_source=reddit.com` and `?utm_source=reddit` match. Anyone can set this label; it does not verify origin.

= Redirect Method and URL forwarding =

Choose **JavaScript Redirect**, **301 Permanent**, **302 Temporary**, **307 Temporary**, **308 Permanent** or **Meta Refresh** per rule: a 301 redirect for permanent redirection, a 302 redirect for temporary URL forwarding. New-tab opening and client-side delays need JavaScript; server and meta methods open in the same tab.

Under **Where To Send Traffic**, choose:

* **To provided links** - enter one or more destination URLs.
* **To links or buttons on the page** - enter a fragment such as `amazon.com` to find a matching link/button already on the page. You can target the N-th match.
* **To same path on another domain** - preserve the visitor's path and query for a domain redirect. For example, `yoursite.com/post/123` becomes `otherdomain.com/post/123`.

= Link rotation and traffic distribution =

The URL rotator supports **First to last**, **Random** and **Weighted distribution** for provided links. The **Click Distribution** slider favours the first link, splits evenly or favours the last, with a live preview; **Repeat List** restarts from the first URL.

For basic AB testing or split testing, the rotator splits traffic between links; it does not measure conversions or pick a winner.

= How often to redirect =

Choose **Every visit**, **Once per visitor** or **After a delay**. The latter two recognise returning visitors by IP address or IP + browser/device and can redirect only every N-th unique visitor; After a delay sets the gap in minutes, hours or days.

**Daily Redirect Limit** - "Limit redirects per day" sets a per-rule cap chosen each day between two whole numbers; the same number twice is a fixed cap. A redirect counts when the rule makes it, not on arrival; with Visitor Check it counts when the pass is accepted, so loading a page costs nothing.

= Known Bots, Outdated Browsers and Visitor Check =

**Known Bots** - "Don't redirect known bots" is on by default. Matching requests are not redirected, sent to the bypass link or counted as visitors. Local checks use built-in browser identifiers and any shared DevDome bot data already stored locally; this build downloads no bot feeds. Unrecognised bots can still be redirected.

**Outdated Browsers** - "Don't redirect outdated browsers" skips reported Chrome/Chromium versions below 125, except 109, and Firefox below 125, except 115. Edge is checked through its Chrome version. Browser identifiers containing Mobile, Android, iPhone or iPad are not checked. Older versions can belong to real visitors.

**Visitor Check** - "Require the same IP address to continue" checks that a single-use pass returns from the same IP address and browser. It applies only to JavaScript redirects to provided links or the same path on another domain, not links found on the page; off by default. The pass stays in your database as a keyed hash of IP address and browser, never the raw values, is deleted when used and expires within minutes plus any configured delay.


= Open Link Settings: automatic redirect or click =

Choose an automatic redirect or wait for a visitor's click. For an auto redirect with a delay, use the JavaScript method.

* **Open Link In** - Same Tab or New Tab. New Tab requires JavaScript.
* **Same Tab Link Delay** - instant or a random delay in seconds within a range.
* **Redirect On Click** - wait for a click/tap anywhere before redirecting.
* **After Click Delay** - wait a random time up to 4 seconds after the click.
* **New Tab Link Delay** - instant or a random delay before the new tab is armed; it opens on the visitor's next real click, because browsers block tabs that open by themselves.

= Geo Filter Settings: country redirect and geolocation =

Use geotargeting for a country redirect based on IP geolocation. The GeoIP lookup supplies a country code; a geo IP redirect can include or exclude listed countries.

* **Test geo service** - check that the service is reachable from your server.
* **Geo Filtering** - enable or disable the country filter; off by default.
* **Filter mode** - redirect only listed countries or everyone except them. An unresolved country is not redirected by an "except listed" rule.
* **Site Behind Proxy / CDN** - behind Cloudflare or another proxy, enable "Trust forwarded IP headers" to use the real visitor IP. The forwarded header counts only when the connection comes from a Cloudflare or private proxy address. "Detect automatically" ticks the box for you.

See External services for what a geo redirect sends and where.

= Optional Settings: devices, cache and schedules =

* **Devices to Redirect** - select Desktop, Mobile or Tablet. For a mobile redirect, check Mobile; other devices follow **Not Redirected Visitors**.
* **Purge Page Cache On Save** - clear cached copies of targeted pages when saving/running a rule so it takes effect immediately. Supports WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, WP-Optimize, Cache Enabler, Hummingbird and Breeze.
* **Not Redirected Visitors** - Leave On Page or Send To Bypass Link, using a URL you choose.
* **Schedule Mode** - Always Active or Custom Schedule, with a run time, selected weekdays and up to three specific time windows.

= Optional Settings: visitor exclusions =

Exclude your own address, selected browsers or logged-in roles such as Administrator. Per rule, off by default; matching visitors see the page as usual and count as skipped bots.

* **Excluded IP Addresses** - one IPv4 or IPv6 address or CIDR range per line.
* **Excluded Browser Strings** - skip User-Agent strings containing listed text, ignoring case. Entries under 5 characters are not saved.
* **Excluded User Roles** - skip logged-in users with a ticked role.

= Optional Settings: hide from search and DevDome Analytics =

* **Noindex Header** - every address the running rule targets answers X-Robots-Tag: noindex, nofollow, to crawlers and visitors alike, before anyone is redirected.
* **Robots.txt Disallow** - lists the rule's Custom URLs or Selected existing URLs in the virtual robots.txt. Stops crawling, not indexing; no file is written.
* **Report To DevDome Analytics** - reports each redirect to your DevDome Analytics dashboard; see External services.

Per rule, off by default.

= Rules, run state and link tracking =

Add, duplicate, delete, start and stop rules independently; each has a nickname and priority, and you drag rules to reorder them.

* **Save & Run** starts a rule; **Stop** stops it. Live status shows Running/Stopped, run time and time left.
* **Save Settings** saves without changing run state. **Return to Default** resets the current rule.
* **Reset Stats** clears the current rule's statistics and rotation position, not today's daily-limit count.
* **Export** downloads every rule as JSON, without statistics or run state. **Import** replaces every rule on the site; imported rules arrive stopped.

Each rule records visitors, page views, unique users, unique IPs, redirects, bypassed visitors, device and country counts and source/destination breakdowns, with a time-range filter; this link tracking covers rule activity, not arrival at a destination.

**Bots Skipped** counts bot and outdated-browser matches, the three exclusions and refused Visitor Check passes, totalled across rules on the DevDome dashboard. It is not a count of confirmed bots.

= Developer hooks =

* `devdredi_redirect_target` filters destinations for provided links and same-path redirects.
* `devdredi_pass_bind_address` filters the address a Visitor Check pass is bound to, for example a network range.
* `devdredi_redirected` fires on redirect events. Neither confirms arrival at the destination.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install it from the Plugins screen) and activate it.
2. Open **Tools -> DevDome Redirect Manager** (the DevDome Tools menu).
3. Pick what to redirect, choose a redirect method and where to send traffic, then set any targeting, timing and schedule you need.
4. Click **Save & Run** to start the rule. Use **Save Settings** to save changes without changing the run state.

No account is required for the redirect features. The geo filter is optional and off by default.

== Frequently Asked Questions ==

= Does the redirect work without JavaScript, and why is "New Tab" greyed out or reverting to "Same Tab"? =

Choose 301, 302, 307, 308 or Meta Refresh to redirect without JavaScript. These methods always open in the same tab, so New Tab is unavailable.

JavaScript is required for Visitor Check, new-tab opening, "wait for a click" and client-side delays. On fully cached pages, Referring websites and Only visitors arriving from outside rules also use JavaScript to detect the landing.

= My redirect does not fire immediately after saving. Why? =

A caching plugin or server cache may still serve a stored copy of the page. Keep "Purge Page Cache On Save" enabled so targeted pages are cleared when you save or run the rule.

= Can I run more than one rule at the same time? =

Yes. Rules are independent and ordered by priority. For each request, the highest-priority running rule that matches handles it.

= What does "Once per visitor" mean? =

The visitor is redirected only the first time, then left on the page on later visits until you reset the rule's statistics. Choose "After a delay" to make the redirect available again after a set time. Visitors are identified by IP or IP + browser/device.

= Does the plugin recognise every bot, and what should I switch on if bots inflate affiliate or ad clicks? =

No. "Don't redirect known bots" skips requests matching local checks, but bots with unrecognised browser identifiers can still be redirected. Visitor Check compares the IP address and browser between two requests; it does not prove that a visitor is human.

Keep "Don't redirect known bots" enabled. For supported JavaScript redirects, try Visitor Check. Consider Outdated Browsers if excluding older browsers suits your audience. These settings can reduce some automated redirects, but cannot prevent direct visits to the destination or guarantee valid clicks.

= Will Visitor Check slow real visitors down? =

It adds one request to your site before opening the destination, so some extra loading time is possible. There is no CAPTCHA. A visitor using the same IP address and browser continues automatically if the pass is still valid.

= What happens if a visitor's network changes? =

If the IP address or browser identifier changes between loading the page and returning the pass, the redirect is refused. The visitor sees an expired-link message asking them to go back and open the page again. This can happen to real visitors switching networks.

= What happens when the daily limit is reached? =

Further visitors handled by that rule stay on the page or go to its bypass link. A new daily allowance becomes available at midnight in the site's timezone, subject to the rule's schedule. Resetting statistics does not reset today's allowance.

= Does the geo filter send any data off my site? =

Country lookups send visitor IP addresses to the geo service when Geo Filtering is enabled. "Test geo service" also contacts that service. The plugin catalog and optional account connection have separate external requests. See External services for details.

Visitor Check, Outdated Browsers, Daily Redirect Limit, UTM source matching and Hide From Search run locally. Report To DevDome Analytics sends data only when ticked on a rule.

= How do I stop being redirected on my own site? =

Edit each rule that could redirect you. Under Optional Settings, tick "Don't redirect users with these roles", tick your role, such as Administrator or Editor, then save. This applies only while logged in.

To skip redirects while logged out too, tick "Don't redirect these IP addresses or ranges" and add your public IPv4 or IPv6 address, one address or CIDR range per line. Update the entry if your public address changes. Everyone sharing a listed address is also skipped.

Matching visits stay on the page, never go to the bypass link and are counted with skipped bots instead of visitors. Cached pages may still contain a redirect, so clear the page cache after saving if needed.

= How do I move my rules to another site? =

Use Export to download a JSON file of all rules, then Import on the other site. Imported rules arrive stopped, so you can review them before starting.

= How do I set up 301 redirects when I change domain, change URL or change permalink? =

For the redirect part of a site migration, choose "To same path on another domain" to use domain forwarding while preserving paths and queries. Configure 301 redirects for a permanent move.

After you change URL paths or change permalink settings in WordPress, enter the old paths under Custom URLs and provide their new destinations. This sets up a link redirect from an old address; it does not change permalink settings or move your site's files and database.

== AI and Agent Support ==

On WordPress 6.9+, compatible AI agents and MCP clients can use WordPress Abilities when your site exposes them, for example through the WordPress MCP Adapter. Abilities cover rule configuration, statistics, content search, 404 suggestions with DevDome Link Monitor, geo status, export and rule management. Administrator access is required.

New fields are `skip_ips`, `skip_user_agents`, `skip_roles`, `visitor_check`, `skip_old_browsers`, `daily_limit_enabled`, `daily_limit_min`, `daily_limit_max`, `referrer_utm_scan`, `search_noindex`, `search_disallow` and `analytics_report` (needs confirmation). A list entry that is not valid is refused and nothing is changed; a list with entries switches its exclusion on, an empty list switches it off. Statistics include `bots_skipped`. Agents must obtain user agreement and send `confirm: true` when turning an enabled Visitor Check or Outdated Browsers setting off. Turning Known Bots off does not currently require that flag.

New rules start stopped unless requested otherwise. Updates are all or nothing. Enabling geo targeting, deleting rules and resetting statistics also require confirmation.

== External services ==

**Plugin catalog (`devdome.com`).** The DevDome Dashboard in wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours. Only the bundled core version is sent; no site or visitor data.

The plugin can also talk to the DevDome services below, all optional. Service provider for every service here: DevDome. Terms of service: https://devdome.com/terms-of-service . Privacy policy: https://devdome.com/privacy-policy .

= Geo lookup (api.devdome.com/geo-resolve) =

Used only by the optional **Geo Filter** (off by default) to turn a visitor's IP address into a country code.

* When a rule with Geo Filtering enabled handles a front-end request, the visitor's IP address is sent to `https://api.devdome.com/geo-resolve/classify` (POST, body `{"ips":[<ip>]}`). Results are cached.
* **Test geo service** requests `https://api.devdome.com/geo-resolve/health`; no visitor data is sent. **Detect automatically** runs locally and sends nothing.

= Bot detection feeds (api.devdome.com/bot-protection) =

The bundled DevDome bot-detection library can download three block lists for local matching: `https://api.devdome.com/bot-protection/list` (bot user-agent patterns), `.../asns` (data-center networks) and `.../drop` (Spamhaus DROP ranges). No visitor data is sent. On this WordPress.org build the downloads are disabled entirely: no request is made or scheduled, connected or not.

= Optional DevDome account connection (devdome.com and api.devdome.com) =

Optional: nothing is sent until you press Connect on the DevDome screen. Connecting opens `devdome.com` to sign in; the plugin then stores your public DevDome Account ID and a site token and verifies the link against `https://api.devdome.com/plugin/account` (site domain and site token). When you connect from the DevDome Tools dashboard, whose Connect card says so first, those checks also send the slug and version of each active DevDome plugin plus the library, WordPress and PHP versions, so your account can show which sites run which plugins; sites connected earlier or from a button without that text send no list. Disconnecting sends the site domain and token once to `https://api.devdome.com/plugin/disconnect` and stops the list. The connection itself sends no visitor data, content or redirect rules; visitor data leaves the site only through the click reports below, for a rule where you tick Report To DevDome Analytics.

= DevDome Analytics click reports (analytics.devdome.com) =

Off by default, per rule: nothing is sent until you tick **Report To DevDome Analytics** on a rule, and nothing while the account is not connected. Clearing the box or disconnecting stops it at once; a report queued in the same request is dropped if consent is gone by then.

* **Redirect events.** Each redirect the rule makes is POSTed by your server as one `redirect` event to `https://analytics.devdome.com/api/event` (JSON): site id (domain), DevDome Account ID, source page URL and path, destination URL, referrer (or the previous DevDome site in a chain), visitor IP address and browser string with the browser, operating system and device type derived from it, country when behind Cloudflare, an inbound ?d= token from another of your sites, a product code when the path carries one, the DevDome Analytics visitor and session ids when its tracker set them, and your site token. The IP address follows the plugin's proxy rules.
* **Hop token.** `https://analytics.devdome.com/api/plugin/hop-config` (GET, site id and site token) answers an opaque token for this site and the other sites of your account, cached six hours (ten minutes after a failure). A redirect to one of those sites gets `?d=<token>` so it credits this site without naming it. Dropped on disconnect, when the box is cleared, on Return to Default and on deactivation.

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

= 1.5.7 =

* Visitor statistics and the once-per-visitor redirect count now identify a visitor by the trusted client address (behind Cloudflare or a trusted proxy) instead of the raw connection address, and the agent rule details mask secret values in the selected referrer pages like they do for sources and destinations.
* New per-rule Hide From Search controls under Optional Settings, both off by default: a Noindex Header sent on every address the rule targets before the plugin decides who is redirected, and a Robots.txt Disallow block for the rule's Custom URLs or Selected existing URLs in the virtual robots.txt. Also available to AI agents as `search_noindex` and `search_disallow`.
* New per-rule Report To DevDome Analytics switch, off by default: each redirect is reported to your DevDome Analytics dashboard and a destination that is another site of your account gets an opaque ?d= token. Needs a connected DevDome account; what is sent is listed under External services. Agents: `analytics_report`, confirmation required to turn it on.
* Trust forwarded IP headers now believes a forwarded header only when the connection comes from a Cloudflare address or a private or loopback reverse proxy; a direct visitor can no longer hand the plugin another address.
* Saving a rule is all or nothing: every row is copied before the first write and put back when a write fails, so visitors never meet a half-changed running rule. Return to Default acts only on the rule the screen shows.
* A database query that fails during an action (saving, importing, rule actions, agent abilities) now ends in an error instead of a success message, and no further write follows it. Reset Stats reports a rotation position it could not clear.
* Import checks every value against the same choices and ranges the settings screen enforces and refuses the file otherwise. Agent exports mask credentials and secret-looking values in destination URLs and email addresses; the wp-admin Export file is unchanged. The cache purge ability reports how many caching plugins received the purge.
* Shared DevDome library 1.7.11: on a subdirectory multisite, a site mapped to its own domain is its own site and no longer shares the network's connection.
* Shared DevDome library 1.7.12: a Cloudflare visitor address header is trusted only when the connection itself comes from a Cloudflare address, so a forged header from anywhere else is ignored.

= 1.5.6 =

* Shared DevDome library 1.7.10: the one-time Report a bug hint is recorded through a nonce-checked request instead of on a page view; the DevDome dashboard lists only real problems and prints its icons through the WordPress escaping functions.
* The redirect scripts and the rule list are printed through the WordPress escaping functions, with no escaping exceptions left (WordPress.org review rule). The scripts themselves are unchanged.
* Listing text updated: title, short description, tags and introduction.

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

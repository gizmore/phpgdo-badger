# Badger

Badger is a generic, community-verifiable achievement module. Issuers define
badges, users earn them through an explicit verification process, and Badger
keeps a small auditable record of the decisions. Its initial RPG-style rewards
are cosmetic: an achievement can show a new item, image, XP or stats without
changing unrelated game mechanics.

Domain services, policy checks, render helpers and AI provider implementations
live in `Service/` under `GDO\Badger\Service`. The module root keeps GDOs,
GDTs and `Module_Badger`; HTTP/CLI methods remain in `Method/`.

Deployment uses a dedicated `phpgdo_badger` database and a Badger-specific
configuration. For now, select an instance manually by copying its protected
configuration variant to `protected/config.php`; this selection applies to
the shared PHPGDO installation and all virtual hosts using it. The Badger
installer checks the selected database, file directory and database principal.
Badger uses its own file storage, temporary/cache directory and HTTPS-only
cookie name/domain. Its setup must never run the shared web install wizard,
which otherwise selects or rewrites the generic configuration.
Badger disables full POST request logging, public stack traces and automatic
error-report emails so private claim text, consent tokens and evidence are not
copied into the shared logs or sent to an unrelated mailbox.
Per-module temporary paths also resolve inside `temp_badger/`; a Badger
install reuses an existing shared Country sprite instead of rebuilding it.
The CLI bootstrap `GDO/Badger/install/bootstrap.php --preflight` checks the
isolated connection and dependencies without installing, including when the
database already contains data; `--install-empty`
requires an empty `phpgdo_badger` database and refuses to erase or migrate an
existing database. A failed partial installation requires inspection, not an
automatic reset. `--resume-partial` is limited to the known initial state with
only the system account and no badges; it installs missing modules and then
performs the Badger seed without dropping tables or replacing accounts.
For an existing isolated installation, `--install-register` and
`--install-captcha` install only the named missing module after checking its
tables and module record do not already exist; neither reruns Badger's seed.
If partial module state is present, installation refuses and requires inspection.
The seed requires configured Gizmore and Dejavu entries; the Mira entry is
optional. Validate all entries and reject existing usernames before creating
any account, rather than silently replacing or elevating an existing user.
For new deployments set `GDO_BADGER_SEED_CONFIG` to an absolute path outside
the PHPGDO tree, pointing to a non-symlink PHP file readable only by its owner.
The installer and seed check use this file instead of the legacy module-local
`secret.php`. This is an explicit CLI environment setting, not a web setting.
The legacy path remains supported for existing installs but is tracked by Git;
never put new passwords there. Rotating credentials does not erase Git history.
`php GDO/Badger/install/bootstrap.php --seed-check` is a read-only diagnostic
for an existing isolated database: it reports whether configured seed accounts
are missing, present as members, or present with another account type. It
prints neither names nor credentials and does not grant rights or run a seed.
Badger depends on Cronjob for evidence retention, review notices and optional
AL review dispatch and CLI for management; the site must also run the PHPGDO
cron scheduler.
Badge tag search compares UTF-8 text with an explicit Unicode collation, so
the search remains usable when the connection and tag table have different
default collations.
Badger depends on Register so people can create accounts, and on Captcha for
Register's default CAPTCHA setting. This basic CAPTCHA is not a complete abuse
defense. Registration's activation, CAPTCHA and mail configuration must be
reviewed before public use.
The Admin module supplies the staff navigation used by payment and moderation
pages and must be installed before those sidebars are rendered.
For CLI and cron, explicitly set `GDO_BADGER_INSTANCE=1`; without that trusted
environment variable both entry points retain the normal PHPGDO configuration.
The Badger cron writes its PHPGDO logs under `temp_badger/cronlogs`, not the
shared `protected/logs`. `deploy/badger-cron.example` is a system-crontab
example with an overlap lock; it is not enabled automatically. The PHPGDO
scheduler runs jobs for all enabled modules, including cleanup jobs, so review
the installed modules and retention configuration before enabling it.
In the current shared Mail module, `MailCronjob` selects rows with
`mail_sent IS NOT NULL` and calls the mailer again; enabling the general
scheduler can therefore resend previously sent mail. Resolve that shared-module
behavior and review mail delivery before activating the example.
A separate port-80 staging virtual host in `deploy/badger-local.conf` permits
only local clients, denies private storage and the web installer, and selects
the Badger configuration. A second listener on 127.0.0.1:8443 uses a
short-lived self-signed certificate for local HTTPS testing only; neither is
a public or trusted TLS deployment.
The separate `deploy/badger-ssl-staging.conf` binds the requested domain on
port 443 with a publicly trusted Let's Encrypt certificate, but restricts the
application to local clients until deployment blockers are resolved. Port 80
allows the public ACME HTTP-01 path only; `deploy/badger-cert-reload.sh` reloads
Apache when this particular certificate renews. Certbot's timer handles renewal.
The 2026-10-29 staging renewal simulation succeeded after a temporary rate
limit from Let's Encrypt's test service. The issued production certificate
was also verified locally.
Badger access logs record the method and status but not the request URL or
referrer. PHPGDO may place activation tokens in either the path or query string,
so omitting only the query string is insufficient. A public virtual host and
any reverse proxy must enforce the same logging boundary.
The shared `GDO/Mailer/Mailer.php` currently disables SMTP certificate and
hostname verification. Do not send registration mail through that transport
until its TLS verification and the deployment's SMTP credentials are reviewed.
Run the Badger PHPUnit suite against the isolated Badger test configuration,
not the normal LinkUUp database, for example:

```
php -d auto_prepend_file=protected/config_badger.php vendor/bin/phpunit \
  --bootstrap GDO7.php --do-not-cache-result GDO/Badger/Test
```

The configured database principal must be able to create temporary tables.
The repository's PHPUnit configuration currently reports tests without explicit
coverage targets as risky; distinguish those warnings from test failures.
`php GDO/Badger/Test/LocalHttpsSmoke.php` checks login and the badge-creation
form on that loopback HTTPS listener using a configured seed account. It also
submits a deliberately invalid CSRF token and confirms that no badge or
credit-setting change occurred. It does not print credentials or cookies.
`php GDO/Badger/Test/LocalPurchaseSmoke.php --run-local-only` creates a
distinct synthetic member, exercises one real private badge purchase over the
loopback TLS listener, and deactivates the synthetic member afterward. The
private, clearly named test badge and account remain for inspection; existing
accounts and credits are not used.
`php GDO/Badger/Test/LocalLifecycleSmoke.php --run-local-only` uses two
synthetic accounts to exercise badge creation, request, approval, award
preview and claim over the same listener. It checks that XP is counted only
after claim, checks badge icons and recipient-only award previews, and verifies
that changing the badge from public to private removes it from an anonymous
listing and protects its uploaded background and icon. The synthetic item is
unequipped and both accounts are then deactivated; the images remain with the
test badge. The test also
checks that the general file method and the filesystem URL cannot bypass the
Badger image ACL for an anonymous visitor. It also equips the claimed item
through the web form and verifies the avatar layer and its intentionally public
equipped-item image, including when the badge itself is private. It changes
achievement sharing through the web form and verifies the public profile and
profile-icon endpoint follow that achievement ACL. It also checks that the tag
filter shows a public matching badge and stops showing it when made private,
and that purchasing an equipment slot charges the expected credits atomically.
It also revokes an equipped award, corrects the revocation as staff and checks
that the recipient must claim again before rewards return. A public detail view
must not reveal the private submission message, while recipient and reviewers
can see it. A second synthetic badge exercises the no-verification policy and
confirms that automatic verification still requires a separate claim.
`php GDO/Badger/Test/LocalConcurrentPurchases.php --run-local-only` submits
five simultaneous 50-credit purchases against a new synthetic account with
200 welcome credits. Exactly four badges may be created and the balance must
be zero. All created badges are private and the account is deactivated;
synthetic records remain for inspection.
`php GDO/Badger/Test/LocalActivationSmoke.php --run-local-only` creates a
synthetic pending activation and confirms it through the normal local GET link
without sending email. It checks the newly activated member has the expected
once-only welcome credits and can log in with its registered password, then
deactivates that member afterward. Its activation record is retained; it
verifies the test token does not appear in the recent Badger Apache access or
error logs.
`php GDO/Badger/Test/LocalBrowserSmoke.php --run-local-only` uses
Chromium/ChromeDriver with a dedicated temporary profile and synthetic member
to check browser login and create a private badge through the actual form. It
waits for asynchronous login navigation before checking the form. The
synthetic badge remains private after the account is deactivated. It accepts the staging
certificate only inside that test; certificate verification is covered
separately by `LocalHttpsSmoke.php`. The account is deactivated after the test;
the temporary profile is retained.
The isolated test records are retained for inspection.

# Badger (For Humans)

Badger lets a user account create badges such as “helped at an event”,
“completed a workshop” or “welcomed three newcomers”. A user asks for a badge,
the badge's staff, community or AL verification checks it, and the user sees
the award animation and new cosmetic item when it succeeds.

The module configures the default achievement visibility. The recipient can
change it using the available relationship-based ACL options.

## Methods

- `Badger.Badges` — browse badges and achievements that the current ACL allows.
- `Badger.MyAchievements` — list the current user's achievement requests and awards.
- `Badger.ReviewQueue` — list pending requests for badges the user may review.
- `Badger.CommunityQueue` — list visible pending requests on badges the user
  can community-review and has not voted on in the current round.
- `Badger.BadgeCrud` — GDO staff editor and moderation view.
- `Badger.Add` — add a badge for a user/issuer account. Cost Credits.
- `Badger.Edit` — edit a badge as its owner or staff.
- `Badger.Image` — Pixel editor for items (currently only upload works, credits are only paid once)
- `Badger.EvidenceUpload` — The recipient privately uploads evidence for the next claim round; upload fails closed if private storage or scanning is not configured.
- `Badger.EvidenceDownload` — Download retained evidence from the current pending or rejected round, only for its recipient or authorised badge reviewers.
- `Badger.Items` – Avatar item selector / preview
- `Badger.BuySlot` — buy one additional equipped-item slot with credits.
- `Badger.CategoryTree` — manage badge categories.
- `Badger.Achieve` — start, submit, withdraw, claim or acknowledge an achievement.
- `Badger.AddStaff` — add or remove badge staff; ownership remains with the owner.
- `Badger.Transfer` — transfer a badge to an existing member; only its owner or site staff can do this.
- `Badger.Rankings` — list badger users by xp, num achieves, etc.

## GDTs

- `GDT_BadgeTree` — visual hierarchy of badges with a nullable root parent.

## GDOs

`GDO_Badge` — one issuer-defined badge. Its physical table is named through
  `gdoTableName()` and therefore becomes `gdo_badger_badge`.

- bb_image GDT_ImageFile for badge overview background
- bb_icon GDT_ImageFile for tables and profile
- bb_item GDT_ImageFile (a transparent GIF; may be animated with 4 frames at
  250 ms each)
- bb_has_image GDT_Checkbox records whether the image option was purchased; it
  is immutable after creation, even before the image is uploaded.
- bb_item_<x|y|w|h> (position of the avatar item)
- bb_item_name
- bb_category
- bb_country: optional country; empty input is stored as NULL and displayed as
  “None”. It can be set or cleared when creating or editing a badge.
- bb_<xp|hp|str|int>
- and more...

`GDO_Achieve` — one recipient's badge attempt/award. Its physical table is
  `gdo_badger_achieve`; Core `GDT_Virtual` fields expose cosmetic aggregates,
  for example `SUM(bb_xp) GROUP BY ba_user`.

- ba_equipped (see user config bb_add_max_equip)
- ba_is_staff (user is marked as staff this way)
- ba_status (see in AL section)

`GDO_Evidence` records a private vault key, content hash, MIME type and the
achievement round to which an uploaded document belongs.

## Avatars

An avatar may layer equipped earned badge images over its base image. The Avatar
renderer must not honour the achievement ACL before showing an item; the ACL is
only for a user's profile achievement overview.

Avatars shall zoom out and equip items in an animation.

## Profile

Add a hook for profile main content card GDTs.

Add visible achievements with bb_icon display.

## Pixel Editor

Coming soon.

In v1, uploads convert to GIF and dimensions are validated with Core
`GDT_Width` and `GDT_Height` fields, using positive whole pixels.

## Credits

We use credits to control how many badges are added.

Some configurations cannot be edited after you add a badge (xp,hp,str,int,has_image)

All costs are non-negative whole credits. Reject a creation request if its
calculated price cannot be represented as a PHP integer; never wrap or round
an overflowing price. Badge stat fields accept only whole non-negative values
representable by their unsigned 32-bit database columns.

`Badger.Add` explains badge creation and shows a live credit-price preview at
the bottom of the form. The preview uses the configured base, stat and image
rates, including the first free XP point. Invalid or overflowing values show no
price. JavaScript is optional: the server always recalculates the authoritative
price when the form is submitted.

### Initial badge seeds

On a fresh installation, the configured Gizmore seed account owns three groups:
Caretaker (Cleanse Togetherness), Bookworm (Reading) and CBA. Each has a root badge,
its own category and the listed cleaning or reading achievements. Badger does
not yet support parent-child badge relationships: the root badge and its
achievements share the category, rather than implying a prerequisite. Each
group shares its own icon; the roots grant the Golden WC Brush and the
Page-turning Book items. The book item visually turns a page when equipped;
reduced-motion preferences suppress this animation. Seeding is only part of
installation; it does not modify an existing live installation automatically.

CBA adds a playful backwards-spelling group: spell your name backwards, learn
from midpoint M halfway down, reverse a short word, learn from Z halfway down,
memorize remaining errors, and finally recite the alphabet backwards. Its root
grants a Möbius Compass and the error-memory milestone grants a Tesseract Lantern. Optional per-leaf
icons and items are seeded like root assets; other leaves inherit their group's
icon. The category is an organizational group, not an enforced prerequisite.

`Badger.FAQ` explains the basic badge and achievement workflow. Its heading
uses the optional JQuery candle effect in the UI module's theme color; the page
finishes with the optional text explosion and remains readable when effects or
JavaScript are disabled.

## Badger RPG

Each Achievement adds to your xp, hp, str and int.
Some badges come with an image, which you can equip for your avatar.

A separate combat system, similar to SlapWars, is coming later. It is outside
Badger v1; the current XP, HP, STR, INT and item values remain cosmetic.

## Module configuration

- `bb_welcome_credits=200` – initial credits for a user.
- `bb_credits_per_<badge=50|xp=50|hp=50|str=50|int=50|image=50>` — cost for `Badger.Add`.
- `bb_default_<xp=1|hp=0|str=0|int=0|max_equip=2>` — cosmetic defaults for a badge.
- `bb_credits_per_equip_slot=50` — credit price for one additional slot.
- `bb_item_max_w` / `bb_item_max_h` — maximum width and height for an
  uploaded avatar item in pixels; validated with `GDT_Width` and `GDT_Height`.
- `bb_item_max_bytes=2097152` — maximum source upload size for an avatar item.
- `bb_default_achieve_visibility=acl_all` (`GDT_ACLRelation`) — module default
  for a user's initial achievement visibility setting; initially public.
- `bb_default_verify_staff=1` — whether new badges initially require issuer
  staff approval.
- `bb_default_staff_approvals=1` and `bb_default_community_approvals=1` —
  positive default thresholds copied to newly created badges. Badge editors
  may subsequently change those thresholds.
- A review timeout is under discussion; its action is not yet specified.
- permitted document MIME types, maximum size, malware scanning and retention.
- rate limits for achievement requests and community attestations.
- `bb_claims_per_hour=20` and `bb_community_votes_per_hour=50` — per-user
  rolling one-hour limits for submissions and community approvals; zero disables
  the corresponding limit. Counts use the append-only decision records and
  the actor's database row serializes concurrent requests.
- `bb_rejected_document_delete_after` — automatic deletion delay for
  rejected evidence that staff did not handle, in seconds (default 604800).
- `bb_evidence_max_bytes=5242880` — maximum size of an evidence upload.
- `bb_evidence_allowed_mimes` — allowed evidence MIME types; the initial
  default permits PDF, PNG and JPEG.
- `bb_evidence_private_dir` and `bb_evidence_scanner` — private storage and
  malware scanner configuration. Both start empty; evidence uploads fail
  closed until both are configured.
- `bb_evidence_scan_timeout=15` — maximum seconds for a scanner invocation.
- `bb_ai_provider_class` — optional operator-configured local PHP class
  implementing `AIReviewProvider`; empty disables the AL worker. Selecting a
  provider authorises its adapter to process the data explicitly described in
  the badge's review policy.
- `bb_ai_ollama_model` — model for the optional `OllamaAIReviewProvider`;
  the adapter connects only to `http://127.0.0.1:11434/api/chat`, uses a
  bounded non-streaming JSON response, and is disabled until selected.

## User configuration

Internal per user config.

- `bb_add_max_equip` (`GDT_Int`) — additional equipped-achievement capacity
  purchased with credits.

## User settings

User can set this.

- `bb_default_achieve_visible` (`GDT_ACLRelation`) — default ACL for a new
  achievement, initially set from `bb_default_achieve_visibility`.


# Badger (For ALs)

This is the implementation contract. Build from it top down; do not infer
workflow rules from a UI. Badger is not a silent profiling or reputation
harvesting system: every badge is an explicit claim and every achievement-
overview display is controlled by a relationship ACL. Equipped avatar items are the
intentional exception.

## Methods

| Method | Purpose | Primary actor |
| --- | --- | --- |
| `Badger.Badges` | Browse badge definitions and ACL-permitted achievements. | everyone |
| `Badger.MyAchievements` | List the recipient's own visible requests and awards. | recipient |
| `Badger.ReviewQueue` | List pending requests scoped to the badge reviewer. | badge staff / owner / GDO staff |
| `Badger.CommunityQueue` | List ACL-visible pending requests eligible for a community vote. | eligible badge holder |
| `Badger.BadgeCrud` | GDO-wide badge moderation and editing. | GDO staff |
| `Badger.Add` | Create a badge for a user account; charge configured credits. | owner |
| `Badger.Edit` | Edit a badge owned by the current issuer. | owner / badge staff |
| `Badger.Image` | Create or select badge imagery. | owner / badge staff |
| `Badger.EvidenceUpload` | Upload a scanned, private document for the recipient's next claim round. | achievement recipient |
| `Badger.EvidenceDownload` | Privately download the current pending round's evidence. | recipient / badge staff |
| `Badger.Items` | Select and preview equipped avatar items. | recipient |
| `Badger.BuySlot` | Purchase one extra equipped-item slot atomically. | recipient |
| `Badger.Rankings` | List aggregate Badger totals per user. | everyone |
| `Badger.CategoryTree` | Manage the Badger category hierarchy. | category managers |
| `Badger.Achieve` | Start, submit, withdraw, inspect or acknowledge an award. | recipient |
| `Badger.AddStaff` | Create or find a scoped achievement and set or clear `ba_is_staff`. | issuer owner |
| `Badger.Transfer` | Transfer the badge to an existing member account. | issuer owner / GDO staff |

- A) *`Badger.BadgeCrud` is reserved for GDO staff; issuers use `Add` and
  `Edit` for their own badges.*
- B) One editor serves issuer and GDO staff with permission-filtered fields.
- C) Badge creation is GDO-staff-only during the first beta.

## GDTs

- `GDT_BadgeTree` renders a badge hierarchy rooted at a null `parent_id`.
- Core `GDT_Virtual` provides subquery-generated cosmetic aggregates on
  `GDO_Achieve`; Badger does not implement a separate RPG table.
- `GDT_BadgeUserUnique` declares a database uniqueness constraint on
  `ba_badge` and `ba_user` so concurrent requests cannot create duplicates.
- `GDT_BadgeAction` links a listed badge to its achievement request form.
- `GDT_BadgeIcon` renders a badge-list icon through the badge ACL endpoint.
- `GDT_AchievementAction` links an achievement row to its request form.
- `GDT_BadgeEditAction` links a staff moderation row to its badge editor.

## GDOs

PHP classes use concise module-local names. `gdoTableName()` supplies the
module prefix, so no class name needs `Badger` embedded in it.

- `GDO_Category` → `gdo_badger_category`: `parent_id`, name, slug, sort order
  and timestamps. Parent is nullable; cycles are forbidden.
- `GDO_Badge` → `gdo_badger_badge`: issuer/owner, text, category/tags,
  overview-background `bb_image`, table/profile `bb_icon`, transparent and
  optionally animated `bb_item`, `bb_item_<x|y|w|h>`, `bb_item_name`,
  `bb_category`, nullable country `bb_country`, cosmetic
  `bb_<xp|hp|str|int>`, verification fields, default visibility and
  active/archived state. Badges are edited in place; there is no version field.
- `GDO_Achieve` → `gdo_badger_achieve`: badge, recipient, verification status,
  deletion state, approval counters, evidence references, ACL, `ba_equipped`,
  `ba_is_staff` and lifecycle timestamps. Core `GDT_Virtual` fields calculate
  `SUM(bb_<xp|hp|str|int>) GROUP BY ba_user` from claimed, non-revoked
  achievements.
- `GDO_Ranking` is a read-only query projection keyed by user, not an
  installed table or an RPG state store.
- `GDO_BadgeTag` stores badge-scoped tag names. Tag editing uses the Tags
  module's name validation, but private badge tags are not published to the
  global tag completion endpoint. A badge's tags follow its visibility ACL.
  Editors may set up to eight comma-separated tags, with case-insensitive
  duplicate removal; an empty field removes all tags.
  The badge-list tag filter matches tag text only within the badge query's
  existing visibility ACL; input is treated as literal UTF-8 text, not SQL or
  a wildcard pattern, and it does not expose global tag suggestions. Icons
  and action links are display-only and do not advertise numeric filters.
- `GDO_ReviewNotice` is a durable per-recipient outbox for AL rejection PMs.
  The rejection and all notice entries are committed together; delivery is
  retried independently and must never include the uploaded source document.
  The minute-based worker rechecks the recipient's current badge role, writes
  the PM and marks the notice delivered in one transaction, and suppresses a
  notice if the recipient is no longer authorised or no longer a member.
  A persistent composite cursor rotates the bounded delivery batch. A failed
  notice stays pending, is logged without its contents, and does not prevent
  other notices from being attempted. Later passes retry it after wraparound.

### Owner and staff

A badge belongs to one normal user account. `ba_is_staff` marks the user on a
badge's achievement as staff. `Badger.AddStaff` creates an achievement in an
initial `viewed` state when needed, without granting a reward. Badge staff may
only be newly assigned to an existing member account; removing a staff marker
remains possible after that account is deactivated. Owner and badge
staff have almost identical operational permissions. Only the owner may
transfer ownership or change `ba_is_staff` through `Badger.AddStaff`. Revocation
or deletion changes the achievement's status or deletion state, not its staff
membership; use `Badger.AddStaff` to remove staff rights explicitly.
Ownership transfer changes `bb_owner` under a database lock and immediately
changes owner permissions. It does not automatically add or remove any staff
marker, alter awards, or charge credits. The confirmation form names the
recipient; only an existing member may receive ownership.

### Credits and immutable badge fields

`bb_welcome_credits` gives a new user initial credits. `Badger.Add` charges
the applicable `bb_credits_per_*` values for badge creation. `Badger.Edit`
does not charge credits.

The welcome grant is recorded once per account and adds to any existing
PaymentCredits balance. On member activation it is granted within the current
registration transaction when one exists. Existing accounts without a grant
receive it during their first successful Badger purchase; in that fallback,
grant, charge and creation commit together, so failure changes neither balance
nor marker. Lock the account and balance for concurrent grants and purchases
and reject insufficient funds instead of creating a badge.
Badge creation also rechecks the account's current member type while holding
that lock, so a stale member object cannot purchase after deactivation. Slot
purchases perform the same check before granting or charging any credits.

Deployment warning: the shared PaymentCredits grant/order paths currently call
`GDO_User::increaseSetting()`, which reads and later writes the balance without
Badger's account lock. A concurrent external credit grant can therefore
overwrite a Badger debit. Do not enable concurrent PaymentCredits grants and
Badger purchases until the shared writer uses a compatible atomic update or
lock; changes to that shared module also affect LinkUUp and require review.

XP, HP, STR and INT are charged per point at their respective configured rates,
in addition to the badge base cost. The first XP point is free; each additional
XP point and every HP, STR and INT point costs 50 credits by default. An image
adds its configured one-time cost.

Once a badge exists, its cosmetic `bb_<xp|hp|str|int>` values and whether it
has an image/item cannot be changed. Text, category, verification settings and
active state remain editable.

`bb_default_<...|max_equip=2>` supplies the base equipped-item capacity.
`bb_add_max_equip` is the user-owned additional capacity bought with credits;
each purchase adds exactly one slot and debits `bb_credits_per_equip_slot` in
the same transaction. Reject overflow and insufficient funds without changing
either the balance or capacity. The total limit is their sum.

### Rankings

`Badger.Rankings` displays aggregate XP, HP, STR, INT and achievement counts
for each user. It sums claimed, non-revoked achievements and deliberately does
not reveal which badge or achievement supplied a value. Individual achievement
ACLs therefore govern profile-overview details, not these source-free totals.
Only current member accounts appear; deactivated or guest accounts do not.

### Profile and image integration

Patch Core with the profile main-content card-GDT hook required by Badger. The
card displays achievements permitted by the profile ACL using `bb_icon`.
Only claimed achievements not removed by their recipient appear in this
profile overview. A badge icon is delivered through a Badger endpoint that
rechecks the corresponding achievement ACL for each request.
Badge-list icons use a separate badge-ACL-checked endpoint. The award preview
uses a recipient-only item endpoint for verified or claimed achievements; it
must work before the item is equipped and must not expose another user's award.
The request identity must be a member; anonymous, guest or deactivated
identities receive the same 404 as an unavailable item.
Missing or inaccessible badge backgrounds, profile icons and evidence return
HTTP 404 without revealing whether a protected resource exists.

`Badger.Image` is upload-only in v1: it converts uploads to GIF and validates
dimensions in whole pixels with `GDT_Width` and `GDT_Height` against `bb_item_max_w` and
`bb_item_max_h`. Check the actual decoded image format, byte size and dimensions
before decoding; accept GIF, PNG, JPEG and WebP. Preserve uploaded GIF bytes so
animation is not flattened; convert other formats to GIF with transparent pixels
preserved. The pixel editor is a later feature.
Native uploads use an opt-in multipart form, accept only an actual PHP HTTP
upload for the current request and reject file IDs supplied as input. This
prevents selecting arbitrary existing files and avoids persisting an upload
before the form's CSRF check.
`Badger.Image` rechecks the current badge owner/staff and purchased image
option under the badge lock. It stores a new GIF through `GDO_File`, then
updates the badge reference in the same database transaction. A failed write
rolls back the database changes and removes the new file copy; existing files
are not deleted when an image is replaced.
The purchased image option enables uploads for the avatar item, badge icon
and badge overview background. Each has its own reference and may be replaced
without another charge.
Badge backgrounds are served by badge ID with the badge visibility ACL checked
on every request; they are displayed on the achievement detail page.

## Achievement lifecycle

`GDO_Achieve` holds the current status of one recipient's attempt. Its status
and its deletion state are independent.

| Status | Meaning | Typical next states |
| --- | --- | --- |
| `viewed` | Initial, unsubmitted achievement record, including one created for staff. | `draft`, `pending` |
| `draft` | Recipient started a form but has not submitted it. | `pending`, `withdrawn` |
| `pending` | Submitted request awaiting staff, community, document or AL verification. | `verified`, `rejected`, `withdrawn` |
| `verified` | Award is approved; recipient has not yet acknowledged it and rewards do not yet count. | `claimed`, `revoked` |
| `claimed` | Recipient saw the success message, animation and reward/item update. | `revoked` |
| `rejected` | Verification failed; recipient and issuer staff can see the reason. | `draft`, `pending` |
| `withdrawn` | Recipient stopped the request. | `draft` |
| `revoked` | A verified or claimed achievement was invalidated. | `verified` by staff correction only |

`Badger.Achieve` changes `verified → claimed` only after the recipient has
seen the award update. Cosmetic totals and equipment become available at
`claimed`; the presentation may preview the award before the transition.
An authorised badge reviewer may correct an erroneous revocation with a
nonempty reason. This records a `revoked → verified` staff attestation, never
restores rewards or equipment automatically, and requires the recipient to
acknowledge the award again before rewards count. The correction reason is
visible in the achievement history to the same viewers as the revocation.
Starting an attempt and assigning staff both lock the badge before checking for
an existing attempt, so simultaneous requests reuse the same record. The active
state, badge visibility and badge ownership are rechecked while holding that
lock. Badge staff can still see the badge definitions they manage.
The action form offers only actions permitted for the viewer and current
status. A withdrawn request can be resumed as a draft before resubmission;
a read-only viewer, including an anonymous visitor when the achievement ACL
permits it, receives no action controls. Starting a request requires a member
account. The detail page shows the
badge name and current status before the history and action form.

The transition and its attestation must share one transaction. A caller that
already owns a transaction passes `ownsTransaction=false` to the internal
transition service and is responsible for committing or rolling it back;
the service must not commit an enclosing method transaction. Authorization is
checked by the caller before invoking the service.

### Deletion state

An achievement is never physically deleted. Deletion never replaces its
verification status; `GDO_Achieve` stores one of these four states:

| Deletion state | Meaning |
| --- | --- |
| `not_deleted` | Neither side requested deletion. |
| `deleted_badger` | Badge staff removed it from their active view; the recipient did not. |
| `deleted_user` | Recipient removed it from their view; badge staff retain the record. |
| `deleted_both` | Recipient and badge staff removed it from their views; the achievement record remains. |

The deletion state changes the achievement ACL and evidence retention, while
the original verification status remains auditable.
Deletion and sharing recheck the target, role and current state while holding
the badge and achievement locks. The recipient or GDO staff may change sharing;
each deletion side is recorded independently.

## Attestations and audit trail

Every approval, rejection, revocation, community vote, AL recommendation and
system transition creates an immutable `GDO_Attest`. `GDO_Achieve` stores only
the current counters and status.

Each attestation records achievement ID, actor type (`staff`, `community`,
`ai`, `system`), actor identity, decision, optional reason/evidence reference,
timestamp and resulting state transition.

- A) *Revocation requires a reason and creates an attestation visible to every
  principal allowed to view the achievement.*
- B) Revocation history is visible only to recipient and issuer staff.
- C) Verified achievements cannot be revoked.

## Verification policies

`GDO_Badge` selects verification behaviour with these fields:

- `bb_verify_staff` (`bool`) — authorised badge staff approve or reject.
- `bb_verify_cc` (`bool`) — eligible community members who already hold the
  badge may attest; store distinct approver IDs and never count issuer staff
  twice.
- `bb_verify_doc` (`bool`) — the claim requires a document/image upload.
  Badge editors can enable it only after private storage and an executable
  scanner are configured. Changing it is refused while requests are pending.
- `bb_verify_ai` (`prompt_string`) — prompt given to the AL together with the
  recipient message and badge rubric.
- `bb_verify_ai_doc` (`prompt_string`) — additional AL-mode prompt when
  document evidence is present.
- `bb_approve_ai` (`bool`) — permits AL approval/rejection to change status
  automatically for that badge; otherwise it remains a recommendation.
- `bb_staff_approvals_needed` — number of distinct staff approvals required.
- `bb_community_approvals_needed` — number of distinct eligible community
  approvals required when `bb_verify_cc` is enabled, default 1.

An eligible community approver has a claimed achievement for the same badge,
is a member, is not the recipient, badge owner or badge staff, and can view the request.
Community approvals are unique per actor and verification round. The staff
and community thresholds are conjunctive when both are enabled. Starting a
new round resets both counters but retains all prior attestations.
An actor who already voted in one role may not vote in the other role during
the same round, even if their staff membership changes later.

Each submission starts a new verification round. Staff approvals are unique
per actor and round; a resubmission resets the current approval count without
deleting earlier attestations. Once the staff threshold is met, other enabled
checks must still succeed before verification.
The recipient may include a message when submitting. Store it in that round's
immutable `pending` attestation; show it only to the recipient and authorised
badge reviewers, not merely because an achievement is publicly visible.
Approval checks lock and reread the badge, current staff membership, target
achievement and existing votes; cached objects or an earlier database snapshot
must not grant staff rights or hide a prior vote. Recipient submission,
withdrawal, acknowledgement, rejection and revocation likewise verify the
current badge, recipient and applicable staff membership under the lock.

More than one enabled verification field is conjunctive: all required checks
must finish before the award is verified.
If no verification field is enabled, submission records both `pending` and a
system `verified` transition in the same transaction; the recipient still
explicitly claims the award before cosmetic rewards count.

AL output stores prompt/rubric metadata, model/result metadata and a
recipient-visible rationale in `GDO_Attest`. It must not ask for unrelated
secrets or reveal sensitive data.
AL adapters receive only the pending round's badge rubric, configured prompts,
recipient message and, when document review is enabled, its verified private
evidence, alongside the adapter class/model and evidence MIME needed to process
them. Recipient, badge and achievement identifiers stay in the internal
snapshot and are not passed to the adapter. The snapshot has a fingerprint of these inputs and
the round; a result for a changed request or policy must not be applied. No
external provider is contacted unless explicitly configured by the operator.
For each AL-enabled submission, the form identifies the configured adapter and
asks for the recipient's explicit consent before sending the claim and any
AL-reviewed document to it. The consent is recorded on the submission
attestation; without it, neither submission nor AL capture may proceed.
The consent identifies the configured adapter class; changing providers
or its configured model requires the recipient to withdraw and submit a new
consenting round. The built-in local Ollama adapter sends text and optionally
PNG/JPEG evidence; PDF evidence fails closed until a suitable provider exists.
The consent form includes a fingerprint of the displayed provider and model;
if configuration changes before submission, the recipient must review the
updated disclosure and consent again. The adapter uses the consented model
from the captured request rather than rereading a newer configuration.
Badge editors cannot change verification methods, thresholds or AL policy
while any request for the badge is pending. Other editable badge fields remain
available. Enabling an AL prompt requires a configured provider; a document
prompt also requires document verification.
Internal AL recommendation recording locks the badge and achievement, then
rechecks that fingerprint against the current pending round. The immutable
attestation stores a bounded rationale and metadata (provider, model, result,
snapshot and prompt hashes). A recommendation does not change an award when
automatic decisions are disabled.
An operator-supplied `AIReviewProvider` adapter returns a model identifier,
decision and rationale. `AIReviewRunner` captures input, calls that adapter
outside the database transaction, and records only a still-current result.
Identical recommendations for the same snapshot are idempotent. No default
provider, public result-submission endpoint or automatic external request is
provided by this interface.
The scheduled AL worker uses only the configured adapter, processes pending
rounds in bounded batches and does not resend a snapshot already reviewed by
that provider. Provider errors leave the claim pending for retry; logs contain
neither prompts, messages nor document contents.
The worker advances a persistent achievement-ID cursor after each review
processing attempt, including an input or provider failure. It starts after that cursor and wraps around,
so repeatedly failing early requests do not permanently starve later requests.
The cursor does not contain claim content or a credential.
When `bb_approve_ai` is enabled, a positive recommendation matching the
current fingerprint satisfies the AL check. It verifies an award only when
every other enabled check also succeeds; otherwise subsequent checks re-read
the current fingerprint before using it.
When automatic decisions are off, badge staff may explicitly accept a positive
current recommendation; this records a separate human attestation for the
same fingerprint. A negative or inconclusive recommendation does not satisfy
the AL check, though staff may reject the claim through the normal workflow.
An automatic negative decision transitions the current round to `rejected`
and queues a private notice for its owner and badge staff in the same database
transaction. The notice contains only a link to the request, not the private
document or prompt. If automatic decisions are disabled, the result remains a
recommendation and creates no rejection notice.

### Document evidence retention

Document/image evidence is private to the recipient and authorised reviewers.
Store a reference/hash in the achievement or attestation, not the source after
it is no longer needed.
Determine MIME from file content, not a submitted filename or browser header;
reject empty, oversized or disallowed evidence. The configured scanner must
positively report a clean result; an absent scanner, an error or an infected
result never accepts evidence. Do not place evidence in the public file store.
The scanner is an administrator-configured absolute path to a
`clamscan`-compatible executable. Invoke it without a shell on a private
temporary file: exit 0 is clean, 1 is infected, all other exits and timeouts
are failures. Delete the temporary file after the scan.
The evidence vault requires a pre-existing, non-symlink absolute directory owned
by the PHP user with no group/other permissions, outside both the PHPGDO tree
and configured web document root. Store accepted content under random opaque
names with mode 0600; a vault key is not itself an authorization token. Reads
and deletions require lifecycle authorization before invoking the vault.
If PHP cannot determine its effective UID, vault validation fails closed.
The recipient may attach one scanned document to the next round while the
request is viewed, draft, rejected or withdrawn. A duplicate is rejected. A
failed database transaction removes the newly created source. A request that
requires a document cannot be submitted without a live document for its next
round. An authorised badge reviewer may approve that document after review;
record a `document_approve` staff attestation bound to its evidence ID and
content hash, independent of the ordinary staff vote. Verification requires
this and all other enabled checks. Reject duplicate approvals in a round.

- On AL rejection, create the rejection attestation and PM badge staff to
  review or delete the source evidence.
- While the current round is rejected, the recipient and authorised reviewers
  may download its retained evidence; only badge owner/staff or site staff may
  delete it immediately. Manual deletion rechecks the rejected round and current
  reviewer role under locks and retains the evidence metadata and hash.
  The download and deletion controls are shown only while current-round
  evidence remains available; a stale request still rechecks at execution.
- If staff do not act, rejected evidence is deleted after
  `bb_rejected_document_delete_after`.
- A) *Delete source evidence after successful verification once its hash and
  decision record are stored.*
- B) Keep accepted evidence until the recipient acknowledges the award.
- C) Keep accepted evidence for a configured appeal period.

The hourly cleanup job retries unfinished deletions. It removes only vault
files whose own verification round has a recorded successful transition, or a
rejection older than the configured delay; it keeps the evidence metadata and
hash. The job rechecks eligibility under the evidence row lock before deleting.
The bounded cleanup rotates a persistent evidence-ID cursor. A failure leaves
that record eligible for retry, is logged without document contents, and does
not prevent later eligible records from being processed.

Every terminal deletion state may trigger source-evidence deletion when no
review or appeal remains.

## ACL, roles and visibility

Every badge definition and achievement has an `ACL_Relation`. A user's
`bb_default_achieve_visible` seeds a new achievement ACL. Recipient,
badge owner/staff and GDO staff always retain the access necessary for the
lifecycle and audit trail.

| Action | Recipient | Badge staff | Issuer owner | GDO staff |
| --- | --- | --- | --- | --- |
| View public badge definition | yes | yes | yes | yes |
| Start / submit / withdraw own request | own only | no | no | yes |
| Approve / reject scoped request | no | yes | yes | yes |
| Community-attest eligible request | policy dependent | policy dependent | policy dependent | yes |
| Edit badge | no | yes | yes | yes |
| Change `ba_is_staff` | no | no | yes | yes |
| Transfer badge ownership | no | no | yes | yes |
| Revoke achievement | no | yes | yes | yes |
| Change achievement sharing | own only | no | no | yes |

Personal achievements and the staff/community review queues require a member
account at the method boundary; anonymous and guest identities cannot open
these lists even if they happen to have a numeric user ID.
Achievement changes and badge editing likewise require a member identity;
retaining a recipient, owner or staff record does not authorize a guest.

The module-configured default seeds the user's visibility setting, which seeds
new achievement ACLs. The recipient can change visibility using the relation
choices supported by `GDT_ACLRelation`; arbitrary individual selections are
not part of this contract.

Third-party views may only read achievements explicitly shared with that party
or made public. Badger must not derive hidden profiles or scores from badges,
categories or attestation history.

Avatar rendering zooms out and equips selected item images in an animation.
It does not consult individual achievement ACLs. It may equip no more than the
module base capacity plus the user's `bb_add_max_equip` achievements in total.
`Badger.Items` previews the current equipped avatar above the selector; changes
appear in that preview after the form is submitted.
Only claimed achievements with an uploaded item can be equipped. Equipping
and unequipping lock the user and their achievement rows so simultaneous
requests cannot exceed that limit. Revoking an achievement clears its equipped
flag. Item name and coordinates remain descriptive data rather than an
implicitly inferred exclusive slot.
Badger supplies its equipped item layers through Avatar's `AvatarLayers` hook.
Coordinates and item sizes are measured against the configured maximum item
canvas and scaled to the displayed avatar. Item-image URLs identify an
equipped, claimed achievement, not an arbitrary file ID.
The image endpoint sends the GIF inline and without caching, so replacing or
unequipping an item does not leave a stale browser-cached response.

## Initial implementation slice

Implement one staff-verified badge, configurable achievement ACLs, `GDO_Attest`,
virtual cosmetic reward fields on `GDO_Achieve`, recipient acknowledgement
(`verified → claimed`) and explicit sharing. Community voting, AL verification,
document evidence, avatar layering and tag integration follow after this slice
is proven.

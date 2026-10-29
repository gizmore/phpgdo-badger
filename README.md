# Badger

Badger is a generic, community-verifiable achievement module. Issuers define
badges, users earn them through an explicit verification process, and Badger
keeps a small auditable record of the decisions. Its initial RPG-style rewards
are cosmetic: an achievement can show a new item, image, XP or stats without
changing unrelated game mechanics.

# Badger (For Humans)

Badger lets a person or organisation account create badges such as “helped at
an event”, “completed a workshop” or “welcomed three newcomers”. A user asks
for a badge, the badge's staff, community or AL verification checks it, and the
user sees the award animation and new cosmetic item when it succeeds.

Achievements are private by default. The recipient decides whether to show one
publicly, to an organisation or to selected people.

## Methods

- `Badger.Badges` — browse badges and achievements that the current ACL allows.
- `Badger.BadgeCrud` — GDO staff editor and moderation view.
- `Badger.Add` — add a badge for a user/issuer account. Cost Credits.
- `Badger.Edit` — edit a badge as its owner or staff.
- `Badger.Image` — Pixel editor for items (currently only upload works, credits are only paid once)
- `Badger.Items` – Avatar item selector / preview
- `Badger.CategoryTree` — manage badge categories.
- `Badger.Achieve` — start, submit, withdraw, claim or acknowledge an achievement.
- `Badger.AddStaff` — add badge staff; ownership remains with the owner.

## GDTs

- `GDT_BadgeTree` — visual hierarchy of badges each rooted at `parent_id = 0`.

## GDOs

`GDO_Badge` — one issuer-defined badge. Its physical table is named through
  `gdo_table_name()` and therefore becomes `gdo_badger_badge`.

- bb_image GDT_ImageFile for badge overview background
- bb_icon GDT_ImageFile for tables and profile
- bb_item GDT_ImageFile (a transparent GIF; may be animated with 4 frames at
  250 ms each)
- bb_item_<x|y|w|h> (position of the avatar item)
- bb_item_name
- bb_item_paid_at
- bb_image_paid_at
- bb_category
- bb_<xp|hp|str|int>
- and more...

`GDO_Achieve` — one recipient's badge attempt/award. Its physical table is
  `gdo_badger_achieve`; Core `GDT_Virtual` fields expose cosmetic aggregates,
  for example `SUM(bb_xp) GROUP BY ba_user`.

- ba_equipped (see user config bb_max_equipped)
- ba_staff (user is marked as staff this way)
- ba_status (see in AL section)

## Avatars

An avatar may layer equipped earned badge images over its base image. The Avatar
renderer must honour the achievement ACL before showing an item.

## Module configuration

- `bb_welcome_credits=200` – initial credits for a user.
- `bb_credits_per_<badge=50|xp=50|hp=50|str=50|int=50|image=50>` — cost for `Badger.Add|Edit`.
- `bb_default_<xp=1|hp=0|str=0|int=0>` — cosmetic defaults for a badge.
- default staff/community approval thresholds and review timeout.
- default achievement visibility and issuer-approval requirement.
- permitted document MIME types, maximum size, malware scanning and retention.
- rate limits for achievement requests and community attestations.
- `bb_rejected_document_delete_after` — automatic deletion delay for
  rejected evidence that staff did not handle.

## User configuration

Internal per user config.

- `bb_max_equipped` (`GDT_Int`) — maximum number of extra equipped achievement
  items for that user (credits to increase).

## User settings

User can set this.

- `bb_default_achieve_visible=friends` (`GDT_ACLRelation`) — default ACL for a new
  achievement.


# Badger (For ALs)

This is the implementation contract. Build from it top down; do not infer
workflow rules from a UI. Badger is not a silent profiling or reputation
harvesting system: every badge is an explicit claim and every display is
controlled by an ACL.

## Methods

| Method | Purpose | Primary actor |
| --- | --- | --- |
| `Badger.Badges` | Browse badge definitions and ACL-permitted achievements. | everyone |
| `Badger.BadgeCrud` | GDO-wide badge moderation and editing. | GDO staff |
| `Badger.Add` | Create a badge for an issuer account. | issuer owner |
| `Badger.Edit` | Edit a badge owned by the current issuer. | owner / badge staff |
| `Badger.Image` | Create or select badge imagery. | owner / badge staff |
| `Badger.Items` | Select and preview equipped avatar items. | recipient |
| `Badger.CategoryTree` | Manage the Badger category hierarchy. | category managers |
| `Badger.Achieve` | Start, submit, withdraw, inspect or acknowledge an award. | recipient |
| `Badger.AddStaff` | Set or clear `ba_staff` on a scoped achievement. | issuer owner |

- A) *`Badger.BadgeCrud` is reserved for GDO staff; issuers use `Add` and
  `Edit` for their own badges.*
- B) One editor serves issuer and GDO staff with permission-filtered fields.
- C) Badge creation is GDO-staff-only during the first beta.

## GDTs

- `GDT_BadgeTree` renders a badge hierarchy rooted at `parent_id = 0`.
- Core `GDT_Virtual` provides subquery-generated cosmetic aggregates on
  `GDO_Achieve`; Badger does not implement a separate RPG table.

## GDOs

PHP classes use concise module-local names. `gdo_table_name()` supplies the
module prefix, so no class name needs `Badger` embedded in it.

- `GDO_Category` → `gdo_badger_category`: `parent_id`, name, slug, sort order
  and timestamps. Parent is nullable; cycles are forbidden.
- `GDO_Badge` → `gdo_badger_badge`: issuer/owner, text, category/tags,
  overview-background `bb_image`, table/profile `bb_icon`, transparent and
  optionally animated `bb_item`, `bb_item_<x|y|w|h>`, `bb_item_name`,
  `bb_item_paid_at`, `bb_image_paid_at`, `bb_category`, cosmetic
  `bb_<xp|hp|str|int>`, verification fields, default visibility and
  active/archived state. Badges are edited in place; there is no version field.
- `GDO_Achieve` → `gdo_badger_achieve`: badge, recipient, verification status,
  deletion state, approval counters, evidence references, ACL, `ba_equipped`,
  `ba_staff` and lifecycle timestamps. Core `GDT_Virtual` fields calculate
  `SUM(bb_<xp|hp|str|int>) GROUP BY ba_user` from claimed, non-revoked
  achievements.

### Issuers, owner and staff

An issuer is a normal user account. An organisation is represented by such an
account with its address/profile information; Badger needs no separate
organisation GDO. `ba_staff` marks an achievement holder as badge staff. Owner
and badge staff have almost identical operational permissions. Only the owner
may transfer ownership or change `ba_staff`.

## Achievement lifecycle

`GDO_Achieve` holds the current status of one recipient's attempt. Its status
and its deletion state are independent.

| Status | Meaning | Typical next states |
| --- | --- | --- |
| `draft` | Recipient started a form but has not submitted it. | `pending`, `withdrawn` |
| `pending` | Submitted request awaiting staff, community, document or AL verification. | `verified`, `rejected`, `withdrawn` |
| `verified` | Award is granted and virtual cosmetic values are available; recipient has not yet acknowledged it. | `claimed`, `revoked` |
| `claimed` | Recipient saw the success message, animation and reward/item update. | `revoked` |
| `rejected` | Verification failed; recipient and issuer staff can see the reason. | `draft`, `pending` |
| `withdrawn` | Recipient stopped the request. | `draft` |
| `revoked` | A verified or claimed achievement was invalidated. | staff correction only |

`Badger.Achieve` changes `verified → claimed` only after the recipient has
seen the award update. Cosmetic data becomes available at `verified`; `claimed`
records that it was presented to the recipient.

### Deletion state

Deletion never replaces the verification status. `GDO_Achieve` stores one of
these four states:

| Deletion state | Meaning |
| --- | --- |
| `not_deleted` | Neither side requested deletion. |
| `deleted_badger` | Badge staff removed it from their active view; the recipient did not. |
| `deleted_user` | Recipient removed it from their view; badge staff retain the record. |
| `deleted_both` | Recipient and badge staff removed it; retain only the minimum audit data. |

The deletion state changes the achievement ACL and evidence retention, while
the original verification status remains auditable.

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
- `bb_verify_ai` (`prompt_string`) — prompt given to the AL together with the
  recipient message and badge rubric.
- `bb_verify_ai_doc` (`prompt_string`) — additional AL-mode prompt when
  document evidence is present.
- `bb_approve_ai` (`bool`) — permits AL approval/rejection to change status
  automatically for that badge; otherwise it remains a recommendation.
- `bb_staff_approvals_needed` — number of distinct staff approvals required.

More than one enabled verification field is conjunctive: all required checks
must finish before the award is verified.

AL output stores prompt/rubric metadata, model/result metadata and a
recipient-visible rationale in `GDO_Attest`. It must not ask for unrelated
secrets or reveal sensitive data.

### Document evidence retention

Document/image evidence is private to the recipient and authorised reviewers.
Store a reference/hash in the achievement or attestation, not the source after
it is no longer needed.

- On AL rejection, create the rejection attestation and PM badge staff to
  review or delete the source evidence.
- If staff do not act, rejected evidence is deleted after
  `bb_rejected_document_delete_after`.
- A) *Delete source evidence after successful verification once its hash and
  decision record are stored.*
- B) Keep accepted evidence until the recipient acknowledges the award.
- C) Keep accepted evidence for a configured appeal period.

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
| Change `ba_staff` | no | no | yes | yes |
| Transfer badge ownership | no | no | yes | yes |
| Revoke achievement | no | yes | yes | yes |
| Change achievement sharing | own only | no | no | yes |

- A) *Achievements are private by default; the recipient may share them
  publicly, with an organisation or with selected users.*
- B) The issuer's default visibility applies until the recipient changes it.
- C) All verified achievements are public by default.

Sponsor-facing views may only read achievements explicitly shared with that
sponsor or made public. Badger must not derive hidden profiles or scores from
badges, categories or attestation history.

Avatar rendering may equip no more than the user's `bb_max_equipped`
achievements in total.

## Initial implementation slice

Implement one staff-verified badge, private achievement ACLs, `GDO_Attest`,
virtual cosmetic reward fields on `GDO_Achieve`, recipient acknowledgement
(`verified → claimed`) and explicit sharing. Community voting, AL verification,
document evidence, avatar layering and tag integration follow after this slice
is proven.

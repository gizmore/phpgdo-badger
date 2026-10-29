# Badger (For Humans)

Badger is a community-verifiable achievement module. Organisations(users with addresses) and Users define
badges, people can receive them, and attestations provide an auditable trail
for community or organisation confirmation.


## Methods

- Badger.Badges - Badge List
- Badger.BadgeCrud - GDO Admin/Staff Badge Editor
- Badger.Add - Add Badge with a User Account
- Badger.Edit - Edit Badge for Badge owner / staff
- Badger.Image - Image Pixel Editor
- Badger.CategoryTree - Manage Category Tree
- Badger.Achieve - Enter a flag and gain achievement.
- Badger.Flag - Generate a flag for an achievement - Respects verification Types - Uses GDT_Badge.
- Badger.AddStaff - Add a staff member to a Badge. Requires owner.


## Types

- GDT_BadgePosition - helmet, armor, shield, weapon, boots, legs (for the badgified rpg)
- GDT_BadgeTree - A visual mesh of a main badge (parent=0)


## Avatars

Beside an image, each Avatar my add up N badge item images layered.

We will override the GDO/Avatar/tpl/avatar.php to achieve this.


## Module Config

- badger_credits_per_<xp|hp|str|int|image> (Für Badger.Add)
- badger_default_<xp=1|hp=0|str=0|int=0>


## User Config


## Initial schema

- `GDO_Badge`: an issuer's badge definition and expected verification
  policy. parent_id for nested structures. is taggable. verification types
  via multiple fields. xp, hp, str, int, BadgePosition, AvatarImage
- `GDO_Achieve`: (tablename gdo_badger_achivement) an achieved badge for a Flag user, created_at, staff_since + staff_by.


## Verification Types

- gdo_badgerbadge.bb_verficication_doc - optional document boolean
- gdo_badgerbadge.bb_verification_staff - staff may elect solvers.
- gdo_badgerbadge.bb_verification_community - community driven - N solvers may elect other solvers,
- gdo_badgerbadge.bb_verification_al - prompt for the AL to check message and chat with user.
- gdo_badgerbadge.bb_verification_al_document - user prompt for the AL document part

## Achievement Process

User goes to Badger.Flag and selects an available badge, depending on the badge this will show a form with these processes.

- Staff: User enters a message to the staff. Issues a PM to all badge staff to verify the message (or doc) from a user.
- Community: User enters a message to the community. Issues a PM to all users with that achievement already to verify a message (or doc) from a user
- AL: User enters a message to the community. Issues the AL chat to verify a message (or doc) from a user. Then goes into chat mode and AL decides of reject, delete_badge and accept(sic.).


------

@Mira: You may edit below this line, take and treat my input working protocols. We will work on the contracts until we are happy and then start to impl together in small steps.


# Badger (for AL)

Badger is a community-verifiable achievement module. Users and organisations
with an address can define badges; people can earn them; and attestations
create an auditable trail for the resulting achievement.

Badger is not an interest-scoring or silent-profiling system. A badge is an
explicit claim, made visible only under the recipient's chosen sharing rules.

@Mira: Please re-read phpgdo-core sometimes.

## Product goal

A badge answers a small, verifiable question such as “completed a workshop”,
“helped at an event”, or “solved this community challenge”. The module must
make it clear:

1. who defined the badge;
2. what evidence or decision is required;
3. who attested to it;
4. whether the achievement is pending, verified, rejected, revoked, or
   expired; and
5. who is permitted to see it.

### Identity and issuer decisions

- A normal user or a verified organisation may be an issuer. An
  organisation is represented by an address-owning user account plus its
  authorised staff.

## Badge definition

Every badge definition needs at least:

- immutable ID;
- issuer and owner;
- title, short description, and optional long description;
- category and optional tags;
- icon/image and optional `GDT_BadgePosition`;
- verification type and its policy fields;
- default visibility;
- optional rewards (`xp`, `hp`, `str`, `int`);
- active / archived state;
- creation and update timestamps.

### Badge versioning decisions

- Owners may edit all fields in place, while the audit trail records the
  change. This shall be implemented in GDO/History/WithHistory.php

### Badge ownership decisions

- Ownership belongs to the issuer account; staff receive scoped rights but
  cannot transfer ownership.

## Achievement lifecycle

An achievement is separate from a badge definition. This permits a badge to
be awarded to many recipients and preserves the decision history of each
award.

| Status           | Meaning | Typical next states |
|------------------| --- | --- |
| `draft`          | Recipient started an application but has not submitted it. | `pending`, `withdrawn` |
| `pending`        | Awaiting verification or votes. | `verified`, `rejected`, `withdrawn` |
| `verified`       | Achievement is valid and may be displayed under its visibility rules. | `revoked`, `expired` |
| `rejected`       | Verification failed, with an optional reason. | `draft`, `pending` |
| `deleted_user`   |
| `deleted_badger` |
| `deleted_both`   |
| `withdrawn`      | Recipient withdrew the request. | `draft` |
| `revoked`        | A previously verified achievement was invalidated. | none, except staff correction |
| `expired`        | Time-limited badge is no longer current but remains auditable. | `pending` for renewal |

### Lifecycle decisions

- Rejections are hidden from everyone except recipient and issuer staff.

- A) *Revocation requires a reason and creates a visible attestation record
  for everyone who can see the achievement.*
- B) Revocation is private to recipient and issuer staff.
- C) Badges cannot be revoked after verification.

## Verification types

@Mira: You can merge my new input in your version, if not we have a logical conflict.

Verification policies are declared by the badge definition. More than one
policy may be required only if the badge explicitly says so.

### Staff verification

`bb_verification_staff`: (boolean) authorised badge or issuer staff approve or reject a
pending achievement.

- N (gdo_badger.bb_staff_approvals_needed=1) (and gdo_badger_achievement.ba_staff_approvals), the bb_rejector is saved then.

### Community verification

`bb_verification_community`: verified community members vote or attest.

- Require a configurable number of distinct eligible attestations; issuer
  staff cannot count twice as community votes. (distinct counter fields for approvals)

### AI verification

@Mira: Rethink with my new input

`bb_verification_ai`: an AI evaluates a recipient-provided response against a
published badge rubric.

- A) *AI returns a recommendation with rationale; staff or the recipient can
  request human review before final verification.*
- B) AI may verify automatically for low-risk badges, with an appeal path.
- C) AI is only a drafting assistant and cannot affect status.

The prompt, rubric version, model/result metadata, and recipient-visible
reason should be retained as an attestation record. AI must not request or
reveal unrelated secrets or sensitive data.

### AI plus document verification

@Mira: Update with my new input

`bb_verification_ai_document`: AI evaluates a response together with uploaded
evidence such as an image or document.

- A) *Uploads are private to recipient and authorised reviewers by default;
  AI output is a recommendation, not sole proof.*
- B) The evidence may be made public by the recipient.
- C) Documents are disallowed; only images are accepted.

Define accepted MIME types, size limits, retention period, malware scanning,
and whether evidence is deleted after verification before implementing this
type.

## Attestations and audit trail

@Mira: No Attests?, just (re)-use gdo_badger_achievement?) OK? (Brainstorm required)

Each attestation records:

- achievement ID and badge version;
- actor type (`staff`, `community`, `ai`, `system`);
- acting user or service identity;
- decision (`approve`, `reject`, `revoke`, `comment`);
- optional reason and evidence references;
- timestamp; and
- the status transition it caused, if any.

### Audit visibility decisions

@Mira: Rethink this

each badge has ACL_Relation.

Also achievement has ACL_Relation, User Settings badger_default_achievement_visibility)

each achievement is visible by user, gdo staff and badge owner/staff.

Ob approval, documnts get deleted.

## Flags and signed attestations

@Mira: Rethink:

only achievements and their states. Flags will insert an achievemnt in early state.



`Badger.Achieve` and `Badger.Flag` provide an optional challenge-style route
for issuing an achievement. A flag is an attestation transport, not a generic
password and not the sole security boundary for a badge.

`GDT_FlagFormat` must carry a versioned structured payload containing at least
issuer ID, recipient ID, badge ID, issue time, expiry or nonce, and a modern
signature/MAC.

- A) *Use canonical JSON plus HMAC-SHA-256 or an asymmetric signature; include
  an expiry and one-time nonce where replay would matter.*
- B) Use a server-side random one-time token whose hash is stored with the
  pending achievement.
- C) Support both formats per badge policy.

MD5 may be retained only for legacy compatibility and must not authenticate a
new flag format. Correct the field spelling to `recipient_id`.

## Roles and permissions

| Action | Recipient | Badge staff | Issuer owner | GDO staff |
| --- | --- | --- | --- | --- |
| View public badge definition | yes | yes | yes | yes |
| Create achievement request | own only | no | no | no |
| Withdraw own request | own only | no | no | yes |
| Approve / reject staff badge | no | scoped badges | yes | yes |
| Manage badge staff | no | no | own issuer | yes |
| Edit published badge criteria | no | no | see versioning decision | yes |
| Revoke achievement | no | scoped badges | own issuer | yes |
| Change achievement sharing | own only | no | no | yes |

The exact permission policy must be chosen before methods become public.

## Visibility and sharing

Badger must make recipient consent visible and reversible.

- A) *Each achievement is private by default. The recipient can share it
  publicly, with a named organisation, or with selected users.*
- B) Issuer-defined default visibility applies until the recipient changes it.
- C) All verified badges are public by default.

Sponsor-facing views may use only badges the recipient has explicitly shared
with that organisation or publicly. No interest score or hidden profile may be
calculated from badges, categories, or attestation history.

## Categories and tags

`GDO_BadgerCategory` represents a hierarchical category tree. Tags are a
separate many-to-many relation and must not pretend to be tree nodes.

- A) *Implement categories inside Badger and reuse the generic Tags module for
  tags when available.*
- B) Move both categories and tags entirely into a future generic Tags module.
- C) Begin with flat tags only; defer category hierarchy.

## Methods

| Method | Purpose | Primary actors |
| --- | --- | --- |
| `Badger.Badges` | Browse badge definitions and achievements allowed by visibility. | everyone |
| `Badger.BadgeCrud` | GDO admin/staff badge editor. | GDO staff |
| `Badger.Add` | Create a badge definition for an issuer account. | issuer owner |
| `Badger.Edit` | Edit a draft badge or create a new published version. | owner / authorised staff |
| `Badger.Image` | Pixel/icon editor for badge imagery. | owner / authorised staff |
| `Badger.CategoryTree` | Manage the category tree. | configured category managers |
| `Badger.Achieve` | Start or submit an achievement request, including an optional flag. | recipient |
| `Badger.Flag` | Issue a flag or signed attestation under the badge policy. | issuer / authorised service |
| `Badger.AddStaff` | Delegate scoped badge-management rights. | issuer owner |

### Method decisions

- A) *`Badger.BadgeCrud` is reserved for GDO-wide moderation; issuers use
  `Badger.Add` and `Badger.Edit` for their own badges.*
- B) One editor serves both issuer and GDO staff, with permission-filtered
  fields.
- C) Badge creation is staff-only during an initial beta.

## Types

- `GDT_BadgePosition`: `helmet`, `armor`, `shield`, `weapon`, `boots`,
  `legs`, or no position.
- `GDT_FlagFormat`: a versioned, canonical signed-attestation payload as
  described above.
- `GDT_BadgeVerificationPolicy`: validates the selected type and its policy
  fields.
- `GDT_BadgeVisibility`: validates the recipient sharing choice.

## Module configuration

Existing configuration proposal:

- `badger_credits_per_<xp|hp|str|int|image>` for `Badger.Add` costs;
- `badger_default_<xp=1|hp=0|str=0|int=0>` for badge reward defaults.

Configuration still needed:

- maximum image/document size and permitted MIME types;
- default verification policy and vote threshold;
- default achievement visibility;
- staff-review and flag expiry durations;
- whether organisation issuers need approval;
- rate limits for applications, flags, and community attestations.

## User configuration

- Users choose a default achievement visibility and can opt out of public
  profile display entirely. (GDT_ACLVisibility(sic?))
- Visibility is configured badge-by-badge. Respect parent for the badge.


## Initial schema

- `GDO_BadgerCategory`: `category_id`, `parent_id`, `name`, `slug`, sort
  order, timestamps. Parent is nullable; cycle prevention is required.
- `GDO_BadgerBadge`: badge definition, issuer/owner, category, policy,
  visibility default, reward values, image/position, version, active state,
  timestamps.
- `GDO_BadgerAchieve`: recipient's badge instance, badge version, status,
  visibility, submitted/verified/revoked/expired timestamps, and optional
  evidence references.
- `GDO_BadgerAttest`: immutable decision/audit records for an
  achievement.
- `GDO_BadgerStaff`: delegated staff scope, role, granted-by, start/end time.
- `GDO_BadgerTag` and `GDO_BadgerBadgeTag`: optional flat tag relation. (No Tags in v1?)
- ––`GDO_BadgerFlag`––: only if the flag route is selected; issuer, recipient,
  badge/achievement, token or payload hash, expiry, used time, and issuer
  metadata. (No and GDO_BadgeFlag | GDT_BadgeFlag, always create an GDO_BadgerAchieve on visit Badger.Flag)

## Initial seed

Seed data should demonstrate policy, not manufacture reputation:

1. **Workshop Helper** — staff verification; issuer staff confirms event help.
2. **Community Welcome** — community verification; three distinct eligible
   attestations required.
3. **Puzzle Pathfinder** — flag or signed-attestation verification; one-time
   challenge completion.
4. **Portfolio Reflection** — AI recommendation plus human review; recipient
   may share evidence selectively.

## Open decisions

Before implementation, choose the marked A/B/C options for:

1. issuer identity and approval;
2. badge versioning and ownership;
3. staff and community thresholds;
4. AI authority and document retention;
5. flag/token format and replay protection;
6. audit and sharing visibility;
7. category/tag ownership;
8. initial creation permissions.

The first implementation should cover one staff-verified badge with a private
achievement, an immutable attestation trail, and explicit recipient sharing.
Community, AI, documents, and flags can follow once this narrow lifecycle is
proven.

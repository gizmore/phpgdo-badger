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
- Badger.Achieve - Claim a badge and manage the resulting achievement request.
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
- `GDO_Achieve`: (tablename gdo_badger_achivement) an achieved badge for a
  user, created_at, staff_since + staff_by.


## Verification Types

- gdo_badgerbadge.bb_verficication_doc - optional document boolean
- gdo_badgerbadge.bb_verification_staff - staff may elect solvers.
- gdo_badgerbadge.bb_verification_community - community driven - N solvers may elect other solvers,
- gdo_badgerbadge.bb_verification_al - prompt for the AL to check message and chat with user.
- gdo_badgerbadge.bb_verification_al_document - user prompt for the AL document part

## Achievement Process

User goes to Badger.Achieve and claims an available badge. Depending on its
verification policy, this opens the relevant request form.

- Staff: User enters a message to the staff. Issues a PM to all badge staff to verify the message (or doc) from a user.
- Community: User enters a message to the community. Issues a PM to all users with that achievement already to verify a message (or doc) from a user
- AL: User enters a message to the community. Issues the AL chat to verify a message (or doc) from a user. Then goes into chat mode and AL decides of reject, delete_badge and accept(sic.).


------

@Mira: You may edit below this line, take and treat my input working protocols. We will work on the contracts until we are happy and then start to impl together in small steps.


# Badger (For ALs)

This section is the implementation contract. It turns the human-facing module
outline above into stable names, state transitions and access rules. Build from
this document top down; do not infer behaviour from a UI alone.

Badger awards explicit, verifiable achievements. It is not a silent profiling,
interest-scoring or reputation-harvesting system. A badge is defined by an
issuer, achieved by a user and shown only through the achievement's ACL.

## Methods

Badger provides methods for defining badges, applying for them, verifying
applications and managing the resulting achievements.

| Method | Purpose | Primary actor |
| --- | --- | --- |
| `Badger.Badges` | Browse badge definitions and achievements permitted by ACL. | everyone |
| `Badger.BadgeCrud` | GDO-wide badge moderation and editing. | GDO staff |
| `Badger.Add` | Create a badge for an issuer account. | issuer owner |
| `Badger.Edit` | Edit a badge owned by the current issuer. | owner / badge staff |
| `Badger.Image` | Create or select badge imagery. | owner / badge staff |
| `Badger.CategoryTree` | Manage the Badger category hierarchy. | category managers |
| `Badger.Achieve` | Claim, submit, withdraw or inspect an achievement request. | recipient |
| `Badger.AddStaff` | Add or remove a scoped badge staff member. | issuer owner |

Claiming a badge creates or updates `GDO_BadgerAchieve` according to that
badge's verification policy; it does not directly grant verification.

### Method decisions

- A) *`Badger.BadgeCrud` is reserved for GDO staff; issuers use `Add` and
  `Edit` for their own badges.*
- B) One editor serves issuer and GDO staff, with permission-filtered fields.
- C) Badge creation is GDO-staff-only during the first beta.

## GDTs

Badger provides validation and rendering types for badge data and recipient
choices.

- `GDT_BadgePosition`: `helmet`, `armor`, `shield`, `weapon`, `boots`,
  `legs`, or no RPG position.
- `GDT_BadgeTree`: a visual tree rooted at a badge with `parent_id = 0`.
- `GDT_BadgeVerificationPolicy`: validates one or more selected verification
  modes and their policy fields.
- `GDT_BadgeVisibility`: validates a recipient's achievement ACL/sharing
  choice.

`GDT_BadgePosition` is also the avatar integration point: an avatar may layer
up to one visible item image per position over its base image. The Avatar
override must respect the achievement ACL before rendering an item.

## GDOs

Badger provides persistent badge definitions, achievements and their approval
history.

- `GDO_BadgerCategory`: hierarchical category node with `parent_id`, name,
  slug, sort order and timestamps. Prevent cycles.
- `GDO_BadgerBadge`: issuer-owned badge definition: title, descriptions,
  category, tags, image, position, verification policy, reward values,
  default visibility, version and active/archived state.
- `GDO_BadgerAchieve`: a recipient's badge instance: badge/version,
  recipient, current status, staff/community counters, evidence references,
  achievement ACL and lifecycle timestamps.
- `GDO_BadgerAttest`: immutable decision record for an achievement: actor,
  decision, optional reason/evidence reference, timestamp and resulting state
  transition.
- `GDO_BadgerStaff`: issuer- or badge-scoped role, granted-by, start/end time.
- `GDO_BadgerTag` and `GDO_BadgerBadgeTag`: optional tag relation; categories
  remain a Badger tree.

### Achievement lifecycle

`GDO_BadgerAchieve` is the current state of one recipient's attempt. It is
separate from `GDO_BadgerBadge`, so a badge may be achieved repeatedly without
losing the decision history.

| Status | Meaning | Typical next states |
| --- | --- | --- |
| `draft` | Recipient started but has not submitted an application. | `pending`, `withdrawn` |
| `claimed` | Recipient claimed an available badge; verification begins from this request. | `pending`, `withdrawn` |
| `pending` | Awaiting staff, community, AI or document verification. | `verified`, `rejected`, `withdrawn` |
| `verified` | Valid achievement, display controlled by its ACL. | `revoked` |
| `rejected` | Verification failed; reason is recipient/issuer-staff visible. | `draft`, `pending` |
| `withdrawn` | Recipient stopped the request. | `draft` |
| `revoked` | A verified achievement was invalidated. | staff correction only |

Deletion is not a verification state. Recipient or issuer removal changes the
achievement ACL/visibility while preserving the minimum necessary audit trail.

### Attestations and audit trail

Every approval, rejection, revocation, community vote, AI recommendation and
system transition creates a `GDO_BadgerAttest` row. `GDO_BadgerAchieve` stores
the current counters and status; it does not replace the history.

Each attestation records achievement ID, badge version, actor type
(`staff`, `community`, `ai`, `system`), actor identity, decision, optional
reason/evidence reference, timestamp and transition.

- A) *Revocation requires a reason and creates an attestation visible to every
  principal allowed to view the achievement.*
- B) Revocation history is visible only to recipient and issuer staff.
- C) Verified achievements cannot be revoked.

## Verification policies

Badge definitions declare their verification policy. Multiple policies may be
required only when the badge explicitly requires all of them.

### Staff verification

`bb_verification_staff` permits authorised badge or issuer staff to approve or
reject a pending achievement. `bb_staff_approvals_needed` defines the required
number of distinct approvals; `GDO_BadgerAchieve` stores the current approval
counter and final rejector when applicable.

### Community verification

`bb_verification_community` permits eligible users who already hold the badge
to attest to an application. Store distinct approver IDs, not only a counter;
issuer staff never count twice as community voters.

### AI verification

`bb_verification_ai` holds prompt that is given to the AL together with the message (and maybe document)
rubric. Store the rubric/prompt version, model metadata, recipient-visible
rationale and the AI result as an attestation. Never request unrelated secrets
or reveal sensitive data.

- AI may automatically verify explicitly low-risk badges with an appeal. (option bb_approve_ai)


### AI document verification

`bb_verification_ai_document` Additional prompt for an AL mode with a document.

- Accept approved MIME types with size limits; retain a reference/hash and
  delete source documents on accept.

## ACL, roles and visibility

Each badge definition and achievement has an `ACL_Relation`. A user's
`badger_default_achievement_visibility` seeds new achievement ACLs. Recipient,
GDO staff and badge owner/staff always retain the access required to operate
the lifecycle.

| Action | Recipient | Badge staff | Issuer owner | GDO staff |
| --- | --- | --- | --- | --- |
| View public badge definition | yes | yes | yes | yes |
| Create / withdraw own request | own only | no | no | yes |
| Approve / reject scoped request | no | yes | yes | yes |
| Community-attest eligible request | policy dependent | policy dependent | policy dependent | yes |
| Manage badge staff | no | no | own issuer | yes |
| Edit badge criteria | no | scoped draft only | own issuer | yes |
| Revoke achievement | no | scoped badges | own issuer | yes |
| Change achievement sharing | own only | no | no | yes |

- A) *Achievements are private by default; the recipient may share them
  publicly, with an organisation or with selected users.*
- B) The issuer's default visibility applies until the recipient changes it.
- C) All verified achievements are public by default.

Sponsor-facing views may only read achievements explicitly shared with that
sponsor or made public. Badger must not derive hidden profiles or scores from
badges, categories or attestation history.

## Configuration

Module configuration:

- `badger_credits_per_<xp|hp|str|int|image>`: creation cost for `Badger.Add`.
- `badger_default_<xp=1|hp=0|str=0|int=0>`: default rewards for new badges.
- default verification policy, approval/vote threshold and review timeout.
- default achievement visibility and issuer-approval requirement.
- image/document MIME, size, malware scanning and retention settings.
- rate limits for claims, requests and community attestations.

User configuration:

- `badger_default_achievement_visibility` supplies the ACL for new
  achievements.
- Users may opt out of public profile display and override visibility per
  achievement.

## Initial implementation slice

Start with one staff-verified badge, a private achievement ACL,
`GDO_BadgerAttest`, recipient sharing and the methods needed for that narrow
lifecycle. Community voting, AI, document evidence, avatar layering and tag
integration follow after this slice is proven.

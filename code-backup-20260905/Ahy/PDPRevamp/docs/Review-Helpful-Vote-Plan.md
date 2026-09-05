# Review "Helpful" Vote — Fix Plan

**Status:** planned, not implemented
**Scope:** `Ahy_PDPRevamp` only
**Date:** 2026-08-24

Requirement: **only logged-in customers may vote**, and a vote must survive a page
reload so the same customer cannot vote repeatedly on the same review.

---

## 1. The problem

The "Helpful (N)" button on the PDP reviews section
(`view/frontend/templates/product/view/yotpo_reviews.phtml`) can be clicked
without limit. Refresh the page and the same visitor — logged in or not — can
vote again on the same review.

The cause is that vote state is stored **only in a CSS class**. In
`view/frontend/templates/product/view/options/yotpo/yotpo_js.phtml`
(`reviewFeedback()`):

```js
const svg = parentNodeEl.querySelector(`#vote-${add_vote_string}-${review_id}`);
const isAlreadyVoted = svg.classList.contains("fill-current");
```

A filled thumb icon *is* the entire record of having voted. Nothing is persisted
in the database, in `localStorage`, in a cookie, or against the customer account,
so a page load wipes it.

Verified: `etc/db_schema.xml` has no vote table or column, and
`Controller/Index/Vote.php` performs no database writes at all.

### Why Yotpo cannot solve this for us

Votes are sent to Yotpo, and Yotpo does store the count — but it cannot
deduplicate:

- The request carries **only** `review_id` and `vote_type` (plus an optional
  `/true` for unvote). **No customer identifier is sent**, and no app key or
  secret either — just an `Accept` header. Yotpo has no idea which shopper voted.
- Yotpo returns **`200 OK` unconditionally**. Probed against the live API:

  | Request | Response |
  |---|---|
  | `POST /reviews/999999999/vote/up` (nonexistent id) | `{"status":{"code":200,"message":"OK"}}` |
  | `POST /reviews/abc-not-a-real-id/vote/up` | `200 OK` |
  | `POST /reviews/999999999/vote/sideways` (invalid type) | `200 OK` |

  So the response is useless as a success signal — the code cannot tell an
  accepted vote from a discarded one.
- The response contains **no updated vote total**, which is why the current code
  increments the displayed count optimistically.

### Division of responsibility in the fix

| Concern | Owner |
|---|---|
| How many votes a review has (`votes_up`) | **Yotpo** — remains source of truth |
| Which customer already voted | **Us** — new local table |

Keeping the count in Yotpo avoids two totals that drift apart. We only record
*who*, so the controller can refuse a second vote before forwarding to Yotpo.

---

## 2. Current data flow

```
click "Helpful"
  └─ reviewFeedback(1, review, event)          yotpo_js.phtml:241
       └─ POST /ahyyotpo/index/vote            Controller/Index/Vote.php
            └─ YotpoClient::voteReview()       Service/YotpoClient.php:589
                 └─ POST https://api.yotpo.com/reviews/{id}/vote/up
                      └─ Yotpo increments votes_up on its own record
```

Read path (how the number gets back):

```
GET /ahyyotpo/index/reviews?product_id=X       Controller/Index/Reviews.php
  └─ YotpoClient::getReviews()
       └─ GET api-cdn.yotpo.com/v1/widget/{appKey}/products/X/reviews.json
            └─ cached 15 min, tag "ahy_yotpo_api"
                 └─ rendered as "Helpful (N)" via x-html="review.votes_up"
```

---

## 3. Fix — six parts

### 3.1 Schema: new table

`etc/db_schema.xml`. Follows the existing `ahy_pdprevamp_review_tags` pattern,
including its `varchar(64)` review-id convention (which already accommodates both
Yotpo ids and `native_<id>` for the native-review fallback).

```xml
<table name="ahy_pdprevamp_review_vote" resource="default" engine="innodb"
       comment="Records which customer voted on which review, so a vote cannot be repeated">
    <column xsi:type="int" name="vote_id" unsigned="true" nullable="false" identity="true"/>
    <column xsi:type="varchar" name="review_id" nullable="false" length="64"/>
    <column xsi:type="int" name="customer_id" unsigned="true" nullable="false"/>
    <column xsi:type="varchar" name="vote_type" nullable="false" length="4" comment="up|down"/>
    <column xsi:type="timestamp" name="created_at" nullable="false" default="CURRENT_TIMESTAMP"/>
    <constraint xsi:type="primary" referenceId="PRIMARY">
        <column name="vote_id"/>
    </constraint>
    <constraint xsi:type="unique" referenceId="AHY_PDPREVAMP_REVIEW_VOTE_REVIEW_CUSTOMER">
        <column name="review_id"/>
        <column name="customer_id"/>
    </constraint>
    <index referenceId="AHY_PDPREVAMP_REVIEW_VOTE_REVIEW_ID" indexType="btree">
        <column name="review_id"/>
    </index>
    <constraint xsi:type="foreign" referenceId="AHY_PDPREVAMP_REVIEW_VOTE_CUSTOMER_ID"
                table="ahy_pdprevamp_review_vote" column="customer_id"
                referenceTable="customer_entity" referenceColumn="entity_id"
                onDelete="CASCADE"/>
</table>
```

Three deliberate choices:

- **Unique constraint on `(review_id, customer_id)`** — one-vote-per-customer is
  enforced by the database, not by application logic. Two simultaneous requests
  cannot both pass a `SELECT`-then-`INSERT` check; the second fails on duplicate
  key and is handled as "already voted".

  Note this is keyed on the **pair, not on `vote_type`** — so a customer holds at
  most one vote per review and switching direction *replaces* it, rather than
  allowing an up and a down vote simultaneously. See §3.3 for the switch flow.
- **`vote_type` column, not a boolean** — stores `up` or `down`, so the current
  direction is known and the UI can highlight the correct icon after a reload.
- **`onDelete="CASCADE"`** — deleting a customer removes their votes, leaving no
  orphan rows.

Also add the corresponding entry to `etc/db_schema_whitelist.json`.

### 3.2 Resource model

`Model/ResourceModel/ReviewVote.php`, shaped like the module's existing
`Model/ResourceModel/VariantColor.php` (direct `ResourceConnection`, no full
CRUD model):

| Method | Purpose |
|---|---|
| `getVote(string $reviewId, int $customerId): ?string` | returns `'up'`, `'down'`, or `null` — direction matters now, not just existence |
| `getVotesByReviewIds(array $reviewIds, int $customerId): array` | **batched** map of `review_id => vote_type`; rendering N reviews costs one query, not N |
| `saveVote(string $reviewId, int $customerId, string $voteType): void` | `insertOnDuplicate` so a direction switch overwrites the existing row in one statement |
| `removeVote(string $reviewId, int $customerId): void` | for unvote |

`saveVote()` uses `insertOnDuplicate` (the same approach as the module's existing
`VariantColor::saveHex()`) rather than delete-then-insert: switching direction is
then a single atomic statement that cannot race against itself.

### 3.3 Controller: the auth gate

`Controller/Index/Vote.php` — inject `Magento\Customer\Model\Session` and reject
anonymous requests before anything else:

```php
if (!$this->customerSession->isLoggedIn()) {
    return $result->setHttpResponseCode(401)->setData([
        'success'        => false,
        'requires_login' => true,
        'message'        => __('Please sign in to vote on reviews.'),
    ]);
}
```

Then, **before** calling Yotpo, branch on the customer's existing vote
(`getVote()` returns `'up'`, `'down'`, or `null`). With a working down-vote there
are four cases:

| Existing | Clicked | Action |
|---|---|---|
| `null` | up / down | **New vote.** Save row, call Yotpo `vote/{type}`. |
| `up` | up | **Unvote.** Delete row, call Yotpo `vote/up/true`. |
| `down` | down | **Unvote.** Delete row, call Yotpo `vote/down/true`. |
| `up` | down (or vice versa) | **Switch.** Two Yotpo calls: `vote/up/true` to retract the old, then `vote/down` to apply the new. `saveVote()` overwrites the row. |

The switch case is the one worth attention: Yotpo has no "change my vote"
operation, so the old vote must be explicitly retracted or its count stays
inflated. Both Yotpo calls must be issued, in that order.

- **Ordering:** write locally *first*. If the local write fails, Yotpo's count is
  untouched; if the Yotpo call then fails, roll the local row back.
- The response returns the resulting `vote_type` (or `null` after an unvote) so
  the front end sets its state from the server rather than guessing.

This also substantially addresses the security finding that this endpoint is
CSRF-exempt with no auth or rate limiting — an unauthenticated POST is now
refused outright, so it ceases to be an open vote-stuffing target. A form-key
check is still worth adding, but the exposure shrinks enormously.

### 3.4 Return real vote state to the browser

**This part is required by full-page caching.** Varnish is enabled on this
install (`system/full_page_cache/caching_application = 1`), so per-customer state
must never be rendered into cached HTML — the first visitor's votes would be
served to everyone.

Reviews already load client-side through `ahyyotpo/index/reviews`, which is the
FPC-safe channel. So `Controller/Index/Reviews.php`, **after** `YotpoClient`
returns, stamps each review for the current customer:

```php
// 'up', 'down', or null — not a boolean, so the correct icon can be
// highlighted after a reload.
$reviews[$i]['my_vote'] = $votes[(string) $review['id']] ?? null;
```

and adds `'can_vote' => $isLoggedIn` at the response root.

**`votes_down` does not currently exist anywhere.** A repo-wide search found
`votes_up` only — the native-review fallback in `YotpoClient` emits
`'votes_up' => 0` and no down-vote field at all. So for the down-vote count:

- Read it as `review.votes_down ?? 0` on the front end, so a payload without the
  field degrades to zero rather than rendering `undefined`.
- Add `'votes_down' => 0` to the native fallback array
  (`YotpoClient::getNativeReviewsFallback()`) for shape parity with Yotpo.
- Whether Yotpo's widget API actually returns `votes_down` for this account is
  **unconfirmed** — it cannot be checked locally, because this install's reviews
  endpoint returns `total_review: 0` for every product. **Verify on dev by
  inspecting the raw `ahyyotpo/index/reviews` JSON** before relying on the
  displayed down-count. If the field is absent, the down-vote still records and
  retracts correctly; only its running total would need sourcing elsewhere.

**Critical detail:** the stamping happens *after* `YotpoClient::getReviews()`
returns, never inside it. The shared 15-minute `ahy_yotpo_api` cache therefore
stays customer-agnostic and no per-customer data ever enters it.

### 3.5 Template and JS

`yotpo_reviews.phtml` — drive both icons from data instead of a CSS class, and
render the down-vote button that never existed:

```html
<!-- Helpful (up) -->
<button :class="review.my_vote === 'up' ? 'text-ahy-rating-green' : 'text-gray-500'"
        @click="reviewFeedback(1, review, $event)">
    <svg :class="review.my_vote === 'up' ? 'fill-current' : ''" ...>
    <span>Helpful (<span x-text="review.votes_up ?? 0"></span>)</span>
</button>

<!-- Not helpful (down) - new; the existing thumb-up SVG rotated 180deg -->
<button :class="review.my_vote === 'down' ? 'text-ahy-blue' : 'text-gray-500'"
        @click="reviewFeedback(0, review, $event)">
    <svg :class="review.my_vote === 'down' ? 'fill-current' : ''" ...>
    <span x-text="review.votes_down ?? 0"></span>
</button>
```

The down button reuses the existing thumb-up path rotated 180°, so no new icon
asset is needed. Both keep their `:id="'vote-up-' + review.id"` /
`'vote-down-' + review.id` attributes — `reviewFeedback()` already queries those
selectors, so the dead branch becomes live with no rename.

`yotpo_js.phtml` — rework `reviewFeedback()`:

- Read `review.my_vote` rather than `svg.classList.contains('fill-current')`.
- Handle a `401` response by showing the sign-in prompt.
- Adjust counts **only when the server confirms**, and revert on failure. The
  current code increments inside `.then()` regardless of outcome, so the display
  drifts whenever a vote is actually discarded.
- **Update both counts on a direction switch** — decrement the old, increment the
  new. The current code only ever touches `votes_up`, so a switch would leave the
  down-count stale.
- Set `review.my_vote` from the response so Alpine re-renders reactively.
- Remove the manual `classList.toggle()` calls: state now flows from data, and
  leaving them in would fight the Alpine bindings.

### 3.6 Cache invalidation

Because Yotpo returns no updated total and its `200` is meaningless, invalidate
that product's cached reviews entry after a successful vote. The next page load
then pulls Yotpo's real number instead of trusting an optimistic guess for up to
15 minutes.

Requires the vote payload to include `product_id` (already available in the JS).

---

## 4. Logged-out experience

**Decided: inline prompt, not a redirect.**

Clicking "Helpful" while signed out shows a "Sign in to vote" message with a link.
The vote count stays visible either way, so the review's social proof is not lost.

Rationale: the reviews section sits far down the PDP, and a redirect to login
loses the reader's scroll position and place in the list.

Note on the wishlist comparison: Magento gates wishlist **server-side in its
controller** (`Magento\Wishlist\Controller\Index\Add`), not in the template —
`addtowishlist.phtml` contains no `isLoggedIn` check. So the auth-in-controller
approach above *is* the wishlist pattern; only the UX response differs.

---

## 5. Files touched

All within `app/code/Ahy/PDPRevamp/`:

| File | Action |
|---|---|
| `etc/db_schema.xml` | add `ahy_pdprevamp_review_vote` table |
| `etc/db_schema_whitelist.json` | whitelist the new table |
| `Model/ResourceModel/ReviewVote.php` | **new** — vote CRUD |
| `Controller/Index/Vote.php` | auth gate + duplicate prevention |
| `Controller/Index/Reviews.php` | stamp `my_vote` / `can_vote` |
| `Service/YotpoClient.php` | add `'votes_down' => 0` to the native-review fallback for shape parity |
| `view/frontend/templates/product/view/yotpo_reviews.phtml` | data-driven icon state, **new down-vote button**, sign-in prompt |
| `view/frontend/templates/product/view/options/yotpo/yotpo_js.phtml` | rework `reviewFeedback()` incl. the switch case |

No core files, no `vendor/`, no theme files.

---

## 6. Verification

### Can be verified locally

1. `setup:upgrade` runs clean; `ahy_pdprevamp_review_vote` exists with the unique
   constraint.
2. Anonymous `POST /ahyyotpo/index/vote` returns **401** and does not reach Yotpo.
3. Duplicate-vote and direction-switch logic exercised directly against the
   resource model, including that the unique constraint rejects a second row.
4. `my_vote` / `can_vote` present in the `ahyyotpo/index/reviews` JSON.
5. PHP/JS syntax checks; no fatals on the PDP.

### Must be verified on dev

The rendered behaviour cannot be checked locally: this install's reviews endpoint
returns `total_review: 0` for every product, so there are no reviews to vote on.

1. Logged out → both buttons show the sign-in prompt; counts still visible.
2. Logged in → up-vote fills the thumb and increments Helpful.
3. **Reload the page → the icon stays filled** (the actual bug being fixed).
4. Clicking the same button again retracts the vote and decrements.
5. **Switch direction** → up-count decrements *and* down-count increments; only
   one icon is filled afterwards.
6. Reload after a switch → the down icon is the filled one.
7. A second customer can vote on the same review independently.
8. Vote counts survive the 15-minute cache window and match Yotpo.
9. **Inspect the raw reviews JSON** to confirm whether Yotpo returns `votes_down`
   for this account (see §3.4).

---

## 7. Decisions taken

1. **Logged-out UX — inline prompt.** Clicking either button while signed out
   shows a "Sign in to vote" message with a link. No redirect, so the reader keeps
   their scroll position in the reviews list.
2. **Down-vote — wire up a working button.** `reviewFeedback()` already handles
   `vote_type === 0` and queries `#vote-down-{id}`; the missing piece was the
   button itself, plus count handling for the down direction and the switch case.

Both are reflected throughout §3.

---

## 8. Known limitations

- **Existing inflated counts are not corrected.** Counts already stored in Yotpo
  (e.g. the test reviews on dev showing `Helpful (5)`) stay as they are. Only new
  votes are gated.
- **Votes cannot be verified as accepted by Yotpo.** Yotpo answers `200 OK` to
  everything, so "success" means "the request was sent", not "the vote was
  counted". The cache invalidation in §3.6 is the mitigation: the real number is
  re-fetched rather than assumed.
- **Guests cannot vote at all.** This is the requested behaviour, but it does
  reduce vote volume, since most PDP traffic is anonymous.
- **One vote per review per customer, not per vote type.** The unique constraint
  is on `(review_id, customer_id)`, so a customer holds at most one vote per
  review; switching direction replaces it. This is intentional — holding a
  simultaneous up *and* down vote on the same review is not meaningful.
- **`votes_down` availability is unconfirmed.** The field appears nowhere in the
  codebase today, and whether Yotpo's widget API returns it for this account
  cannot be checked locally (no reviews exist here). If it turns out to be absent,
  the down-vote still records and retracts correctly — only its running total
  would show `0`. Confirm on dev per §6, test 9.
- **A direction switch makes two Yotpo calls.** Yotpo has no "change vote"
  operation, so retract-then-apply is required. If the second call fails after the
  first succeeded, the customer's vote is retracted but not re-applied; the local
  row is rolled back so the UI stays consistent with our record, and the next
  cache refresh reconciles the count from Yotpo.

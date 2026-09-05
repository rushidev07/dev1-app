# Review "Helpful" Vote — Testing Checklist

Companion to `Review-Helpful-Vote-Plan.md`. Covers the logged-in-only vote with
persistence and the new down-vote button.

**Environment:** must be tested on **dev** (`everest.ahydev.com`). Local cannot
be used for the UI tests — every product there returns `total_review: 0` from the
reviews API, so no reviews render and there is nothing to vote on.

---

## 0. Pre-flight

| # | Check | Expected |
|---|---|---|
| 0.1 | `bin/magento setup:upgrade` run after deploy | completes clean |
| 0.2 | Table exists: `SHOW TABLES LIKE 'ahy_pdprevamp_review_vote';` | one row returned |
| 0.3 | Constraints present (query below) | `PRIMARY`, one `UNIQUE`, one `FOREIGN KEY` |
| 0.4 | **Theme layout points at the module template** (see §6) | `Ahy_PDPRevamp::...yotpo_js.phtml` |
| 0.5 | `bin/magento cache:clean layout block_html full_page` | done |
| 0.6 | Pick a product with **at least 2 Yotpo reviews** | note its URL |

```sql
SELECT constraint_name, constraint_type
FROM information_schema.table_constraints
WHERE table_name = 'ahy_pdprevamp_review_vote';
```

Two customer accounts are needed for §3. Call them **A** and **B**.

---

## 1. Logged-out behaviour

| # | Step | Expected |
|---|---|---|
| 1.1 | Open the PDP signed out, scroll to Ratings & Reviews | reviews render; both Helpful and down-vote buttons visible |
| 1.2 | Vote counts shown | real numbers, not blank or `undefined` |
| 1.3 | Click **Helpful** | inline red message "Please sign in to vote on reviews." + **Sign in** link. **No** redirect. |
| 1.4 | Neither count changes | counts unchanged |
| 1.5 | Click the **down-vote** | same inline prompt |
| 1.6 | Click the **Sign in** link | goes to `customer/account/login` |
| 1.7 | Network tab → the vote request | **HTTP 401**, body `{"success":false,"requires_login":true,...}` |
| 1.8 | Reviews API response (`ahyyotpo/index/reviews`) | `"can_vote": false`, every review `"my_vote": null` |
| 1.9 | DB after all the above | `SELECT COUNT(*) FROM ahy_pdprevamp_review_vote;` → unchanged |

**1.7 is the important one:** it proves Yotpo was never contacted for a guest.

---

## 2. Logged-in: vote, persist, retract

Sign in as **customer A**.

| # | Step | Expected |
|---|---|---|
| 2.1 | Reviews API response | `"can_vote": true` |
| 2.2 | Click **Helpful** on review #1 | thumb fills green, count **+1**, no error |
| 2.3 | Network → vote request | **HTTP 200**, `{"success":true,"my_vote":"up"}` |
| 2.4 | DB | one row: `review_id` = that review, `customer_id` = A, `vote_type` = `up` |
| 2.5 | **Reload the page** | **thumb is still filled** and the count still includes your vote |
| 2.6 | Reviews API after reload | that review has `"my_vote": "up"` |
| 2.7 | Click **Helpful** again (same review) | thumb empties, count **-1** |
| 2.8 | DB | row **deleted** |
| 2.9 | Reload | thumb unfilled |

**2.5 is the bug being fixed.** Before this change the icon reset on every reload
and the same person could vote again indefinitely.

---

## 3. Down-vote and direction switching

Still signed in as **A**.

| # | Step | Expected |
|---|---|---|
| 3.1 | Click the **down-vote** on review #2 | down icon fills (blue), down count **+1** |
| 3.2 | DB | one row, `vote_type` = `down` |
| 3.3 | Reload | **down icon** is the filled one, not the up icon |
| 3.4 | Now click **Helpful** on the same review (switch) | up count **+1**, down count **-1**; only the up icon filled |
| 3.5 | DB | still **exactly one row**, `vote_type` now `up` |
| 3.6 | Reload | up icon filled |
| 3.7 | Click **down** again (switch back) | counts swap back; one icon filled |

**3.5 is the key assertion** — a switch must replace the vote, never leave the
customer holding both an up and a down vote.

---

## 4. Multi-customer isolation

| # | Step | Expected |
|---|---|---|
| 4.1 | As **A**, up-vote review #3 | count +1 |
| 4.2 | Sign out, sign in as **B** | — |
| 4.3 | View the same review | count includes A's vote, but **B's icons are unfilled** |
| 4.4 | As **B**, up-vote the same review | count **+1** again (now +2 total) |
| 4.5 | DB | **two rows** for that `review_id`, different `customer_id` |
| 4.6 | Sign back in as **A**, reload | A's icon filled, count still reflects both |

4.3 is the full-page-cache check: if B ever sees A's filled icon, per-customer
state has leaked into the cached page.

---

## 5. Error handling and edge cases

| # | Step | Expected |
|---|---|---|
| 5.1 | Vote, then immediately click again before the request finishes | second click ignored (buttons disabled while `votePending`) |
| 5.2 | Sign out in a second tab, then vote in the first | inline sign-in prompt appears; **count reverts** to its previous value |
| 5.3 | DevTools → block the vote URL, then click | count reverts, no stuck state |
| 5.4 | Malformed request by hand: `{"review_id":"","vote_type":"sideways"}` | **HTTP 400** |
| 5.5 | Valid-looking request as a guest via curl | **HTTP 401**, no DB row, no Yotpo call |
| 5.6 | Product with **no** reviews | section hidden as before; no JS errors |
| 5.7 | Browser console throughout | no errors |

```bash
# 5.5
curl -i -X POST -H "Content-Type: application/json" \
  -d '{"review_id":"123","vote_type":"up","product_id":456}' \
  https://everest.ahydev.com/ahyyotpo/index/vote
```

---

## 6. Deploy-specific checks

| # | Check | Why |
|---|---|---|
| 6.1 | Theme layout `catalog_product_view.xml` points `product.yotpo.review.js` at `Ahy_PDPRevamp::...` | **The theme declared its own copy of `yotpo_js.phtml` and, because theme layout merges after module layout, silently overrode the module's version.** Without this one-line change none of the vote code reaches the page. |
| 6.2 | `grep -c isAlreadyVoted` on the rendered page → **0** | confirms the old template is no longer being served |
| 6.3 | `grep -c canVote` on the rendered page → **> 0** | confirms the new template *is* being served |
| 6.4 | Stale theme template deleted once 6.2/6.3 pass | `app/design/frontend/Ahy/Everest2/Magento_Catalog/templates/product/view/options/yotpo/yotpo_js.phtml` is now dead code (it had diverged ~796 lines from the module's) |
| 6.5 | Generated DI cleared if a "Too few arguments" error appears | both controllers gained constructor arguments; a stale interceptor throws until `generated/` is cleared |

---

## 7. Known limitations — not bugs

Do not raise these as defects:

| Behaviour | Why |
|---|---|
| Pre-existing counts look inflated | Those votes are already stored in Yotpo from before this change. Only new votes are gated. |
| Down-vote count may always read `0` | `votes_down` appears nowhere in the codebase, and whether Yotpo's widget API returns it for this account is **unverified** — it could not be checked locally. **Confirm by inspecting the raw `ahyyotpo/index/reviews` JSON.** If absent, votes still record and retract correctly; only the running total shows 0. |
| Count may lag briefly | Reviews are cached 15 minutes. A vote clears that product's cache entry, so the corrected number appears on the next load. |
| "Success" cannot be strictly verified | Yotpo answers `200 OK` to everything — including nonexistent review ids and `vote/sideways`. A `200` means "sent", not "counted". |
| Guests cannot vote at all | Intended. Reduces vote volume, since most PDP traffic is anonymous. |

---

## 8. Sign-off

The feature is working when all of these hold:

- [ ] Guests get the inline prompt and a `401`; no DB row is written
- [ ] A logged-in customer can vote once per review
- [ ] **The vote survives a page reload** (§2.5)
- [ ] Clicking the same direction retracts it
- [ ] Switching direction leaves exactly one row (§3.5)
- [ ] Two customers vote independently and never see each other's state (§4)
- [ ] Failed requests revert the count rather than leaving it wrong
- [ ] No console errors, no PHP exceptions

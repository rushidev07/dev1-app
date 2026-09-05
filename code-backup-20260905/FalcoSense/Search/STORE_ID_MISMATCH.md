# FalcoSense Search: platform_store_id vs Magento store_id mismatch

**Status:** Deferred — not blocking the current products-not-loading (401) fix. Revisit before onboarding any multi-store-view client.

## The problem

`SearchTokenService::getToken(int $magentoStoreId)` uses its parameter for two things:
1. Magento config scope lookups — `Helper\Data::getApiKey($storeId)` / `getEndpointUrl($storeId)`, both via `ScopeInterface::SCOPE_STORE`.
2. The token cache filename — `/tmp/smartsearch_token_{storeId}.json`.

It is designed to receive a real **Magento store ID**. Several call sites instead pass `Helper\Data::getPlatformStoreId()`, which is a *different, unrelated* number.

## What getPlatformStoreId() actually returns

`app/code/FalcoSense/Search/Helper/Data.php`:

```php
public function getPlatformStoreId(int|string|null $storeId = null): int
{
    // Dynamic calculation matching FourSeasons approach
    $stores   = $this->storeManager->getStores(false);
    $storeIds = array_keys($stores);
    sort($storeIds);

    if ($storeId === null) {
        $storeId = (int) $this->storeManager->getStore()->getId();
    }

    $position = array_search((int) $storeId, $storeIds);
    if ($position !== false) {
        return (int) $position + 1;
    }

    // Fallback to config value
    return (int) ($this->config->getValue(self::XML_PATH_PLATFORM_STORE, ScopeInterface::SCOPE_STORE, $storeId) ?: 1);
}
```

This is **not** read from the platform's API in the normal path. It's a locally recomputed **ordinal position** — "you're the Nth active Magento store," sorted by store_id, 1-indexed — recalculated fresh on every call from whatever stores currently exist.

There is a genuine API-issued value stored in config (`smart_search/general/platform_store_id`, `XML_PATH_PLATFORM_STORE`), presumably set during onboarding. But it's only used as a **fallback**, reached only when `array_search()` fails to find the store in the local list — which basically never happens for a real, active store. So the actual platform-issued value is effectively dead code today.

## Why it "works" on dev1 right now

`bin/magento store:list` on dev1 shows exactly one store, id=1. Position-among-active-stores for a single-store site is always `1`, and Magento's real store_id is also `1` — so the wrong value and the right value coincide. Nothing looks broken.

## Where it's wired wrong (verified by grep across the module)

**Correct** — pass the real Magento store ID via `$this->_storeManager->getStore()->getId()`:
- `Block/Category.php:44`
- `Block/Search.php:48`

**Wrong** — pass `Helper\Data::getPlatformStoreId()` into something that expects a Magento store ID:
- `view/frontend/templates/html/header/search-form.phtml:27-28` (loaded on every page)
- `view/frontend/templates/modal/config-modal.phtml:36-37`
- `Controller/Suggest/Index.php:24-25` (autocomplete)

**Dead/misleading code** — template passes an argument the method signature doesn't accept, so it's silently discarded by PHP (harmless in effect, but reads like it does something it doesn't):
- `view/frontend/templates/category/results.phtml:4` — `$block->getSearchToken((int) $block->getPlatformStoreId())`, but `Category::getSearchToken()` takes zero parameters.
- `view/frontend/templates/search/results.phtml:5` and `search/autocomplete.phtml:4` — same pattern against `Block\Search::getSearchToken()`.

## Why it will break on a multi-store-view client

- Position and Magento `store_id` diverge as soon as there's more than one store view, or stores are added/removed/reordered (position is **not stable over time**, either).
- `Helper\Data::getApiKey($position)` would scope config lookup to whatever Magento store literally has `store_id == $position` — which may be a completely different store view, or none (silently falls back to default scope).
- Cache files keyed by position (`search-form.phtml`, `config-modal.phtml`, `Suggest/Index.php`) won't match cache files keyed by real store_id (`Category.php`, `Block/Search.php`) for the same store — header search and category browsing end up on two disconnected token caches.

## Proposed fix (not yet implemented)

- In `search-form.phtml`, `config-modal.phtml`, and `Suggest/Index.php`: replace `$smartSearchHelper->getPlatformStoreId()` with the real Magento store ID (`$storeManager->getStore()->getId()`) wherever the value feeds into `SearchTokenService`, `Helper::getApiKey()`, or `Helper::getEndpointUrl()`.
- `platform_store_id` should be used **only** as a literal query parameter sent *to* the platform's product/search API — never fed into anything that resolves Magento config scope or builds a cache key.
- Separately, confirm with the team/platform side what the *actual* per-store platform IDs are supposed to be (the user noted actual store IDs and store numbers differ in production) — the `XML_PATH_PLATFORM_STORE` config fallback path may need to become the primary path instead of the position-based calculation, once that's confirmed.
- Clean up the three dead-argument call sites (`results.phtml`, `search/results.phtml`, `autocomplete.phtml`) to stop passing an argument `getSearchToken()` doesn't accept — either drop the argument or give the block method a real parameter if per-call override is actually needed.

## Context recovered during investigation

The module (`app/code/FalcoSense/Search`) is **entirely untracked in git** on dev1 (`git status` shows the whole directory as untracked), so there's no commit history to consult for how this was originally intended to work.

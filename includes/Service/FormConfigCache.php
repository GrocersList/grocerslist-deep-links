<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;
use GrocersList\Support\PageCachePurger;
use GrocersList\Support\RefreshLock;
use GrocersList\Support\RequestFinisher;

/**
 * The GRO form configs (GL_ListGrowthFormConfig) this site renders, and the
 * creator's list of forms, cached so that no page waits on GRO for a form it
 * has a copy of.
 *
 * Each is one transient — grocerslist_form_<hash> per form, and
 * grocerslist_forms_list — holding { body, etag, loadedAt, fetchedAt,
 * checkedAt, status }. Every one lives for STALE_FOR (24 h), whatever it
 * holds, bar a placeholder stored for a key nothing was stored under by a
 * read no page was waiting on (placeholderTtl()); the shorter rules are read
 * off the timestamps inside it:
 *
 * - checked with GRO under FRESH_FOR (60 s) ago: served as it is. That
 *   includes a "gone" answer and a failed request, so an archived form or an
 *   outage costs GRO one request a minute, not one per page view.
 * - checked longer ago: still served at once, and refreshed after the
 *   response has gone — at shutdown, once fastcgi_finish_request() or
 *   litespeed_finish_request() has sent it (RequestFinisher); on a server
 *   with neither, by one WP-Cron event, REFRESH_HOOK, carrying the key; and
 *   inline when the request is a WP-Cron run already. The refresh asks with
 *   If-None-Match while the body is under CONFIG_TTL (300 s) old — a 304
 *   keeps it and moves fetchedAt on — and in full once it is older. GRO's
 *   ETag is a hash of the config it would send, the brand colours it
 *   resolves at read time included, so a change comes back as a 200 at the
 *   first revalidation after it; the full read is only a safety net, should
 *   an ETag ever fail to move.
 * - one refresh per key at a time: it runs under a RefreshLock, held for at
 *   most LOCK_TTL (30 s), and looks at the copy again once it holds it, so
 *   page views that served a copy stale together cost GRO one request. A
 *   store that cannot lock lets each refresh through; FRESH_FOR still keeps
 *   them to about one a minute.
 * - a page waits on GRO only for a key with nothing stored anywhere, and for
 *   a key whose refresh event has sat REFRESH_OVERDUE (300 s) unrun — WP-Cron
 *   off and no system cron in its place, or no request in that time to spawn
 *   it — both for at most COLD_TIMEOUT (2 s). GRO's hint (invalidate()) and
 *   the block editor (currentForms(), getCurrent()) wait up to
 *   ApiClient::FORM_READ_TIMEOUT (10 s), as a refresh does: no visitor waits
 *   on either, and the editor wants what GRO has now.
 * - an answer is stored only if nothing newer landed while it was on its
 *   way, as the store has it (storedEntry()): a slow answer never replaces a
 *   copy GRO sent or confirmed after it was asked, nor its final answer.
 * - GRO answering 404 (archived, or not this creator's), 401 or 403 is final:
 *   there is nothing to show.
 * - GRO unreachable, throttling or erroring: the last body GRO sent stands
 *   in, however old, until GRO answers.
 *
 * Behind each transient stands the last body GRO sent for it, with its ETag,
 * in an option that is not autoloaded — LIST_LKG_OPTION, or LKG_PREFIX and
 * the hash — which a persistent object cache cannot drop the way it can a
 * transient, so a form whose transient went still shows while GRO cannot be
 * reached. It is written with every body GRO sends (a no-op while body and
 * ETag are unchanged), read only when the transient is missing, and deleted
 * on a final answer and by a hint to a site without a key.
 *
 * One transient rather than a 300 s one plus a 24 h stale copy: a page view
 * reads one row, and one write keeps the body and its timestamps in step.
 *
 * A page cache serves its HTML without running PHP, so only GRO's hint
 * (invalidate()) reaches a page already cached: it purges the site's page
 * cache when the form's config no longer matches the fingerprint recorded
 * for it (FINGERPRINTS_OPTION). Rendering a page never purges
 * (noteFingerprint()). The one exception is GRO coming back: an answer that
 * brings a form back after GRO could not give it (a placeholder replaced by
 * a body) purges once, for the pages cached without the form meanwhile —
 * from a refresh only in the request holding its lock, and never after a
 * final answer nor on a first fetch (refresh()). A page view that asks GRO
 * itself for an overdue refresh event does not: that is a last resort.
 *
 * Every config read runs through the grocerslist_form_config filter, which is
 * how a site overrides a form — and how a local WordPress renders one without
 * GRO (docs/FORMS.md).
 */
class FormConfigCache
{
    public const LIST_TRANSIENT = 'grocerslist_forms_list';
    public const KINDS = ['SIGNUP', 'SAVE_TO_EMAIL', 'CONTENT_GATE'];

    /**
     * Each form this site has shown => md5((string) wp_json_encode($config))
     * of its config as of the last page-cache purge for it (or the first time
     * GRO sent it): what an invalidation hint compares GRO's config with.
     * Over the whole config, because GRO resolves its brand (palette colours,
     * avatar, handle) at read time and never moves its version for that; and
     * over the decoded array, not GRO's bytes or ETag, so the same config has
     * the same fingerprint however its JSON was spaced or escaped and whatever
     * ETag came with it. An option, not part of the transient, because it has
     * to outlive it — a persistent object cache can drop a transient at any
     * time, and some page-cache purges flush that cache too.
     */
    public const FINGERPRINTS_OPTION = 'grocerslist_form_fingerprints';

    /**
     * The WP-Cron event that refreshes a copy where no finish function can
     * send the page first (runDeferred()); its one argument is the cache key.
     */
    public const REFRESH_HOOK = 'grocerslist_forms_refresh';

    /** The options holding the last body GRO sent: the list's, and each form's (+ its hash). */
    public const LIST_LKG_OPTION = 'grocerslist_forms_list_lkg';
    public const LKG_PREFIX = 'grocerslist_form_lkg_';

    /** Why forms() has no list; listProblem() answers one of these. */
    public const LIST_NOT_CONNECTED = 'not_connected';
    public const LIST_UNAVAILABLE = 'unavailable';
    public const LIST_UNREACHABLE = 'unreachable';

    private const PREFIX = 'grocerslist_form_';
    private const FRESH_FOR = 60;
    private const CONFIG_TTL = 300;
    private const STALE_FOR = 86400;

    /** Seconds a page waits on GRO for a key with nothing stored anywhere. */
    private const COLD_TIMEOUT = 2;

    private const LOCK_PREFIX = 'grocerslist_lock_';

    /** ApiClient::FORM_READ_TIMEOUT, the store and a margin: a holder that dies holds up nobody for long. */
    private const LOCK_TTL = 30;

    /** A refresh event still pending this long after it was due means WP-Cron is not running. */
    private const REFRESH_OVERDUE = 300;

    /** Seconds runDeferred() spends at most, so a page with many forms still ends its request in time. */
    private const DEFERRED_BUDGET = 15;

    /** GRO's answers that say there is nothing to show — not failures to serve a stale copy through. */
    private const FINAL_STATUSES = [401, 403, 404];

    private PageCachePurger $purger;

    /** @var callable(): int */
    private $clock;

    private RefreshLock $lock;

    private RequestFinisher $finisher;

    /** @var array<string, true> The keys this request served stale, to refresh at shutdown. */
    private array $pending = [];

    private bool $shutdownHooked = false;

    /**
     * @param (callable(): int)|null $clock Unix time now; time() by default.
     */
    public function __construct(
        ?PageCachePurger $purger = null,
        ?callable $clock = null,
        ?RefreshLock $lock = null,
        ?RequestFinisher $finisher = null
    ) {
        $this->purger = $purger ?? new PageCachePurger();
        $this->clock = $clock ?? 'time';
        $this->lock = $lock ?? new RefreshLock($this->clock);
        $this->finisher = $finisher ?? new RequestFinisher();
    }

    /**
     * A GRO form hash as it may appear in a URL, a transient name and a
     * signed message: letters, digits, "_" and "-", up to 64 of them. \z, not
     * $, which would let a trailing line break through.
     *
     * @param mixed $hash
     */
    public static function isHash($hash): bool
    {
        return is_string($hash) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $hash) === 1;
    }

    /**
     * @return array<string, mixed>|null The form's config, or null when GRO has
     *                                   no such form for this site, or none can
     *                                   be had right now.
     */
    public function get(string $hash): ?array
    {
        $config = null;

        if (PluginSettings::getApiKey() !== '' && self::isHash($hash)) {
            [$config, $answered] = $this->load(self::PREFIX . $hash);

            if ($answered && $config !== null) {
                $this->noteFingerprint($hash, $config);
            }
        }

        $config = apply_filters('grocerslist_form_config', $config, $hash);

        return is_array($config) ? $config : null;
    }

    /**
     * get(), for the block editor's preview: a copy checked with GRO over
     * FRESH_FOR ago is refreshed before it is read rather than after the
     * response, as for currentForms().
     *
     * @return array<string, mixed>|null
     */
    public function getCurrent(string $hash): ?array
    {
        $this->refresh(self::PREFIX . $hash);

        return $this->get($hash);
    }

    /**
     * forms(), for the block editor's picker: a list checked with GRO over
     * FRESH_FOR ago is refreshed before it is answered rather than after the
     * response, since an editor waits for what GRO has now (a form just made
     * in HQ, a key just fixed) and no visitor waits on a page. It waits up to
     * ApiClient::FORM_READ_TIMEOUT, as a refresh does; while another
     * request's refresh holds the lock, the copy is answered as it is.
     *
     * @return array<int, array{hash: string, kind: string, name: string, isDefault: bool}>|null As forms().
     */
    public function currentForms(): ?array
    {
        $this->refresh(self::LIST_TRANSIENT);

        return $this->forms();
    }

    /**
     * The creator's active forms, for the block's picker and for defaults.
     *
     * @return array<int, array{hash: string, kind: string, name: string, isDefault: bool}>|null
     *         Null when the site is not connected, GRO refuses its key, or GRO
     *         cannot be reached and there is no copy to fall back on;
     *         listProblem() then says which.
     */
    public function forms(): ?array
    {
        if (PluginSettings::getApiKey() === '') {
            return null;
        }

        return $this->load(self::LIST_TRANSIENT)[0];
    }

    /**
     * Why forms() came back null, for the block editor to say so: no key or a
     * refused one (LIST_NOT_CONNECTED), GRO Forms not on the account or not on
     * that GRO yet (LIST_UNAVAILABLE, a 403 or 404), anything else
     * (LIST_UNREACHABLE) — or '' when there is a list.
     */
    public function listProblem(): string
    {
        if (PluginSettings::getApiKey() === '') {
            return self::LIST_NOT_CONNECTED;
        }

        $entry = $this->entry(self::LIST_TRANSIENT);
        if ($entry !== null && $entry['body'] !== null) {
            return '';
        }

        $status = $entry !== null ? $entry['status'] : 0;
        if ($status === 401) {
            return self::LIST_NOT_CONNECTED;
        }

        return $status === 403 || $status === 404 ? self::LIST_UNAVAILABLE : self::LIST_UNREACHABLE;
    }

    /**
     * The config of the creator's default form of a kind (SIGNUP,
     * SAVE_TO_EMAIL, CONTENT_GATE), which the shortcodes show without an id.
     *
     * @return array<string, mixed>|null
     */
    public function getDefault(string $kind): ?array
    {
        foreach ($this->forms() ?? [] as $form) {
            if ($form['kind'] === $kind && $form['isDefault']) {
                return $this->get($form['hash']);
            }
        }

        return null;
    }

    /**
     * GRO's hint that a form changed: an edit, or a change to the brand
     * palette in the creator's email settings, after which GRO sends one for
     * each active form. The hint is anonymous, so it is never data: the
     * config is read again, now, over the authenticated route, and the site's
     * page cache is purged only when the config no longer matches the
     * fingerprint recorded for it here (FINGERPRINTS_OPTION) — a change to
     * its copy, its layout, its colours or anything else GRO sends — or when
     * the read brings back a form GRO could not answer for before (see
     * refresh()). A forged or replayed hint therefore costs at most one GRO
     * request and purges only for a change GRO really has. Nothing is purged
     * for a form this site never showed, or one GRO no longer has: cached
     * pages keep an archived form until they expire. A hash GRO answered "no
     * such form" for (or could not answer for) under a minute ago is not
     * asked about again, and one the site has no record of is left alone for
     * as long as the placeholder an earlier hint left lives. The picker's
     * list is refreshed when the form came, went or changed.
     */
    public function invalidate(string $hash): void
    {
        if (!self::isHash($hash)) {
            return;
        }

        $key = self::PREFIX . $hash;
        if (PluginSettings::getApiKey() === '') {
            delete_transient($key);
            $this->deleteLkg($key);

            return;
        }

        $held = $this->entry($key);
        $hadBody = $held !== null && $held['body'] !== null;
        $fingerprints = null;

        if ($held !== null && !$hadBody) {
            $since = $this->now() - $held['checkedAt'];
            if ($since < self::FRESH_FOR) {
                return;
            }

            // Nothing held for the form and no fingerprint ever: this site has
            // never shown it, and the placeholder an earlier hint left is
            // waited out rather than asked about. Asking GRO again would store
            // one over a key something is stored under by then, which lives
            // the full day (placeholderTtl()) — two hints would be all it took
            // to leave a day-long row behind for any hash at all.
            //
            // The wait is that short life and the longest such a read can
            // take, since the row is written when GRO's answer lands and
            // checkedAt is when the read began — and the last second of it
            // counts, a transient being served while now is still its stored
            // expiry. Only a hint's own read and a refresh leave a short
            // placeholder, and both wait at most FORM_READ_TIMEOUT, so the
            // wait covers every one of them whole.
            //
            // A placeholder still here after that wait is therefore not one of
            // those: it is a day-long one, left by a page view for a form this
            // site's own content names, or re-stored for a key the site did
            // hold (a form of its own, archived by an earlier hint, which
            // unrecorded its fingerprint). A hint may ask about either again —
            // re-storing it adds no row that is not already there and already
            // day-long, and nothing but this site's own content can put one
            // there. Nothing waits on the wait: no page has this form, and the
            // page view of one that does refreshes it after a minute, hint or
            // no hint.
            $fingerprints = $this->fingerprints();
            if (!array_key_exists($hash, $fingerprints)
                && $since <= self::CONFIG_TTL + ApiClient::FORM_READ_TIMEOUT) {
                return;
            }
        }

        [$body, $answered, $status, $recovered] = $this->load($key, true);
        if (!$answered) {
            return;
        }

        // load() writes no fingerprint, so one read serves both.
        $fingerprints = $fingerprints ?? $this->fingerprints();
        $known = array_key_exists($hash, $fingerprints);

        if ($body === null) {
            if ($known && $status === 404) {
                unset($fingerprints[$hash]);
                $this->saveFingerprints($fingerprints);
            }
            if ($known || $hadBody) {
                $this->expire(self::LIST_TRANSIENT);
            }

            return;
        }

        $fingerprint = self::fingerprint($body);
        if ($known && $fingerprints[$hash] === $fingerprint) {
            if ($recovered) {
                $this->purgeRecovered($key);
            }

            return;
        }

        $fingerprints[$hash] = $fingerprint;
        $this->saveFingerprints($fingerprints);
        $this->expire(self::LIST_TRANSIENT);

        // A form this site has no fingerprint for may never have been on a
        // cached page: the first hint records it and purges nothing, unless
        // GRO had failed to answer for it and pages were cached without it.
        // The record is saved before the purge so a purge that dies part-way
        // cannot make the next hint purge again.
        if ($known) {
            $this->purger->purgeAll($hash);
        } elseif ($recovered) {
            $this->purgeRecovered($key);
        }
    }

    /**
     * Asks GRO again for a copy served stale: at shutdown (runDeferred()), or
     * as the WP-Cron event REFRESH_HOOK; and for the block editor before it
     * reads one (currentForms(), getCurrent()). Under a lock, so that of the
     * page views that served the copy stale together only one asks GRO; the
     * others find the lock held, or the copy fresh again, and leave it.
     *
     * When GRO's answer brings back a form it could not give before — a
     * placeholder, stored while GRO was unreachable, replaced by a body — the
     * site's page cache is purged once, for the pages cached without the form
     * meanwhile. Only the lock holder does that, and never after a final
     * answer nor on a first fetch, so a purge that flushes the object cache,
     * and the transients with it, never leads to another (noteFingerprint()).
     *
     * @param string $key LIST_TRANSIENT or a form's transient name; anything
     *                    else is ignored. Optional only so that an event
     *                    scheduled by hand without arguments, which WP-Cron
     *                    runs with none, does nothing rather than fail.
     */
    public function refresh(string $key = ''): void
    {
        if ($this->request($key) === null) {
            return;
        }

        $snapshot = $this->entry($key) ?? $this->rehydrate($key);
        if ($snapshot !== null && $this->now() - $snapshot['checkedAt'] < self::FRESH_FOR) {
            return;
        }

        $lock = self::LOCK_PREFIX . $key;
        if (!$this->lock->acquire($lock, self::LOCK_TTL)) {
            Logger::debug("FormConfigCache: another request is refreshing {$key}; leaving it");

            return;
        }

        try {
            // Again, under the lock and from the store: a refresh another
            // request finished before this one took the lock, or a cron event
            // that ran late, finds it fresh.
            $snapshot = $this->storedEntry($key) ?? $this->rehydrate($key);
            if ($snapshot !== null && $this->now() - $snapshot['checkedAt'] < self::FRESH_FOR) {
                return;
            }

            [$body, $answered, , $recovered] = $this->fetch(
                $key,
                $snapshot,
                $this->now(),
                $snapshot === null || $snapshot['loadedAt'] === 0,
                ApiClient::FORM_READ_TIMEOUT
            );

            $hash = self::hashOf($key);
            if ($answered && $body !== null && $hash !== '') {
                $this->noteFingerprint($hash, $body);
            }

            if ($recovered) {
                $this->purgeRecovered($key);
            }
        } finally {
            $this->lock->release($lock);
        }
    }

    /**
     * Refreshes the copies this request served stale once nobody waits on it:
     * inline in a WP-Cron run; inline after RequestFinisher has sent the
     * response; else as a WP-Cron event per key, for the next request that
     * spawns WP-Cron. Hooked to shutdown by queueRefresh().
     */
    public function runDeferred(): void
    {
        $keys = array_keys($this->pending);
        $this->pending = [];
        if (!$keys) {
            return;
        }

        if ($this->finisher->inCron()) {
            $this->refreshInline($keys);
        } elseif ($this->finisher->available()) {
            $this->finisher->finish();
            $this->refreshInline($keys);
        } elseif (function_exists('wp_schedule_single_event')) {
            foreach ($keys as $key) {
                if (!wp_next_scheduled(self::REFRESH_HOOK, [$key])) {
                    wp_schedule_single_event($this->now(), self::REFRESH_HOOK, [$key]);
                }
            }
        } else {
            $this->refreshInline($keys);
        }
    }

    /**
     * @param mixed $data GRO's decoded 200 body for one form.
     * @return array<string, mixed>|null
     */
    public static function parseConfig($data): ?array
    {
        return is_array($data)
            && self::isHash($data['hash'] ?? null)
            && in_array($data['kind'] ?? null, self::KINDS, true)
            ? $data
            : null;
    }

    /**
     * @param mixed $data GRO's decoded 200 body for the list: { data: config[] }.
     * @return array<int, array{hash: string, kind: string, name: string, isDefault: bool}>|null
     */
    public static function parseList($data): ?array
    {
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return null;
        }

        $forms = [];
        foreach ($data['data'] as $config) {
            $config = self::parseConfig($config);
            if ($config === null) {
                continue;
            }

            $forms[] = [
                'hash'      => $config['hash'],
                'kind'      => $config['kind'],
                'name'      => isset($config['name']) && is_string($config['name']) ? $config['name'] : '',
                'isDefault' => !empty($config['isDefault']),
            ];
        }

        return $forms;
    }

    /**
     * The copy of $key to serve now. A page waits on GRO only for a key with
     * nothing stored anywhere and for one whose refresh event WP-Cron has left
     * unrun (refreshOverdue()); any other copy is served as it is, and one
     * checked over FRESH_FOR ago is refreshed after the response (runDeferred()).
     *
     * @param bool $force Ask GRO in full now, however fresh the copy: a hint.
     * @return array{0: array|null, 1: bool, 2: int, 3: bool} The body; whether GRO answered for
     *                                                       it just now; GRO's status (0 when it
     *                                                       was not asked or could not be reached);
     *                                                       whether GRO's answer brought back a form
     *                                                       it could not give before (refresh()).
     */
    private function load(string $key, bool $force = false): array
    {
        $now = $this->now();
        $entry = $this->entry($key);

        if ($entry === null) {
            $entry = $this->rehydrate($key);
            if ($entry !== null) {
                $this->store($key, $entry);
            }
        }

        if ($force) {
            return $this->fetch($key, $entry, $now, true, ApiClient::FORM_READ_TIMEOUT);
        }

        if ($entry === null) {
            return $this->fetch($key, null, $now, true, self::COLD_TIMEOUT);
        }

        if ($now - $entry['checkedAt'] < self::FRESH_FOR) {
            return [$entry['body'], false, 0, false];
        }

        // Stale, a placeholder too: while GRO is down no page waits on it.
        if (!$this->refreshOverdue($key, $now)) {
            $this->queueRefresh($key);

            return [$entry['body'], false, 0, false];
        }

        return $this->fetch($key, $entry, $now, false, self::COLD_TIMEOUT);
    }

    /**
     * Asks GRO for $key — nothing else does — and stores the answer unless a
     * newer one landed while it was on its way.
     *
     * @param array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null $snapshot
     *        The copy as it was when the read began.
     * @param int $startedAt When the read began: the time the answer is stored under.
     * @param bool $full Ask without If-None-Match, whatever the copy.
     * @param int $timeout Seconds to wait on GRO.
     * @return array{0: array|null, 1: bool, 2: int, 3: bool} As load().
     */
    private function fetch(string $key, ?array $snapshot, int $startedAt, bool $full, int $timeout): array
    {
        $request = $this->request($key);
        if ($request === null) {
            return [$snapshot !== null ? $snapshot['body'] : null, false, 0, false];
        }

        $etag = !$full && $snapshot !== null && $snapshot['body'] !== null && $snapshot['etag'] !== ''
            && $startedAt - $snapshot['loadedAt'] < self::CONFIG_TTL
            ? $snapshot['etag']
            : null;

        $response = $request($etag, $timeout);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        // Read again, from the store: another request may have stored an
        // answer while GRO kept this one waiting. Strictly newer, so that with
        // the clock standing still the later write still wins.
        $current = $this->storedEntry($key);
        $base = $current ?? $snapshot;

        if ($status === 304 && $etag !== null) {
            if ($current !== null && ($current['fetchedAt'] > $startedAt || $current['etag'] !== $etag)) {
                return [$current['body'], false, $status, false];
            }

            $base['fetchedAt'] = $startedAt;
            $base['checkedAt'] = $startedAt;
            $this->store($key, $base);

            return [$base['body'], true, $status, false];
        }

        $body = $status >= 200 && $status < 300
            ? ($this->parser($key))(json_decode((string) wp_remote_retrieve_body($response), true))
            : null;

        if ($body !== null) {
            // A placeholder's fetchedAt is 0, so a final answer is newer by
            // its checkedAt: GRO said the form was gone after this read began,
            // and an archived form must not come back from an older answer.
            if ($current !== null && ($current['fetchedAt'] > $startedAt
                || (self::isFinal($current) && $current['checkedAt'] > $startedAt))) {
                Logger::debug("FormConfigCache: a newer answer for {$key} landed while this read was in flight; keeping it");

                return [$current['body'], false, $status, false];
            }

            $etagHeader = wp_remote_retrieve_header($response, 'etag');
            $etagHeader = is_string($etagHeader) ? $etagHeader : '';

            $this->store($key, [
                'body'      => $body,
                'etag'      => $etagHeader,
                'loadedAt'  => $startedAt,
                'fetchedAt' => $startedAt,
                'checkedAt' => $startedAt,
                'status'    => $status,
            ]);
            $this->saveLkg($key, $body, $etagHeader);

            $recovered = $base !== null && $base['body'] === null && !self::isFinal($base);

            return [$body, true, $status, $recovered];
        }

        if (in_array($status, self::FINAL_STATUSES, true)) {
            if ($current !== null && $current['fetchedAt'] > $startedAt) {
                return [$current['body'], false, $status, false];
            }

            $this->store($key, self::placeholder($startedAt, $status), self::placeholderTtl($base, $timeout));
            // An archived form must never come back from the saved copy.
            $this->deleteLkg($key);

            return [null, true, $status, false];
        }

        if ($current !== null && $current['checkedAt'] > $startedAt) {
            return [$current['body'], false, $status, false];
        }

        $answer = 'FormConfigCache: GRO answered ' . ($status > 0 ? $status : 'nothing') . ' for ' . $key . '; ';

        if ($base !== null && $base['body'] !== null) {
            // A copy rehydrated from the saved one has no fetchedAt to show.
            Logger::debug($answer . ($base['fetchedAt'] > 0
                ? 'serving the copy it confirmed at ' . gmdate('c', $base['fetchedAt'])
                : 'serving the copy saved from its last answer'));

            $base['checkedAt'] = $startedAt;
            $this->store($key, $base);

            return [$base['body'], false, $status, false];
        }

        Logger::debug($answer . 'nothing to serve');
        $this->store($key, self::placeholder($startedAt, $status), self::placeholderTtl($base, $timeout));

        return [null, false, $status, false];
    }

    private function queueRefresh(string $key): void
    {
        $this->pending[$key] = true;

        if (!$this->shutdownHooked) {
            // Last, so that WordPress's own shutdown work runs first: among it
            // wp_ob_end_flush_all() (priority 1), where the output buffer a
            // page cache saves the page from completes.
            add_action('shutdown', [$this, 'runDeferred'], PHP_INT_MAX);
            $this->shutdownHooked = true;
        }
    }

    /**
     * Whether $key's refresh event is still pending REFRESH_OVERDUE after it
     * was due: a server with no finish function, WP-Cron off
     * (DISABLE_WP_CRON) and no system cron in its place, where a scheduled
     * event never runs. Its page views then ask GRO themselves. So does the
     * first page view after REFRESH_OVERDUE without a request on a server
     * whose WP-Cron works: its own request spawns the event only as it starts,
     * and it still reads the event as pending.
     */
    private function refreshOverdue(string $key, int $now): bool
    {
        if (!function_exists('wp_next_scheduled')) {
            return false;
        }

        $due = wp_next_scheduled(self::REFRESH_HOOK, [$key]);

        return is_numeric($due) && (int) $due < $now - self::REFRESH_OVERDUE;
    }

    /**
     * @param array<int, string> $keys
     */
    private function refreshInline(array $keys): void
    {
        $start = $this->now();

        foreach ($keys as $i => $key) {
            if ($this->now() - $start >= self::DEFERRED_BUDGET) {
                // The next page view that serves them stale queues them again.
                Logger::debug('FormConfigCache: no time left to refresh ' . implode(', ', array_slice($keys, $i)));

                return;
            }

            $this->refresh($key);
        }
    }

    /**
     * Asks GRO for $key, given the ETag to revalidate against and the seconds
     * to wait. Null for a site without a key, and for a key that is neither
     * the list's nor a form's.
     *
     * @return (callable(?string, int): (array|\WP_Error))|null
     */
    private function request(string $key): ?callable
    {
        $apiKey = PluginSettings::getApiKey();
        if ($apiKey === '') {
            return null;
        }

        if ($key === self::LIST_TRANSIENT) {
            return fn(?string $etag, int $timeout) => ApiClient::getForms($apiKey, $etag, $timeout);
        }

        $hash = self::hashOf($key);
        if ($hash === '') {
            return null;
        }

        return fn(?string $etag, int $timeout) => ApiClient::getForm($apiKey, $hash, $etag, $timeout);
    }

    /**
     * Reads GRO's decoded 200 body for $key; null when it is unusable.
     *
     * @return callable(mixed): (array|null)
     */
    private function parser(string $key): callable
    {
        return $key === self::LIST_TRANSIENT ? [self::class, 'parseList'] : [self::class, 'parseConfig'];
    }

    /**
     * The form hash in a form's transient name; '' for the list's, or anything else.
     */
    private static function hashOf(string $key): string
    {
        if (strpos($key, self::PREFIX) !== 0) {
            return '';
        }

        $hash = (string) substr($key, strlen(self::PREFIX));

        return self::isHash($hash) ? $hash : '';
    }

    /**
     * What is stored when GRO sends no body: nothing to serve, older than any
     * answer GRO gives (fetchedAt 0), and left alone until FRESH_FOR after
     * $checkedAt.
     *
     * @return array{body: null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}
     */
    private static function placeholder(int $checkedAt, int $status): array
    {
        return [
            'body'      => null,
            'etag'      => '',
            'loadedAt'  => 0,
            'fetchedAt' => 0,
            'checkedAt' => $checkedAt,
            'status'    => $status,
        ];
    }

    /**
     * How long the placeholder a read is about to store should live. Both of
     * fetch()'s stores go through this, so the two can never drift apart.
     *
     * STALE_FOR, except for a key nothing was stored under read by something
     * no page was waiting on. $timeout says which: COLD_TIMEOUT is passed
     * only by load()'s two unforced fetches, the ones a page view waits on,
     * and the hash reaching them is always one this site's own content names
     * — a block or shortcode in a post (Blocks\FormRenderer), a submission
     * carrying the signature the site minted for it (Rest\FormsController,
     * Blocks\FormSignature) or the editor's own preview. That form is going
     * to be rendered again, so its "nothing to show" is worth a day: without
     * it every page view more than CONFIG_TTL after the last waits on GRO
     * again. load()'s forced fetch, which is GRO's hint, and refresh() both
     * pass FORM_READ_TIMEOUT, and the hint is anonymous and may name any hash
     * at all (invalidate()) — nothing renders that form, so there is nothing
     * to keep showing, and without a persistent object cache every
     * placeholder is two wp_options rows a day each.
     *
     * @param array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null $base
     *        The copy this answer is replacing; null when nothing was stored.
     */
    private static function placeholderTtl(?array $base, int $timeout): int
    {
        return $base === null && $timeout !== self::COLD_TIMEOUT ? self::CONFIG_TTL : self::STALE_FOR;
    }

    /**
     * Whether $entry holds GRO's final answer: nothing to show, for good.
     *
     * @param array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int} $entry
     */
    private static function isFinal(array $entry): bool
    {
        return $entry['body'] === null && in_array($entry['status'], self::FINAL_STATUSES, true);
    }

    /**
     * The saved copy behind $key's transient, as a copy never checked with
     * GRO: stale at once (checkedAt 0), refreshed in full (loadedAt 0), and
     * older than any answer GRO gives (fetchedAt 0).
     *
     * @return array{body: array, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null
     */
    private function rehydrate(string $key): ?array
    {
        $saved = $this->lkg($key);
        if ($saved === null) {
            return null;
        }

        return [
            'body'      => $saved['body'],
            'etag'      => $saved['etag'],
            'loadedAt'  => 0,
            'fetchedAt' => 0,
            'checkedAt' => 0,
            'status'    => 200,
        ];
    }

    private function lkgOption(string $key): string
    {
        return $key === self::LIST_TRANSIENT ? self::LIST_LKG_OPTION : self::LKG_PREFIX . self::hashOf($key);
    }

    /**
     * @return array{body: array, etag: string}|null
     */
    private function lkg(string $key): ?array
    {
        $saved = get_option($this->lkgOption($key), false);

        return is_array($saved) && is_array($saved['body'] ?? null) && is_string($saved['etag'] ?? null)
            ? ['body' => $saved['body'], 'etag' => $saved['etag']]
            : null;
    }

    /**
     * No timestamps in it, so that a body GRO sends again unchanged leaves
     * the option alone: update_option() writes nothing for an equal value.
     *
     * @param array<mixed> $body
     */
    private function saveLkg(string $key, array $body, string $etag): void
    {
        update_option($this->lkgOption($key), ['body' => $body, 'etag' => $etag], false);
    }

    private function deleteLkg(string $key): void
    {
        delete_option($this->lkgOption($key));
    }

    private function purgeRecovered(string $key): void
    {
        Logger::debug("FormConfigCache: GRO is back for {$key}; purging the page cache");
        $this->purger->purgeAll(self::hashOf($key));
    }

    /**
     * Makes the next read of a copy ask GRO in full, keeping the copy to fall
     * back on if GRO cannot answer.
     */
    private function expire(string $key): void
    {
        $entry = $this->entry($key);
        if ($entry === null) {
            return;
        }

        $entry['loadedAt'] = 0;
        $entry['checkedAt'] = 0;
        $this->store($key, $entry);
    }

    /**
     * Records the fingerprint of the first config GRO sends for a form, so a
     * later hint can tell whether cached pages are behind. Only a hint moves
     * the record on, and only a hint purges: a page view that picks up a
     * change first — a 200 after its ETag missed, or the full read every five
     * minutes — serves it and leaves the record for the hint to find. Were a
     * page view to purge, then with a purge that flushes the object cache
     * along with the transients every page view after it would go back to
     * GRO, find the same change and purge again.
     *
     * @param array<string, mixed> $config
     */
    private function noteFingerprint(string $hash, array $config): void
    {
        $fingerprints = $this->fingerprints();
        if (array_key_exists($hash, $fingerprints)) {
            return;
        }

        $fingerprints[$hash] = self::fingerprint($config);
        $this->saveFingerprints($fingerprints);
    }

    /**
     * See FINGERPRINTS_OPTION.
     *
     * @param array<string, mixed> $config
     */
    private static function fingerprint(array $config): string
    {
        return md5((string) wp_json_encode($config));
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprints(): array
    {
        $fingerprints = get_option(self::FINGERPRINTS_OPTION, []);

        return is_array($fingerprints) ? $fingerprints : [];
    }

    /**
     * @param array<string, mixed> $fingerprints
     */
    private function saveFingerprints(array $fingerprints): void
    {
        update_option(self::FINGERPRINTS_OPTION, $fingerprints, false);
    }

    /**
     * @return array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null
     */
    private function entry(string $key): ?array
    {
        return self::readEntry(get_transient($key));
    }

    /**
     * $key's copy as the store holds it now, for the checks that must see
     * what another request stored meanwhile. entry() cannot: WordPress keeps
     * what a request has read for the rest of it (a persistent object cache
     * its own copy of the transient, and without one the transient's two
     * options in the options cache group, or their absence in notoptions),
     * so a second read answers the copy this request read before.
     *
     * @return array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null
     */
    private function storedEntry(string $key): ?array
    {
        if (wp_using_ext_object_cache()) {
            // $force: the cache's own value, not this request's copy of it.
            return self::readEntry(wp_cache_get($key, 'transient', true));
        }

        $options = ['_transient_' . $key => true, '_transient_timeout_' . $key => true];
        foreach (array_keys($options) as $option) {
            wp_cache_delete($option, 'options');
        }
        $missing = wp_cache_get('notoptions', 'options');
        if (is_array($missing) && array_intersect_key($missing, $options)) {
            wp_cache_set('notoptions', array_diff_key($missing, $options), 'options');
        }

        return $this->entry($key);
    }

    /**
     * @param mixed $entry A stored copy, as get_transient() returns it.
     * @return array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int}|null
     */
    private static function readEntry($entry): ?array
    {
        if (!is_array($entry)
            || !array_key_exists('body', $entry)
            || !(is_array($entry['body']) || $entry['body'] === null)
            || !isset($entry['etag'], $entry['loadedAt'], $entry['fetchedAt'], $entry['checkedAt'])
            || !is_string($entry['etag'])) {
            return null;
        }

        return [
            'body'      => $entry['body'],
            'etag'      => $entry['etag'],
            'loadedAt'  => (int) $entry['loadedAt'],
            'fetchedAt' => (int) $entry['fetchedAt'],
            'checkedAt' => (int) $entry['checkedAt'],
            'status'    => isset($entry['status']) ? (int) $entry['status'] : 0,
        ];
    }

    /**
     * Every copy lives for STALE_FOR, a placeholder too: one that expired
     * after FRESH_FOR would leave the next page view waiting on GRO with
     * nothing to show, and no refresh — nor the purge when GRO comes back —
     * would ever replace it. A body that expires still has its saved copy
     * behind it.
     *
     * That is worth a day for a key the site has a copy for, and for one a
     * page view found nothing under: a page has that form. The short life is
     * for the rest — nothing stored, and nothing waiting on the read — which
     * fetch() picks out by its timeout (placeholderTtl()).
     *
     * @param array{body: array|null, etag: string, loadedAt: int, fetchedAt: int, checkedAt: int, status: int} $entry
     * @param int $ttl Seconds the copy lives.
     */
    private function store(string $key, array $entry, int $ttl = self::STALE_FOR): void
    {
        set_transient($key, $entry, $ttl);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}

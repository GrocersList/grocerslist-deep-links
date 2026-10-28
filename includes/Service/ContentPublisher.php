<?php

namespace GrocersList\Service;

use GrocersList\Admin\CategoryGating;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;
use GrocersList\Support\PostPayload;
use GrocersList\Support\RequestFinisher;
use GrocersList\Support\WprmRecipes;

/**
 * Sends GRO the site's public posts, which GRO builds the creator's Digest
 * emails from: each post as it is published, updated or unpublished, the
 * posts of a category whose membership gating changed and of a category or
 * tag renamed or deleted, an hourly catch-up, and the 20 newest once, after
 * the response of the first admin request that can send it early (else from
 * WP-Cron), tried again until GRO has taken them. With them go the site's
 * category and tag names, which the Digest setup offers to filter by: when
 * one is added, renamed or deleted, every hour, and before the 20 newest.
 *
 * Nothing calls GRO inside a save. The hooks only note which posts changed —
 * the block editor saves a post more than once a request — and at shutdown,
 * when the post's terms, meta and featured image are saved too, each post is
 * read as it is then and sent: after the response has gone where the server
 * can send it first (RequestFinisher), else from the one pending WP-Cron
 * event. A post that is public then goes as publish or update and any other
 * as unpublish, whichever hook noted it, so pushes that run out of order
 * still leave GRO with the post's last state.
 *
 * SYNCED_META holds the post_modified_gmt of the copy GRO accepted, or of a
 * post with nothing to send (no http(s) URL or publish date), marked without
 * GRO taking it so that it is not sought again until edited; it goes when GRO
 * accepts the post's unpublish. From it and the posts' modified and publish
 * dates the hourly reconcile tells what changed in the last day without
 * reaching GRO. Once sending is on again after it was turned off or the
 * plugin deactivated, the reconcile reaches back to then (CATCH_UP_OPTION),
 * and sends the posts deleted meanwhile (DELETED_OPTION).
 */
class ContentPublisher
{
    public const PUSH_HOOK = 'grocerslist_content_push';
    public const SYNC_HOOK = 'grocerslist_content_sync';
    public const BACKFILL_HOOK = 'grocerslist_content_backfill';
    public const TERMS_HOOK = 'grocerslist_content_terms';
    public const SYNCED_META = '_grocerslist_content_synced_at';

    /** The BACKFILL_VERSION whose backfill GRO took all of. */
    public const BACKFILL_OPTION = 'grocerslist_content_backfill_version';

    /** How the backfill's tries went: backfillStatus(). */
    public const BACKFILL_STATUS_OPTION = 'grocerslist_content_backfill_status';

    /**
     * Past the 1.31.0 that the first 1.31.0 builds marked done when they had
     * only scheduled the backfill: those sites run it again, with the
     * categories and tags.
     */
    public const BACKFILL_VERSION = '1.31.0.1';

    /** Tries at the backfill before it is left alone, each at least BACKFILL_RETRY_AFTER after the last. */
    public const BACKFILL_ATTEMPTS = 5;

    /** Held by the request starting a try: claimBackfill(). */
    public const BACKFILL_LOCK_OPTION = 'grocerslist_content_backfill_lock';

    /**
     * When sending stopped, turned off or the plugin deactivated
     * (grocers_list_deactivate() writes it by name), for the reconcile to
     * reach back to: noteSendingStopped(), reconcile().
     */
    public const CATCH_UP_OPTION = 'grocerslist_content_catch_up_since';

    /** Source keys of posts GRO holds deleted while sending was off, the newest MAX_DELETED: onDelete(). */
    public const DELETED_OPTION = 'grocerslist_content_deleted';

    private const MAX_DELETED = 500;

    private const BACKFILL_RETRY_AFTER = 3600;

    /** Seconds a request's claim on starting a try holds off the others. */
    private const BACKFILL_LOCK_TTL = 300;

    private const BACKFILL_POSTS = 20;
    private const RECONCILE_WINDOW = 86400;

    /** Posts the hourly reconcile sends GRO at most a run, of each kind; the rest wait for the next. */
    private const RECONCILE_POSTS = 50;

    /** Posts GRO took before that the reconcile reads, newest first, for those changed since. */
    private const RECONCILE_SCAN = 300;

    private const BATCH_SIZE = 50;

    /** Posts one push sends at most, which bounds it and the WP-Cron event that carries it. */
    private const MAX_QUEUE = 200;

    /** WP Recipe Maker's recipe type: a recipe goes to GRO inside the post it is in. */
    private const WPRM_RECIPE = 'wprm_recipe';

    /** Public types that are never posts of their own. */
    private const NEVER_SENT_TYPES = ['attachment', self::WPRM_RECIPE];

    /** Sent even when a site keeps them out of its search, as some themes do with pages. */
    private const ALWAYS_SENT_TYPES = ['post', 'page'];

    /** Where a post GRO holds can go when it is unpublished. */
    private const UNPUBLISHED_STATUSES = ['draft', 'pending', 'private', 'future', 'trash'];

    /** GRO's answers that the rest of a push would get too. */
    private const STOP_STATUSES = [401, 429];

    /** The taxonomies whose names go to GRO: a post's categories and its tags. */
    private const TERM_TAXONOMIES = ['category', 'post_tag'];

    /** Names GRO takes at most of each taxonomy. */
    private const MAX_TERMS = 500;

    private PostPayload $payload;
    private WprmRecipes $wprm;
    private RequestFinisher $finisher;

    /** @var array<int, string> Post id => publish, update or unpublish: what this request changed. */
    private array $queue = [];

    /** Posts this request changed past MAX_QUEUE. */
    private int $dropped = 0;

    /** Whether a category or tag changed since the lists last went to GRO in this request. */
    private bool $termsChanged = false;

    /** @var array<int, string> Term id => its name before the edit under way: onTermEditing(). */
    private array $namesBeforeEdit = [];

    /**
     * Whether this request runs the backfill once its response has gone:
     * set by maybeScheduleBackfill() only where RequestFinisher can send it.
     */
    private bool $backfillDue = false;

    /** Whether the plugin was deactivated in this request: cancelPending(). */
    private bool $cancelled = false;

    private bool $shutdownHooked = false;

    public function __construct(?PostPayload $payload = null, ?WprmRecipes $wprm = null, ?RequestFinisher $finisher = null)
    {
        $this->wprm = $wprm ?? new WprmRecipes();
        $this->payload = $payload ?? new PostPayload($this->wprm);
        $this->finisher = $finisher ?? new RequestFinisher();
    }

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        add_action('trashed_post', [$this, 'onTrashed'], 10, 1);
        add_action('before_delete_post', [$this, 'onDelete'], 10, 1);
        add_action('wprm_clear_cache', [$this, 'onWprmClearCache'], 10, 1);
        add_action('added_term_meta', [$this, 'onTermMeta'], 10, 3);
        add_action('updated_term_meta', [$this, 'onTermMeta'], 10, 3);
        add_action('deleted_term_meta', [$this, 'onTermMeta'], 10, 3);
        add_action('created_term', [$this, 'onTermChange'], 10, 3);
        add_action('edit_terms', [$this, 'onTermEditing'], 10, 2);
        add_action('edited_term', [$this, 'onTermEdited'], 10, 3);
        add_action('pre_delete_term', [$this, 'onTermDeleting'], 10, 2);
        add_action('delete_term', [$this, 'onTermChange'], 10, 3);

        add_action(self::PUSH_HOOK, [$this, 'push'], 10, 1);
        add_action(self::SYNC_HOOK, [$this, 'reconcile']);
        add_action(self::BACKFILL_HOOK, [$this, 'backfill']);
        add_action(self::TERMS_HOOK, [$this, 'pushTerms']);

        // register_activation_hook does not fire on an update, so a site
        // updated to this version gets its hourly event here.
        add_action('init', [$this, 'scheduleSync']);
        add_action('admin_init', [$this, 'maybeScheduleBackfill']);

        // The hook register_deactivation_hook() uses for grocerslist.php, ahead
        // of grocers_list_deactivate() (10), which drops the pending push.
        if (defined('GROCERS_LIST_PLUGIN_FILE')) {
            add_action('deactivate_' . plugin_basename(GROCERS_LIST_PLUGIN_FILE), [$this, 'cancelPending'], 5);
        }
    }

    /**
     * The plugin was deactivated in this request: nothing it noted goes to
     * GRO at shutdown. A backfill started on admin_init would otherwise run
     * after grocers_list_deactivate() cleared its options and mark itself
     * done, so reactivating would never run it again. A deleted post this
     * request or the pending push held is noted (DELETED_OPTION): once the
     * push is dropped, nothing could find it again.
     */
    public function cancelPending(): void
    {
        // Every push pending, not one: two requests on a server with no
        // finish function can each have left their own (schedule()).
        $queue = $this->queue;
        foreach (self::pendingPushes() as [, $carried]) {
            $queue += $carried;
        }
        self::recordGone($queue);

        $this->queue = [];
        $this->termsChanged = false;
        $this->backfillDue = false;
        $this->dropped = 0;
        $this->cancelled = true;
    }

    /**
     * Into and out of publish. An edit that keeps a post published is
     * onPostUpdated()'s.
     *
     * @param mixed $new
     * @param mixed $old
     * @param mixed $post
     */
    public function onTransition($new, $old, $post): void
    {
        if ($new === $old || !$this->tracks($post)) {
            return;
        }

        // Published with a password is not public, and GRO never had it.
        if ($new === 'publish' && PostPayload::isPublic($post)) {
            $this->queue((int) $post->ID, 'publish');
        } elseif ($old === 'publish') {
            $this->queue((int) $post->ID, 'unpublish');
        }
    }

    /**
     * An edit to a post published before and after it — public on at least
     * one side, so a password added goes to GRO as an unpublish.
     *
     * @param mixed $postId
     * @param mixed $after
     * @param mixed $before
     */
    public function onPostUpdated($postId, $after = null, $before = null): void
    {
        if (is_object($before) && ($before->post_status ?? '') === 'publish'
            && is_object($after) && ($after->post_status ?? '') === 'publish'
            && (PostPayload::isPublic($before) || PostPayload::isPublic($after))
            && $this->tracks($after)) {
            $this->queue((int) $after->ID, 'update');
        }
    }

    /**
     * Trashing reaches onTransition() too; this goes by the status
     * wp_trash_post() saved beforehand, on every WordPress version.
     *
     * @param mixed $postId
     */
    public function onTrashed($postId): void
    {
        $post = get_post((int) $postId);
        if ($this->tracks($post) && get_post_meta((int) $post->ID, '_wp_trash_meta_status', true) === 'publish') {
            $this->queue((int) $post->ID, 'unpublish');
        }
    }

    /**
     * A post deleted outright changes no status. One GRO still holds after an
     * unpublish GRO did not take goes too: once the post is gone, nothing
     * could send that again. Deleted while sending is off, one GRO holds is
     * noted for the reconcile to send once it is on again (DELETED_OPTION):
     * no query could find it then.
     *
     * @param mixed $postId
     */
    public function onDelete($postId): void
    {
        $post = get_post((int) $postId);
        if (!$this->sendable($post)) {
            return;
        }

        $held = get_post_meta((int) $post->ID, self::SYNCED_META, true) !== '';
        if (!$this->enabled()) {
            if ($held) {
                self::recordDeleted([PostPayload::sourceKey((int) $post->ID)]);
            }
        } elseif ($held || ($post->post_status ?? '') === 'publish') {
            $this->queue((int) $post->ID, 'unpublish');
        }
    }

    /**
     * WP Recipe Maker saves a recipe without saving the post it is in, and
     * clears that post's cache: GRO's copy of the post's recipe is stale.
     * WPRM passes the post's id (false for every post, which names none); a
     * recipe's own id is taken to its post.
     *
     * @param mixed $postId
     */
    public function onWprmClearCache($postId = null): void
    {
        $id = is_numeric($postId) ? (int) $postId : 0;
        if ($id > 0 && get_post_type($id) === self::WPRM_RECIPE) {
            $id = $this->wprm->parentPostId($id);
        }

        $post = $id > 0 ? get_post($id) : null;
        if ($this->tracks($post) && PostPayload::isPublic($post)) {
            $this->queue($id, 'update');
        }
    }

    /**
     * A category's membership gating changed. CategoryGating keeps it as term
     * meta, which moves no post's post_modified_gmt, so the reconcile cannot
     * see it: the category's public posts go again, newest first, as many as
     * MAX_QUEUE. WordPress fires these only for a value that changed.
     *
     * @param mixed $metaId
     * @param mixed $termId
     * @param mixed $metaKey
     */
    public function onTermMeta($metaId, $termId, $metaKey): void
    {
        if ($metaKey !== CategoryGating::META_CATEGORY_GATING_TYPE || !is_numeric($termId) || !$this->enabled()) {
            return;
        }

        // A category's gating reaches the posts in it, not those in its children.
        $this->queueTermPosts((int) $termId, 'category');
    }

    /**
     * A category or tag added or deleted (created_term, delete_term;
     * WordPress passes the taxonomy third from 4.4 on), or edited
     * (onTermEdited()): the site's lists go to GRO at shutdown, once however
     * many changed.
     *
     * @param mixed $termId
     * @param mixed $ttId
     * @param mixed $taxonomy
     */
    public function onTermChange($termId, $ttId = null, $taxonomy = null): void
    {
        if (!in_array($taxonomy, self::TERM_TAXONOMIES, true) || !$this->enabled()) {
            return;
        }

        $this->termsChanged = true;
        $this->flushAtShutdown();
    }

    /**
     * A category or tag about to be edited (edit_terms): its name before the
     * edit, for onTermEdited().
     *
     * @param mixed $termId
     * @param mixed $taxonomy
     */
    public function onTermEditing($termId, $taxonomy = null): void
    {
        if (!in_array($taxonomy, self::TERM_TAXONOMIES, true) || !is_numeric($termId) || !$this->enabled()) {
            return;
        }

        $name = self::termName((int) $termId, $taxonomy);
        if ($name !== null) {
            $this->namesBeforeEdit[(int) $termId] = $name;
        }
    }

    /**
     * A category or tag edited (edited_term): the lists go, as for any
     * change, and a new name sends its posts again too. GRO's copies of them
     * carry the old one, which a Digest filtered by the new one would miss,
     * and a rename moves no post's post_modified_gmt for the reconcile to
     * see. An edit that keeps the name (a description, a slug) sends none.
     *
     * @param mixed $termId
     * @param mixed $ttId
     * @param mixed $taxonomy
     */
    public function onTermEdited($termId, $ttId = null, $taxonomy = null): void
    {
        $this->onTermChange($termId, $ttId, $taxonomy);

        $id = is_numeric($termId) ? (int) $termId : 0;
        $before = $this->namesBeforeEdit[$id] ?? null;
        unset($this->namesBeforeEdit[$id]);
        if ($before === null || !in_array($taxonomy, self::TERM_TAXONOMIES, true) || !$this->enabled()) {
            return;
        }

        $after = self::termName($id, $taxonomy);
        if ($after !== null && $after !== $before) {
            $this->queueTermPosts($id, $taxonomy);
        }
    }

    /**
     * A category or tag about to be deleted (pre_delete_term, 4.1+), while
     * its posts still have it: they go again at shutdown, once WordPress has
     * taken it off them (giving a post left with no category the default
     * one), so GRO's copies drop its name.
     *
     * @param mixed $termId
     * @param mixed $taxonomy
     */
    public function onTermDeleting($termId, $taxonomy = null): void
    {
        if (in_array($taxonomy, self::TERM_TAXONOMIES, true) && is_numeric($termId) && $this->enabled()) {
            $this->queueTermPosts((int) $termId, $taxonomy);
        }
    }

    /**
     * Sends what this request changed once nobody waits on it: inline in a
     * WP-Cron run; inline after RequestFinisher has sent the response; else
     * from a WP-Cron event (schedule(), TERMS_HOOK for the category and tag
     * lists, BACKFILL_HOOK for the backfill). The backfill an admin request
     * started goes after the response too. Hooked to shutdown by
     * flushAtShutdown().
     */
    public function flush(): void
    {
        if ($this->cancelled) {
            return;
        }

        $queue = $this->queue;
        $terms = $this->termsChanged;
        $backfill = $this->backfillDue;
        $this->queue = [];
        $this->termsChanged = false;
        $this->backfillDue = false;

        if ($queue || $terms || $backfill) {
            if ($this->finisher->inCron()) {
                $this->send($queue, $terms, $backfill);
            } elseif ($this->finisher->available()) {
                $this->finisher->finish();
                $this->send($queue, $terms, $backfill);
            } elseif (function_exists('wp_schedule_single_event')) {
                if ($queue) {
                    $this->schedule($queue);
                }
                // The event reads the lists when it runs, so a pending one
                // carries this change too.
                if ($terms && !wp_next_scheduled(self::TERMS_HOOK)) {
                    wp_schedule_single_event(time(), self::TERMS_HOOK);
                }
                if ($backfill && !wp_next_scheduled(self::BACKFILL_HOOK)) {
                    wp_schedule_single_event(time(), self::BACKFILL_HOOK);
                }
            } else {
                $this->send($queue, $terms, $backfill);
            }
        }

        if ($this->dropped > 0) {
            Logger::debug('ContentPublisher: dropped ' . $this->dropped . ' post(s) past what one push sends; the hourly sync finds again only those modified in the last day, or published in it if GRO never took them');
            $this->dropped = 0;
        }
    }

    /**
     * Sends GRO each post as it is now: a public one as publish (when it was
     * noted as one) or update, any other — unpublished, deleted, given a
     * password — as unpublish; BATCH_SIZE to a request, one action each. A
     * batch GRO accepts moves its posts' SYNCED_META; one it does not is
     * dropped. The hourly reconcile finds again only the posts modified in
     * the last day, and those GRO never took published in it: not a deleted
     * post, nor one sent for a renamed or deleted term, a gating change or a
     * recipe save. A public post with no http(s) URL or publish date to send
     * is marked without GRO taking it, so the reconcile's posts GRO never
     * took leave it until it is edited. With sending off, a deleted post is
     * noted instead (DELETED_OPTION): the push may be an event WP-Cron ran
     * after sending stopped, and nothing could find the post again.
     *
     * @param mixed $queue Post id => action, as queue() notes them; PUSH_HOOK's argument.
     * @return array{accepted: int, failed: int, skipped: int, error: string}
     *         The posts in batches GRO took, those it did not take or never
     *         got (a bad key or a rate limit stops the rest), those that
     *         could not be sent, and its last refusal, '' for none.
     */
    public function push($queue): array
    {
        $result = ['accepted' => 0, 'failed' => 0, 'skipped' => 0, 'error' => ''];
        if (!is_array($queue) || !$queue) {
            return $result;
        }

        if (!$this->enabled()) {
            self::recordGone($queue);

            return $result;
        }

        $batches = ['publish' => [], 'update' => [], 'unpublish' => []];
        $modified = [];

        // As a visitor, as WP-Cron builds them: a push that runs in the
        // request of a signed-in editor (a save, the backfill) would get what
        // a content gate shows editors from any excerpt filter that renders
        // the post's content.
        $user = get_current_user_id();
        if ($user !== 0) {
            wp_set_current_user(0);
        }

        try {
            foreach ($queue as $postId => $action) {
                $postId = (int) $postId;
                if ($postId <= 0) {
                    continue;
                }

                $post = get_post($postId);
                if (!PostPayload::isPublic($post)) {
                    $batches['unpublish'][$postId] = ['sourceKey' => PostPayload::sourceKey($postId)];
                    continue;
                }

                $entry = $this->payload->post($post);
                if ($entry === null) {
                    update_post_meta($postId, self::SYNCED_META, (string) ($post->post_modified_gmt ?? ''));
                    $result['skipped']++;
                    Logger::debug('ContentPublisher: post ' . $postId . ' has no http(s) URL or publish date to send');
                    continue;
                }

                $batches[$action === 'publish' ? 'publish' : 'update'][$postId] = $entry;
                $modified[$postId] = (string) ($post->post_modified_gmt ?? '');
            }
        } finally {
            if ($user !== 0) {
                wp_set_current_user($user);
            }
        }

        $total = array_sum(array_map('count', $batches));

        foreach ($batches as $action => $entries) {
            foreach (array_chunk($entries, self::BATCH_SIZE, true) as $batch) {
                $response = ApiClient::postContentPosts(array_values($batch), $action);

                if (is_wp_error($response)) {
                    Logger::debug('ContentPublisher: GRO did not take ' . count($batch) . ' post(s) to ' . $action . ': ' . $response->get_error_message());
                    $result['failed'] += count($batch);
                    $result['error'] = $response->get_error_message();

                    if (self::stops($response)) {
                        $result['failed'] = $total - $result['accepted'];

                        return $result;
                    }

                    continue;
                }

                foreach (array_keys($batch) as $sentId) {
                    if ($action === 'unpublish') {
                        delete_post_meta($sentId, self::SYNCED_META);
                    } else {
                        update_post_meta($sentId, self::SYNCED_META, $modified[$sentId]);
                    }
                }

                $result['accepted'] += count($batch);
                PluginSettings::setContentLastSyncAt(time());
                Logger::debug('ContentPublisher: GRO took ' . count($batch) . ' post(s) to ' . $action);
            }
        }

        return $result;
    }

    /**
     * Sends GRO the site's category and tag names, every one whether or not
     * a post has it, as the Digest setup lists them: named as a post's own
     * are (PostPayload::names()), since a Digest's filter matches posts by
     * name. Each list is the site's whole list, which GRO takes in place of
     * the one it held: MAX_TERMS of each at most, the most used kept.
     * TERMS_HOOK's handler.
     *
     * @return array<string, mixed>|\WP_Error|null GRO's answer, as
     *         ApiClient::postContentTerms() reads it; null when nothing was sent.
     */
    public function pushTerms()
    {
        // The lists read now hold every change so far, so the shutdown flush
        // need not send them again.
        $this->termsChanged = false;

        // A site that sends no posts (grocerslist_content_post_types) sends
        // no names either.
        if (!$this->enabled() || !$this->postTypes()) {
            return null;
        }

        $response = ApiClient::postContentTerms(self::termNames('category'), self::termNames('post_tag'));
        Logger::debug(is_wp_error($response)
            ? 'ContentPublisher: GRO did not take the categories and tags: ' . $response->get_error_message()
            : 'ContentPublisher: GRO took the categories and tags');

        return $response;
    }

    /**
     * The hourly catch-up over what changed in the last day, RECONCILE_POSTS
     * of each kind a run at most, the rest left for the next run. First the
     * public posts GRO never took, then those GRO is behind on (stale()),
     * read from the RECONCILE_SCAN newest it took, so a run reaches past the
     * ones it is up to date on. Apart from those, a post GRO holds that has
     * been unpublished since goes as unpublish. GRO takes all of them as
     * idempotent. The category and tag lists go first, whole, whatever
     * changed: a change whose push GRO did not take is in them; then the
     * posts deleted while sending was off (pushDeleted()). A bad key or a
     * rate limit on either ends the run, the posts' push certain to get the
     * same answer.
     *
     * After sending stopped (CATCH_UP_OPTION), the last day reaches back to
     * the day before it stopped, and the posts GRO took before then and that
     * changed since are read as well: each is stale, and drops out once sent,
     * so the runs reach every one past the newest RECONCILE_SCAN. The first
     * run that finds nothing to send ends the catch-up (endCatchUp()).
     */
    public function reconcile(): void
    {
        $types = $this->postTypes();
        // An empty post_type is WP_Query's default, posts.
        if (!$types || !$this->enabled()) {
            return;
        }

        if (self::stops($this->pushTerms()) || !$this->pushDeleted()) {
            Logger::debug('ContentPublisher: the hourly sync leaves the posts to its next run');

            return;
        }

        $note = self::catchUpNote();
        $stopped = self::catchUpSince($note);
        $since = gmdate('Y-m-d H:i:s', ($stopped > 0 ? min($stopped, time()) : time()) - self::RECONCILE_WINDOW);
        $published = ['post_type' => $types, 'post_status' => 'publish'];

        // A scheduled post goes live with the publish date it was given, its
        // modified date left as it was.
        $queue = array_fill_keys(self::recentIds(
            $published + ['has_password' => false, 'meta_query' => self::synced('NOT EXISTS')],
            $since,
            self::RECONCILE_POSTS,
            ['post_modified_gmt', 'post_date_gmt']
        ), 'update');

        if (count($queue) < self::RECONCILE_POSTS) {
            $taken = self::recentIds($published + ['meta_query' => self::synced('EXISTS')], $since, self::RECONCILE_SCAN);
            if ($stopped > 0) {
                $before = gmdate('Y-m-d H:i:s', $stopped);
                $taken = array_values(array_unique(array_merge($taken, self::recentIds(
                    $published + ['meta_query' => [['key' => self::SYNCED_META, 'value' => $before, 'compare' => '<']]],
                    $before,
                    self::RECONCILE_SCAN
                ))));
            }

            $queue += self::stale($taken, self::RECONCILE_POSTS - count($queue));
        }

        $unpublished = self::recentIds(
            ['post_type' => $types, 'post_status' => self::UNPUBLISHED_STATUSES, 'meta_query' => self::synced('EXISTS')],
            $since,
            self::RECONCILE_POSTS
        );
        foreach ($unpublished as $postId) {
            $queue[$postId] = 'unpublish';
        }

        $sent = $this->push($queue);

        if ($stopped > 0 && $sent['accepted'] + $sent['failed'] + $sent['skipped'] === 0) {
            self::endCatchUp($note);
        }
    }

    /**
     * Notes that sending stopped, for the reconcile to reach back to once it
     * is on again, as "<earliest>:<latest>": the first stop while a catch-up
     * is still to run, which the reconcile reaches back to, and this one, so
     * that every stop changes the note (endCatchUp()).
     */
    public static function noteSendingStopped(): void
    {
        $earliest = self::catchUpSince(self::catchUpNote());
        update_option(self::CATCH_UP_OPTION, ($earliest > 0 ? $earliest : time()) . ':' . time(), false);
    }

    /**
     * Runs the reconcile soon rather than at its next hour: its catch-up,
     * once sending is on again. WordPress refuses an event within ten minutes
     * of the same one, so an hourly run due by then stands in for it.
     */
    public static function syncSoon(): void
    {
        wp_schedule_single_event(time(), self::SYNC_HOOK);
    }

    /**
     * Whether a catch-up is still to run (CATCH_UP_OPTION).
     */
    public static function catchUpDue(): bool
    {
        return self::catchUpSince(self::catchUpNote()) > 0;
    }

    /**
     * The site's category and tag lists, then its newest public posts as
     * publish: what the creator's Digests start from. Done (BACKFILL_OPTION)
     * once GRO has taken the posts: lists it refuses are left to the hourly
     * reconcile, which sends them whole every run, unless the refusal is a
     * bad key or a rate limit, which the posts would get too, so they are not
     * sent and the try has failed. A failed try is tried again
     * (maybeScheduleBackfill()). Each run notes how it went for the plugin's
     * settings (backfillReport()), 'running' first: a run that dies on a PHP
     * error stays 'running', which tells it from one WP-Cron never ran. A
     * run whose tries another request reset meanwhile (resetBackfill()) or
     * started again notes nothing at the end: those are the tries that count.
     *
     * @return array<string, mixed>|\WP_Error|null GRO's answer to the lists,
     *         as pushTerms() returns it, for send() to hold the request's own
     *         posts back after; null when this run sent none.
     */
    public function backfill()
    {
        // A WP-Cron event left from before, or a second run, after one
        // succeeded would only send it all again, and a failure would mark
        // the done backfill failed.
        if (!$this->enabled() || self::backfillDone()) {
            return null;
        }

        $status = self::backfillStatus();
        // Tries reset after this run was started (a key saved in its
        // request), or a WP-Cron event left from before a reset: this run is
        // the first try, as BACKFILL_ATTEMPTS and the settings count them.
        $status['attempts'] = max(1, $status['attempts']);
        $run = wp_generate_password(20, false);
        self::saveBackfillStatus(['state' => 'running', 'at' => time(), 'run' => $run] + $status);

        $error = '';
        $accepted = 0;
        $terms = null;
        $types = $this->postTypes();

        // A site that sends no post types has nothing to backfill.
        if ($types) {
            $terms = $this->pushTerms();
            if (self::stops($terms)) {
                $error = rtrim($terms->get_error_message(), '.') . ' (categories and tags)';
            } else {
                $query = new \WP_Query([
                    'post_type'      => $types,
                    'post_status'    => 'publish',
                    'has_password'   => false,
                    'posts_per_page' => self::BACKFILL_POSTS,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);

                $ids = array_map('intval', is_array($query->posts) ? $query->posts : []);
                $sent = $this->push(array_fill_keys($ids, 'publish'));
                $accepted = $sent['accepted'];
                if ($sent['failed'] > 0) {
                    $error = rtrim($sent['error'], '.') . ' (posts)';
                }
            }
        }

        if (!self::runIsCurrent($run)) {
            Logger::debug('ContentPublisher: the backfill was reset or tried again while this run went; it notes nothing');

            return $terms;
        }

        if ($error === '') {
            update_option(self::BACKFILL_OPTION, self::BACKFILL_VERSION);
        }

        self::saveBackfillStatus([
            'state' => $error === '' ? 'done' : 'failed',
            'at'    => time(),
            'posts' => $accepted,
            'error' => $error,
        ] + $status);

        return $terms;
    }

    /**
     * Starts backfill() on admin_init, which runs after every way of
     * updating the plugin, until a run succeeds: from this request once its
     * response has gone, where RequestFinisher can send it first, as flush()
     * sends saved posts; else as a WP-Cron event, which may not run for
     * hours on a site with little traffic its page cache does not answer. A
     * run that failed, never finished, or never started — WP-Cron loses an
     * event when a run that began before it was scheduled writes its own copy
     * of the event list back — is tried again an hour on, the same way,
     * BACKFILL_ATTEMPTS times in all, its reason kept. An event still waiting
     * is left to WP-Cron. Nothing starts while sync is off or no key is saved;
     * saving a key starts over (resetBackfill()), so a newly connected GRO
     * account gets the posts too.
     */
    public function maybeScheduleBackfill(): void
    {
        if (self::backfillDone() || !$this->enabled()) {
            return;
        }

        $status = self::backfillStatus();
        if (($status['state'] === 'scheduled' && wp_next_scheduled(self::BACKFILL_HOOK))
            || ($status['at'] > 0 && time() - $status['at'] < self::BACKFILL_RETRY_AFTER)) {
            return;
        }

        // An hour on, a try that never started or never finished has failed.
        $stale = in_array($status['state'], ['scheduled', 'running'], true);
        if ($status['state'] === 'scheduled') {
            $status['error'] = 'WP-Cron never ran it';
        } elseif ($status['state'] === 'running') {
            $status['error'] = 'it stopped before it finished (look for a PHP error or time limit in the site\'s error log)';
        }

        if ($status['attempts'] >= self::BACKFILL_ATTEMPTS) {
            if ($stale) {
                self::saveBackfillStatus(['state' => 'failed', 'at' => time()] + $status);
            }

            return;
        }

        // A page and the heartbeat it fires can both get here.
        if (!self::claimBackfill()) {
            return;
        }

        $attempt = ['at' => time(), 'attempts' => $status['attempts'] + 1] + $status;

        // Running from the start: the settings page this request may be
        // drawing says so, and a run that dies stays 'running'.
        if ($this->finisher->available()) {
            self::saveBackfillStatus(['state' => 'running'] + $attempt);
            $this->backfillDue = true;
            $this->flushAtShutdown();

            return;
        }

        self::saveBackfillStatus(['state' => 'scheduled'] + $attempt);

        if (function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled(self::BACKFILL_HOOK)) {
                wp_schedule_single_event(time(), self::BACKFILL_HOOK);
            }

            return;
        }

        // No WP-Cron available: run inline as a last resort.
        $this->backfill();
    }

    public static function resetBackfill(): void
    {
        delete_option(self::BACKFILL_OPTION);
        delete_option(self::BACKFILL_STATUS_OPTION);
        delete_option(self::BACKFILL_LOCK_OPTION);
    }

    /**
     * How the backfill went, for the plugin's settings page; null before it
     * was first tried. at is when it reached state: done, failed, running
     * (also while it waits for the response that started it to go), or
     * scheduled (waiting for the WP-Cron fallback); error names why the last
     * try failed, while the next one waits or runs; retryAt is when a failed
     * one is tried again, 0 for never.
     *
     * @return array{state: string, at: int, posts: int, error: string, attempts: int, retryAt: int}|null
     */
    public static function backfillReport(): ?array
    {
        $status = self::backfillStatus();
        if ($status['state'] === '') {
            return null;
        }

        $retries = $status['state'] === 'failed' && $status['attempts'] < self::BACKFILL_ATTEMPTS;

        return [
            'state'    => $status['state'],
            'at'       => $status['at'],
            'posts'    => $status['posts'],
            'error'    => $status['error'],
            'attempts' => $status['attempts'],
            'retryAt'  => $retries ? $status['at'] + self::BACKFILL_RETRY_AFTER : 0,
        ];
    }

    public function scheduleSync(): void
    {
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::SYNC_HOOK);
        }
    }

    /**
     * The public post types the site's search shows, and posts and pages
     * whatever a site does to its search, bar attachments and WPRM's recipes:
     * a type kept out of search (a page builder's templates, say) holds
     * nothing a reader looks for. grocerslist_content_post_types changes the
     * list.
     *
     * @return array<int, string>
     */
    private function postTypes(): array
    {
        $searchable = array_values(get_post_types(['public' => true, 'exclude_from_search' => false]));
        $types = [];

        foreach (array_values(get_post_types(['public' => true])) as $type) {
            if (!in_array($type, self::NEVER_SENT_TYPES, true)
                && (in_array($type, self::ALWAYS_SENT_TYPES, true) || in_array($type, $searchable, true))) {
                $types[] = $type;
            }
        }

        return array_values(array_filter((array) apply_filters('grocerslist_content_post_types', $types), 'is_string'));
    }

    /**
     * Whether sending is on and $post is one this sends (sendable()).
     *
     * @param mixed $post
     */
    private function tracks($post): bool
    {
        return $this->enabled() && $this->sendable($post);
    }

    /**
     * Whether $post is one this sends while sending is on: of a type
     * postTypes() names, and neither an autosave nor a revision.
     *
     * @param mixed $post
     */
    private function sendable($post): bool
    {
        if (!is_object($post) || !isset($post->ID, $post->post_type)) {
            return false;
        }

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision((int) $post->ID)
            || wp_is_post_autosave((int) $post->ID)) {
            return false;
        }

        return in_array($post->post_type, $this->postTypes(), true);
    }

    private function enabled(): bool
    {
        return PluginSettings::getContentSyncEnabled() && PluginSettings::getApiKey() !== '';
    }

    /**
     * Notes a change for flush(). A publish stays a publish for the rest of
     * the request; posts past MAX_QUEUE are dropped, for the hourly reconcile
     * to find again if they were modified in the last day, or published in it
     * and never taken by GRO.
     */
    private function queue(int $postId, string $action): void
    {
        if ($postId <= 0) {
            return;
        }

        if (!isset($this->queue[$postId]) && count($this->queue) >= self::MAX_QUEUE) {
            $this->dropped++;

            return;
        }

        if (($this->queue[$postId] ?? '') !== 'publish') {
            $this->queue[$postId] = $action;
        }

        $this->flushAtShutdown();
    }

    /**
     * Notes a category's or tag's public posts as updates, newest first, as
     * many as MAX_QUEUE: a change to the term moves no post's
     * post_modified_gmt, so the reconcile would never send them. The posts
     * that have the term itself, not a child category's.
     */
    private function queueTermPosts(int $termId, string $taxonomy): void
    {
        $types = $this->postTypes();
        // An empty post_type is WP_Query's default, posts.
        if ($termId <= 0 || !$types) {
            return;
        }

        $by = $taxonomy === 'post_tag' ? 'tag__in' : 'category__in';
        $query = new \WP_Query([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'has_password'   => false,
            $by              => [$termId],
            'posts_per_page' => self::MAX_QUEUE,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach (is_array($query->posts) ? $query->posts : [] as $postId) {
            $this->queue((int) $postId, 'update');
        }
    }

    private function flushAtShutdown(): void
    {
        if (!$this->shutdownHooked) {
            // Last, as FormConfigCache's refresh: WordPress's own shutdown
            // work goes first, the output buffer a page cache saves among it.
            add_action('shutdown', [$this, 'flush'], PHP_INT_MAX);
            $this->shutdownHooked = true;
        }
    }

    /**
     * @param array<int, string> $queue
     */
    private function send(array $queue, bool $terms, bool $backfill): void
    {
        // The backfill sends the category and tag lists first, as they are
        // now, so a change in this request goes with it rather than again.
        $lists = null;
        if ($backfill) {
            $lists = $this->backfill();
        } elseif ($terms) {
            $lists = $this->pushTerms();
        }

        if (!$queue) {
            return;
        }

        // A bad key or a rate limit on the lists would refuse the posts too.
        if (self::stops($lists)) {
            Logger::debug('ContentPublisher: dropped the ' . count($queue) . ' post(s) changed in this request; the hourly sync finds again only those modified in the last day, or published in it if GRO never took them');

            return;
        }

        $this->push($queue);
    }

    /**
     * Sends GRO the posts deleted while sending was off (DELETED_OPTION) as
     * unpublish, BATCH_SIZE to a request; each batch GRO takes leaves the
     * record, and one it does not stays for the next run.
     *
     * @return bool False when GRO answered a bad key or a rate limit, or did
     *              not answer at all: the rest would fare no better.
     */
    private function pushDeleted(): bool
    {
        foreach (array_chunk(self::deletedKeys(), self::BATCH_SIZE) as $batch) {
            $response = ApiClient::postContentPosts(array_map(function (string $sourceKey): array {
                return ['sourceKey' => $sourceKey];
            }, $batch), 'unpublish');

            if (is_wp_error($response)) {
                Logger::debug('ContentPublisher: GRO did not take ' . count($batch) . ' deleted post(s): ' . $response->get_error_message());
                if (self::stops($response) || self::status($response) === 0) {
                    return false;
                }

                continue;
            }

            self::forgetDeleted($batch);
            PluginSettings::setContentLastSyncAt(time());
            Logger::debug('ContentPublisher: GRO took ' . count($batch) . ' deleted post(s) to unpublish');
        }

        return true;
    }

    /**
     * Leaves $queue to a WP-Cron event. While one is pending already, its
     * posts and these go together in it instead, as many as MAX_QUEUE: one
     * event in the autoloaded cron option however many saves wait on it, and
     * nothing dropped that the reconcile could not find again — a deleted
     * post, or a recipe or category change that moves no post_modified_gmt.
     *
     * @param array<int, string> $queue
     */
    private function schedule(array $queue): void
    {
        $pending = self::pendingPush();
        if ($pending === null) {
            wp_schedule_single_event(time(), self::PUSH_HOOK, [$queue]);

            return;
        }

        [$timestamp, $carried] = $pending;
        $merged = $carried;
        foreach ($queue as $postId => $action) {
            if (!isset($merged[$postId]) && count($merged) >= self::MAX_QUEUE) {
                $this->dropped++;
                continue;
            }

            if (($merged[$postId] ?? '') !== 'publish') {
                $merged[$postId] = $action;
            }
        }

        if ($merged !== $carried) {
            wp_unschedule_event($timestamp, self::PUSH_HOOK, [$carried]);
            wp_schedule_single_event($timestamp, self::PUSH_HOOK, [$merged]);
        }
    }

    /**
     * The push event waiting to run, whatever posts it carries. WP-Cron takes
     * an event out of the list before it runs it, so a running push is not
     * pending.
     *
     * @return array{0: int, 1: array<int, string>}|null Its time and its posts.
     */
    private static function pendingPush(): ?array
    {
        return self::pendingPushes()[0] ?? null;
    }

    /**
     * Every push event waiting to run, in the cron list's order.
     *
     * @return array<int, array{0: int, 1: array<int, string>}> Each one's time and posts.
     */
    private static function pendingPushes(): array
    {
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
        $pending = [];

        foreach (is_array($cron) ? $cron : [] as $timestamp => $hooks) {
            $events = is_array($hooks) && isset($hooks[self::PUSH_HOOK]) && is_array($hooks[self::PUSH_HOOK])
                ? $hooks[self::PUSH_HOOK]
                : [];

            foreach ($events as $event) {
                $carried = is_array($event) && isset($event['args'][0]) ? $event['args'][0] : null;
                if (is_array($carried)) {
                    $pending[] = [(int) $timestamp, $carried];
                }
            }
        }

        return $pending;
    }

    /**
     * Of $ids, posts GRO took before, newest first, those it is behind on, as
     * many as $room: a public post changed since GRO took it, as update, and
     * one given a password since, as unpublish. Read BATCH_SIZE at a time,
     * each lot's posts and meta primed in two queries, until $room is full.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private static function stale(array $ids, int $room): array
    {
        $queue = [];

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            if (function_exists('_prime_post_caches')) {
                _prime_post_caches($chunk, false, true);
            }

            foreach ($chunk as $postId) {
                $post = get_post($postId);
                if (!is_object($post)) {
                    continue;
                }

                if (!PostPayload::isPublic($post)) {
                    $queue[$postId] = 'unpublish';
                } elseif ((string) get_post_meta($postId, self::SYNCED_META, true) < (string) ($post->post_modified_gmt ?? '')) {
                    $queue[$postId] = 'update';
                } else {
                    continue;
                }

                if (count($queue) >= $room) {
                    return $queue;
                }
            }
        }

        return $queue;
    }

    /**
     * @param array<string, mixed> $args What picks the posts: post_type, post_status and more.
     * @param array<int, string> $columns The GMT dates, any of which after $since will do.
     * @return array<int, int> Those posts with one after $since, the most
     *                         recently modified first, at most $limit.
     */
    private static function recentIds(array $args, string $since, int $limit, array $columns = ['post_modified_gmt']): array
    {
        // An absolute time: WordPress reads a relative one ("1 day ago") in
        // the site's timezone, not the GMT column's.
        $after = array_map(function (string $column) use ($since): array {
            return ['column' => $column, 'after' => $since];
        }, $columns);

        $query = new \WP_Query($args + [
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'date_query'     => count($after) > 1 ? ['relation' => 'OR'] + $after : $after,
        ]);

        return array_map('intval', is_array($query->posts) ? $query->posts : []);
    }

    /**
     * @return array<int, array{key: string, compare: string}>
     */
    private static function synced(string $compare): array
    {
        return [['key' => self::SYNCED_META, 'compare' => $compare]];
    }

    /**
     * Every name of $taxonomy, those on no post too, the most used first.
     *
     * @return array<int, string>
     */
    private static function termNames(string $taxonomy): array
    {
        // The two-argument form: before 4.5, WordPress reads an array alone
        // as taxonomy names.
        $names = get_terms($taxonomy, [
            'hide_empty'             => false,
            'orderby'                => 'count',
            'order'                  => 'DESC',
            'number'                 => self::MAX_TERMS,
            'fields'                 => 'names',
            'update_term_meta_cache' => false,
        ]);

        return is_array($names) ? PostPayload::names($names, self::MAX_TERMS) : [];
    }

    /**
     * A category's or tag's name as WordPress stores it; null for no term.
     */
    private static function termName(int $termId, string $taxonomy): ?string
    {
        $term = get_term($termId, $taxonomy);

        return is_object($term) && isset($term->name) ? (string) $term->name : null;
    }

    /**
     * Whether GRO's answer is one the rest of a push would get too
     * (STOP_STATUSES): a bad key or a rate limit.
     *
     * @param mixed $response
     */
    private static function stops($response): bool
    {
        return in_array(self::status($response), self::STOP_STATUSES, true);
    }

    /**
     * The HTTP status a refusal from GRO carries; 0 for none, as for a
     * request that never got an answer.
     *
     * @param mixed $response
     */
    private static function status($response): int
    {
        $data = is_wp_error($response) ? $response->get_error_data() : null;

        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /**
     * Whether this request is the one to start a try: an INSERT IGNORE on
     * the option's unique name, as WP_Upgrader::create_lock() takes its lock
     * (add_option() updates a row another request just added, so both would
     * win), held for BACKFILL_LOCK_TTL; past that, whichever request moves
     * the time on first. Read and written past the options cache.
     */
    private static function claimBackfill(): bool
    {
        global $wpdb;

        $table = $wpdb->options;
        $now = (string) time();

        if ($wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `$table` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            self::BACKFILL_LOCK_OPTION,
            $now
        ))) {
            return true;
        }

        $held = (string) self::storedOption(self::BACKFILL_LOCK_OPTION);
        if ((int) $held > (int) $now - self::BACKFILL_LOCK_TTL) {
            return false;
        }

        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
            $now,
            self::BACKFILL_LOCK_OPTION,
            $held
        ));
    }

    /**
     * Whether the tries kept are still those the run holding $run wrote: a
     * token, not the run's start time, which a reset and a new try can share
     * to the second. Read past the options cache, as claimBackfill() reads
     * the lock: this request's cache still holds them after another request's
     * resetBackfill() deleted them, or a newer try replaced them.
     */
    private static function runIsCurrent(string $run): bool
    {
        $kept = self::storedOption(self::BACKFILL_STATUS_OPTION);
        $kept = $kept === null ? null : maybe_unserialize($kept);

        return is_array($kept) && ($kept['run'] ?? null) === $run;
    }

    /**
     * An option's row as the database holds it, past the options cache,
     * which may hold what another request has changed since; null for none.
     */
    private static function storedOption(string $name): ?string
    {
        global $wpdb;

        $table = $wpdb->options;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT `option_value` FROM `$table` WHERE `option_name` = %s",
            $name
        ));

        return is_string($value) ? $value : null;
    }

    /**
     * Writes an option's row, or deletes it for a null $value, only while it
     * still holds $was, or while there is none for a null $was, as
     * claimBackfill() takes and moves the lock: false when another request
     * changed it first. The options cache forgets it either way.
     */
    private static function swapOption(string $name, ?string $was, ?string $value): bool
    {
        global $wpdb;

        $table = $wpdb->options;
        if ($was === null) {
            $swapped = $value === null || $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `$table` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
                $name,
                $value
            ));
            // As add_option() does: this request may have cached that there was none.
            $missing = wp_cache_get('notoptions', 'options');
            if (is_array($missing) && isset($missing[$name])) {
                unset($missing[$name]);
                wp_cache_set('notoptions', $missing, 'options');
            }
        } elseif ($value === null) {
            $swapped = $wpdb->query($wpdb->prepare(
                "DELETE FROM `$table` WHERE `option_name` = %s AND `option_value` = %s",
                $name,
                $was
            ));
        } else {
            $swapped = $wpdb->query($wpdb->prepare(
                "UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
                $value,
                $name,
                $was
            ));
        }
        wp_cache_delete($name, 'options');

        return (bool) $swapped;
    }

    /**
     * The note noteSendingStopped() keeps (CATCH_UP_OPTION); '' for none.
     */
    private static function catchUpNote(): string
    {
        return (string) get_option(self::CATCH_UP_OPTION, '');
    }

    /**
     * When sending first stopped, as $note has it; 0 for no catch-up.
     */
    private static function catchUpSince(string $note): int
    {
        return max(0, (int) explode(':', $note)[0]);
    }

    /**
     * Ends the catch-up the reconcile read as $note, unless the note changed
     * or went since: sending stopped again, which the next run catches up on,
     * or an overlapping run ended the catch-up first.
     */
    private static function endCatchUp(string $note): void
    {
        Logger::debug(self::swapOption(self::CATCH_UP_OPTION, $note, null)
            ? 'ContentPublisher: the hourly sync caught up on the time sending was off'
            : 'ContentPublisher: the catch-up note changed or went while this run caught up (sending stopped again, or another run ended it); left as it is');
    }

    /**
     * @return array<int, string> The source keys DELETED_OPTION keeps, oldest first.
     */
    private static function deletedKeys(): array
    {
        return self::sourceKeys(get_option(self::DELETED_OPTION, []));
    }

    /**
     * @param mixed $kept DELETED_OPTION's value.
     * @return array<int, string>
     */
    private static function sourceKeys($kept): array
    {
        return is_array($kept) ? array_values(array_filter($kept, 'is_string')) : [];
    }

    /**
     * Adds deleted posts to DELETED_OPTION, which keeps the newest
     * MAX_DELETED: one deleted before them is left in GRO.
     *
     * @param array<int, string> $sourceKeys
     */
    private static function recordDeleted(array $sourceKeys): void
    {
        if ($sourceKeys) {
            self::rewriteDeleted(function (array $kept) use ($sourceKeys): array {
                return array_slice(array_merge(array_values(array_diff($kept, $sourceKeys)), $sourceKeys), -self::MAX_DELETED);
            });
        }
    }

    /**
     * Notes the posts of $queue that are gone (deleted) in DELETED_OPTION:
     * a push that cannot go now would lose them.
     *
     * @param array<int|string, mixed> $queue Post id => action.
     */
    private static function recordGone(array $queue): void
    {
        $gone = [];
        foreach (array_keys($queue) as $postId) {
            if ((int) $postId > 0 && get_post((int) $postId) === null) {
                $gone[] = PostPayload::sourceKey((int) $postId);
            }
        }

        self::recordDeleted($gone);
    }

    /**
     * Takes the posts GRO took off DELETED_OPTION.
     *
     * @param array<int, string> $sent Source keys GRO took.
     */
    private static function forgetDeleted(array $sent): void
    {
        self::rewriteDeleted(function (array $kept) use ($sent): array {
            return array_values(array_diff($kept, $sent));
        });
    }

    /**
     * Rewrites DELETED_OPTION as $change makes it of the source keys it
     * holds, read past the options cache and written only over the row as
     * read (swapOption()): what another request wrote in between, a run
     * emptying the record or a delete noting a post, is read again and kept,
     * though this request's cache holds the record as it was.
     *
     * @param callable $change array<int, string> => array<int, string>
     */
    private static function rewriteDeleted(callable $change): void
    {
        for ($tries = 3; $tries > 0; $tries--) {
            $was = self::storedOption(self::DELETED_OPTION);
            $kept = $was === null ? [] : self::sourceKeys(maybe_unserialize($was));
            $keys = $change($kept);
            if ($keys === $kept || self::swapOption(self::DELETED_OPTION, $was, $keys ? maybe_serialize($keys) : null)) {
                return;
            }
        }

        Logger::debug('ContentPublisher: other requests kept rewriting the deleted posts noted; left as they are');
    }

    private static function backfillDone(): bool
    {
        $done = (string) get_option(self::BACKFILL_OPTION, '');

        return $done !== '' && version_compare($done, self::BACKFILL_VERSION, '>=');
    }

    /**
     * The backfill's tries, for BACKFILL_VERSION: none yet when what is kept
     * was another version's. A running backfill()'s token (runIsCurrent())
     * is left out, so tries written from these are no run's.
     *
     * @return array{version: string, attempts: int, state: string, at: int, posts: int, error: string}
     *         state: '' (never tried), scheduled, running, done or failed;
     *         at: when it got there; posts: those GRO took in the last run;
     *         error: why the last try failed, '' if it did not.
     */
    private static function backfillStatus(): array
    {
        $kept = get_option(self::BACKFILL_STATUS_OPTION, []);
        if (!is_array($kept) || ($kept['version'] ?? '') !== self::BACKFILL_VERSION) {
            $kept = [];
        }

        return [
            'version'  => self::BACKFILL_VERSION,
            'attempts' => (int) ($kept['attempts'] ?? 0),
            'state'    => (string) ($kept['state'] ?? ''),
            'at'       => (int) ($kept['at'] ?? 0),
            'posts'    => (int) ($kept['posts'] ?? 0),
            'error'    => (string) ($kept['error'] ?? ''),
        ];
    }

    /**
     * @param array{version: string, attempts: int, state: string, at: int, posts: int, error: string, run?: string} $status
     */
    private static function saveBackfillStatus(array $status): void
    {
        update_option(self::BACKFILL_STATUS_OPTION, $status);
    }
}

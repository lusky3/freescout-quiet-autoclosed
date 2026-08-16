<?php

namespace Modules\QuietAutoClosed\Providers;

use App\Conversation;
use App\Subscription;
use App\User;
use Illuminate\Support\ServiceProvider;
use Modules\QuietAutoClosed\Services\SuppressionDecider;

/**
 * FreeScout registers the "new conversation" notification in
 * FetchEmails.php:1329, one line before the Workflows module runs at :1330,
 * and nothing downstream reconsiders it: the listener only bails out on
 * STATUS_SPAM, and neither Subscription::usersToNotify() nor the queued
 * SendNotificationToUsers job re-reads the status. Core documents the
 * behaviour itself, at Subscription.php:16 - "Changing ticket status does not
 * fire event".
 *
 * A workflow that closes an incoming ticket therefore cannot silence that
 * ticket's own arrival alert. This module drops the recipients for that one
 * alert instead, at the point the pipeline picks them - inside
 * Subscription::processEvents(), which runs well after the workflow.
 *
 * @see https://github.com/lusky3/freescout-quiet-autoclosed
 */
class QuietAutoClosedServiceProvider extends ServiceProvider
{
    /**
     * @var bool
     */
    protected $defer = false;

    /**
     * The pseudo-user the Workflows module acts as. Conversation::setStatus()
     * records whoever closed a conversation in closed_by_user_id, and the
     * workflow close action passes this user, so "closed_by_user_id is the
     * workflow user" means precisely "a workflow closed this".
     *
     * Matched by email rather than by calling into the Workflows module, so
     * this module degrades to a no-op instead of a fatal error when Workflows
     * is absent or deactivated.
     *
     * @see \Modules\Workflows\Entities\Workflow::WF_USER_EMAIL
     */
    const WF_USER_EMAIL = 'fsworkflow@example.org';

    /**
     * Shared between both hooks so they agree, and so the second one costs no
     * queries. Static rather than container-bound because FreeScout resolves
     * providers once per process and never rebinds them.
     *
     * FreeScout runs under php-fpm and per-invocation artisan commands, so
     * this is request-scoped in practice. Under a persistent worker model
     * (Octane and the like) call flushCache() between requests.
     *
     * @var \Modules\QuietAutoClosed\Services\SuppressionDecider|null
     */
    protected static $decider = null;

    /**
     * Resolved id of the workflow pseudo-user, or null if there is no such
     * user (Workflows never installed or never run). `false` means "not looked
     * up yet" - null is a meaningful answer and must be cached too.
     *
     * @var int|null|false
     */
    protected static $workflow_user_id = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Module hooks.
     *
     * Both filters are evaluated inside Subscription::processEvents(), after
     * workflows have run. Emptying the recipient list there covers every
     * medium at once - email, browser push, the notifications menu, and the
     * MobileNotifications module, which reads the same $notify array via the
     * subscription.process_events action.
     *
     * Both bodies fail open. Core wraps neither \Eventy::filter() nor
     * processEvents(), so an exception escaping here would abort a whole
     * fetch-emails run; swallowing it costs at most one un-suppressed
     * notification.
     *
     * @return void
     */
    public function hooks()
    {
        // Core recipients, resolved in Subscription::usersToNotify().
        \Eventy::addFilter('subscription.subscriptions', function ($subscriptions, $conversation, $events, $thread) {
            try {
                if (self::decider()->shouldSuppress($events, $conversation)) {
                    return collect();
                }
            } catch (\Throwable $e) {
                self::logFailure($e);
            }

            return $subscriptions;
        }, 20, 4);

        // The last word, after other modules (Mentions, and so on) have had
        // their chance to add recipients of their own.
        \Eventy::addFilter('subscription.users_to_notify', function ($users_to_notify, $event_type, $events, $thread) {
            try {
                // Checked before the conversation relation is touched below:
                // that relation is lazy, and resolving it would put a query
                // on every reply, note and assignment notification - the
                // exact cost the decider's own event check exists to avoid.
                if ((int) $event_type !== (int) Subscription::EVENT_TYPE_NEW) {
                    return $users_to_notify;
                }

                // isset() rather than a bare property read: on an Eloquent
                // model it resolves the relation, and on anything else it
                // avoids an undefined-property warning.
                $conversation = (is_object($thread) && isset($thread->conversation)) ? $thread->conversation : null;

                if (self::decider()->shouldSuppress($events, $conversation)) {
                    return [];
                }
            } catch (\Throwable $e) {
                self::logFailure($e);
            }

            return $users_to_notify;
        }, 50, 4);

        // Fired at the end of Subscription::processEvents(). Resetting here
        // bounds the memo to a single notification pass, which is the only
        // scope its answers are guaranteed valid for - and keeps it from
        // growing for the length of a long freescout:fetch-emails run.
        \Eventy::addAction('subscription.process_events', function ($notify) {
            self::flushMemo();
        }, 50, 1);
    }

    /**
     * @return \Modules\QuietAutoClosed\Services\SuppressionDecider
     */
    public static function decider()
    {
        if (self::$decider === null) {
            self::$decider = new SuppressionDecider(
                function ($conversation_id) {
                    return self::readConversationState($conversation_id);
                },
                Subscription::EVENT_NEW_CONVERSATION
            );
        }

        return self::$decider;
    }

    /**
     * Read the two facts the decision needs, in one query.
     *
     * Read from the database rather than from the model handed to the
     * notification pipeline, because that object is not guaranteed to reflect
     * the workflow's changeStatus() call - and because closed_by_user_id is
     * not loaded on it at all.
     *
     * @param int $conversation_id
     *
     * @return array{closed: bool, closed_by_workflow: bool}
     */
    public static function readConversationState($conversation_id)
    {
        $none = ['closed' => false, 'closed_by_workflow' => false];

        $conversation = Conversation::where('id', $conversation_id)
            ->select('status', 'closed_by_user_id')
            ->first();

        if ($conversation === null) {
            return $none;
        }

        if ((int) $conversation->status !== (int) Conversation::STATUS_CLOSED) {
            return $none;
        }

        $workflow_user_id = self::workflowUserId();

        return [
            'closed' => true,
            'closed_by_workflow' => $workflow_user_id !== null
                && $conversation->closed_by_user_id !== null
                && (int) $conversation->closed_by_user_id === $workflow_user_id,
        ];
    }

    /**
     * Id of the workflow pseudo-user, looked up once per process.
     *
     * @return int|null
     */
    public static function workflowUserId()
    {
        if (self::$workflow_user_id === false) {
            $id = User::where('email', self::WF_USER_EMAIL)->value('id');

            self::$workflow_user_id = ($id === null) ? null : (int) $id;
        }

        return self::$workflow_user_id;
    }

    /**
     * @param \Throwable $e
     *
     * @return void
     */
    protected static function logFailure(\Throwable $e)
    {
        try {
            \Log::error('[QuietAutoClosed] failing open: ' . $e->getMessage());
        } catch (\Throwable $ignored) {
            // Logging must never be the thing that breaks notifications.
        }
    }

    /**
     * Drop memoized per-conversation decisions, keeping the workflow user id.
     *
     * @return void
     */
    public static function flushMemo()
    {
        if (self::$decider !== null) {
            self::$decider->flush();
        }
    }

    /**
     * Drop everything cached in this process, including the workflow user id.
     * For tests, and for persistent worker models that reuse the process
     * across requests.
     *
     * @return void
     */
    public static function flushCache()
    {
        self::flushMemo();

        self::$workflow_user_id = false;
        self::$decider = null;
    }
}

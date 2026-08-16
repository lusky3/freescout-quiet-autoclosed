<?php

namespace Modules\QuietAutoClosed\Services;

/**
 * Decides whether a pending FreeScout notification should be silenced.
 *
 * All of the framework-facing work - reading the conversation, registering
 * hooks - lives in the service provider. This class holds only the decision,
 * and reaches the database through an injected callable, so the rules below
 * can be exercised without booting Laravel or FreeScout.
 *
 * The rule, in one sentence: silence a "new conversation" alert when the
 * conversation is already closed *and* an automatic workflow is what closed
 * it.
 */
class SuppressionDecider
{
    /**
     * Reads persisted conversation state. Given a conversation id, returns an
     * array with two boolean keys:
     *
     *   closed             - the conversation is currently closed
     *   closed_by_workflow - the workflow pseudo-user is the one who closed it
     *
     * @var callable
     */
    private $readState;

    /**
     * The event id that means "a new conversation arrived" - FreeScout core's
     * \App\Subscription::EVENT_NEW_CONVERSATION. Injected rather than
     * hardcoded so this class never has to load a FreeScout class.
     *
     * @var int
     */
    private $newConversationEvent;

    /**
     * Decisions already made this pass, keyed by conversation id.
     *
     * Both hooks ask the same question about the same conversation during a
     * single Subscription::processEvents() pass, and the answer cannot change
     * within that pass - workflows have already run by then. The provider
     * flushes this at the end of each pass, so it never grows past the
     * conversations handled in one batch.
     *
     * @var array<int, bool>
     */
    private $memo = [];

    /**
     * @param callable $readState
     * @param int      $newConversationEvent
     */
    public function __construct(callable $readState, $newConversationEvent)
    {
        $this->readState = $readState;
        $this->newConversationEvent = (int) $newConversationEvent;
    }

    /**
     * Should the recipients for this notification be dropped?
     *
     * Deliberately conservative: anything unexpected - a missing
     * conversation, a malformed event list, a state reader that throws -
     * returns false and lets the notification through. Over-notifying is a
     * nuisance; under-notifying hides real tickets.
     *
     * @param mixed $events       Subscription event ids being notified about.
     * @param mixed $conversation The conversation, or null.
     *
     * @return bool
     */
    public function shouldSuppress($events, $conversation)
    {
        $conversation_id = $this->conversationId($conversation);

        if ($conversation_id === null) {
            return false;
        }

        // Checked before touching the database: the overwhelming majority of
        // notifications are replies, assignments and notes, and they must not
        // pay for a lookup they can never be affected by.
        if (!$this->isNewConversationEvent($events)) {
            return false;
        }

        if (array_key_exists($conversation_id, $this->memo)) {
            return $this->memo[$conversation_id];
        }

        try {
            $state = call_user_func($this->readState, $conversation_id);
        } catch (\Throwable $e) {
            // Not memoized: a transient database error should not pin a
            // "notify" answer for the rest of the pass.
            return false;
        }

        $suppress = is_array($state)
            && !empty($state['closed'])
            && !empty($state['closed_by_workflow']);

        $this->memo[$conversation_id] = $suppress;

        return $suppress;
    }

    /**
     * Forget memoized decisions. Called by the provider at the end of each
     * notification pass, and by tests.
     *
     * @return void
     */
    public function flush()
    {
        $this->memo = [];
    }

    /**
     * @param mixed $events
     *
     * @return bool
     */
    private function isNewConversationEvent($events)
    {
        if (!is_array($events)) {
            return false;
        }

        foreach ($events as $event) {
            // Core always supplies integer constants, but $events passes
            // through the public subscription.events_by_type filter, so
            // another module can reshape it. Anything that is not plainly a
            // number is not an event id: `true`, "1abc" and " 1" must not be
            // read as EVENT_NEW_CONVERSATION.
            if (!$this->isEventId($event)) {
                continue;
            }

            if ((int) $event === $this->newConversationEvent) {
                return true;
            }
        }

        return false;
    }

    /**
     * An event id is an integer, or a string of digits. Not a bool, not a
     * float, not a numeric-ish string.
     *
     * @param mixed $event
     *
     * @return bool
     */
    private function isEventId($event)
    {
        if (is_int($event)) {
            return true;
        }

        return is_string($event) && $event !== '' && ctype_digit($event);
    }

    /**
     * @param mixed $conversation
     *
     * @return int|null
     */
    private function conversationId($conversation)
    {
        if (!is_object($conversation)) {
            return null;
        }

        $id = isset($conversation->id) ? $conversation->id : null;

        // Integral only. Casting something like 7.9 would silently collide
        // with conversation 7 in the memo.
        if (!is_int($id) && !(is_string($id) && $id !== '' && ctype_digit($id))) {
            return null;
        }

        $id = (int) $id;

        return $id > 0 ? $id : null;
    }
}

<?php

namespace Tests\Unit;

use App\Subscription;
use Modules\QuietAutoClosed\Providers\QuietAutoClosedServiceProvider as Provider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExplodingThread;
use Tests\Support\FakeDb;
use Tests\Support\FakeThread;
use Tests\Support\HookRecorder;

/**
 * The provider is the half that can break an install, so it is booted for
 * real here against the doubles in Tests/Stubs, and the hooks it registers
 * are invoked rather than inspected.
 *
 * Query counts are asserted alongside return values: the module's promise is
 * not only "silences the right notifications" but "costs nothing on the
 * notifications it does not touch", and only a counter can hold it to that.
 */
class ProviderHooksTest extends TestCase
{
    private const WORKFLOW_USER_ID = 5;
    private const HUMAN_USER_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        HookRecorder::reset();
        FakeDb::reset();
        \Log::reset();
        Provider::flushCache();

        (new Provider(null))->boot();
    }

    // -- Helpers ----------------------------------------------------------

    private function workflowUserExists(): void
    {
        FakeDb::$users['fsworkflow@example.org'] = self::WORKFLOW_USER_ID;
    }

    private function conversation(int $id, int $status, $closed_by): object
    {
        FakeDb::$conversations[$id] = ['status' => $status, 'closed_by_user_id' => $closed_by];

        return (object) ['id' => $id];
    }

    private function closedByWorkflow(int $id = 42): object
    {
        $this->workflowUserExists();

        return $this->conversation($id, \App\Conversation::STATUS_CLOSED, self::WORKFLOW_USER_ID);
    }

    private function closedByHuman(int $id = 43): object
    {
        $this->workflowUserExists();

        return $this->conversation($id, \App\Conversation::STATUS_CLOSED, self::HUMAN_USER_ID);
    }

    private function open(int $id = 44): object
    {
        $this->workflowUserExists();

        return $this->conversation($id, \App\Conversation::STATUS_ACTIVE, null);
    }

    /**
     * @return mixed
     */
    private function subscriptions($conversation, array $events, $input = null)
    {
        $cb = HookRecorder::filter('subscription.subscriptions')['cb'];

        return $cb($input ?? collect(['recipient']), $conversation, $events, null);
    }

    /**
     * @return mixed
     */
    private function usersToNotify($thread, $event_type, array $events, array $input = ['recipient'])
    {
        $cb = HookRecorder::filter('subscription.users_to_notify')['cb'];

        return $cb($input, $event_type, $events, $thread);
    }

    // -- Registration -----------------------------------------------------

    public function test_boot_registers_both_filters_and_the_flush_action(): void
    {
        $this->assertCount(1, HookRecorder::$filters['subscription.subscriptions'] ?? []);
        $this->assertCount(1, HookRecorder::$filters['subscription.users_to_notify'] ?? []);
        $this->assertCount(1, HookRecorder::$actions['subscription.process_events'] ?? []);
    }

    public function test_subscriptions_filter_is_registered_with_four_arguments(): void
    {
        $registration = HookRecorder::filter('subscription.subscriptions');

        $this->assertSame(20, $registration['priority']);
        $this->assertSame(4, $registration['args']);
    }

    /**
     * Deliberately late: it must run after other modules (Mentions, and so
     * on) have added recipients of their own, so emptying the list is the
     * last word.
     */
    public function test_users_to_notify_filter_runs_after_the_default_priority(): void
    {
        $registration = HookRecorder::filter('subscription.users_to_notify');

        $this->assertSame(50, $registration['priority']);
        $this->assertSame(4, $registration['args']);
        $this->assertGreaterThan(20, $registration['priority']);
    }

    // -- subscription.subscriptions --------------------------------------

    public function test_drops_recipients_for_a_conversation_a_workflow_closed(): void
    {
        $result = $this->subscriptions($this->closedByWorkflow(), [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(0, $result);
    }

    public function test_keeps_recipients_for_a_conversation_a_human_closed(): void
    {
        $result = $this->subscriptions($this->closedByHuman(), [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(1, $result);
    }

    public function test_keeps_recipients_for_an_open_conversation(): void
    {
        $result = $this->subscriptions($this->open(), [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(1, $result);
    }

    public function test_keeps_recipients_for_events_other_than_new_conversation(): void
    {
        $result = $this->subscriptions($this->closedByWorkflow(), [Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]);

        $this->assertCount(1, $result);
    }

    /**
     * Workflows never installed: there is no pseudo-user to match, so nothing
     * can ever be attributed to a workflow and the module is inert.
     */
    public function test_suppresses_nothing_when_the_workflow_user_does_not_exist(): void
    {
        $conversation = $this->conversation(50, \App\Conversation::STATUS_CLOSED, self::HUMAN_USER_ID);

        $result = $this->subscriptions($conversation, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(1, $result);
    }

    public function test_keeps_recipients_when_the_conversation_is_missing_entirely(): void
    {
        $this->workflowUserExists();

        $result = $this->subscriptions((object) ['id' => 999], [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(1, $result);
    }

    // -- subscription.users_to_notify -------------------------------------

    public function test_users_to_notify_drops_recipients_for_a_workflow_closed_conversation(): void
    {
        $thread = new FakeThread($this->closedByWorkflow());

        $result = $this->usersToNotify($thread, Subscription::EVENT_TYPE_NEW, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertSame([], $result);
    }

    public function test_users_to_notify_keeps_recipients_for_a_human_closed_conversation(): void
    {
        $thread = new FakeThread($this->closedByHuman());

        $result = $this->usersToNotify($thread, Subscription::EVENT_TYPE_NEW, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertSame(['recipient'], $result);
    }

    /**
     * The filter is handed both $event_type and $events. The decision must
     * come from $events - transposing the two would silence replies.
     */
    public function test_users_to_notify_decides_on_the_events_list_not_the_event_type(): void
    {
        $thread = new FakeThread($this->closedByWorkflow());

        $result = $this->usersToNotify(
            $thread,
            Subscription::EVENT_TYPE_NEW,
            [Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]
        );

        $this->assertSame(['recipient'], $result);
    }

    /**
     * $thread->conversation is a lazy relation, so resolving it costs a
     * query. For an event this module can never act on it must not be
     * touched at all - the ExplodingThread turns any such access into a
     * failure.
     */
    public function test_users_to_notify_never_resolves_the_relation_for_unrelated_event_types(): void
    {
        $this->workflowUserExists();

        $result = $this->usersToNotify(
            new ExplodingThread(),
            Subscription::EVENT_TYPE_CUSTOMER_REPLIED,
            [Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]
        );

        $this->assertSame(['recipient'], $result);
        $this->assertSame(0, FakeDb::$reads, 'An unrelated event must cost no queries at all.');
        $this->assertSame([], \Log::$errors, 'Nothing should have failed.');
    }

    // -- Failing open -----------------------------------------------------

    public function test_fails_open_and_logs_when_the_thread_relation_throws(): void
    {
        $this->workflowUserExists();

        $result = $this->usersToNotify(
            new ExplodingThread(),
            Subscription::EVENT_TYPE_NEW,
            [Subscription::EVENT_NEW_CONVERSATION]
        );

        $this->assertSame(['recipient'], $result);
        $this->assertCount(1, \Log::$errors);
        $this->assertStringContainsString('QuietAutoClosed', \Log::$errors[0]);
    }

    public function test_fails_open_when_the_database_throws(): void
    {
        $conversation = $this->closedByWorkflow();
        FakeDb::$explode = true;

        $result = $this->subscriptions($conversation, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertCount(1, $result, 'A database failure must let the notification through.');
    }

    // -- Query economy ----------------------------------------------------

    public function test_costs_no_queries_for_events_it_cannot_act_on(): void
    {
        $this->subscriptions($this->closedByWorkflow(), [Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]);

        $this->assertSame(0, FakeDb::$reads);
    }

    /**
     * One conversation read plus one workflow-user read, and the user is not
     * looked up again for the next conversation.
     */
    public function test_looks_the_workflow_user_up_only_once(): void
    {
        $first = $this->closedByWorkflow(60);
        $second = $this->closedByWorkflow(61);

        $this->subscriptions($first, [Subscription::EVENT_NEW_CONVERSATION]);
        $this->subscriptions($second, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertSame(3, FakeDb::$reads, 'Expected 2 conversation reads + 1 cached user lookup.');
    }

    public function test_both_hooks_share_one_answer_per_conversation(): void
    {
        $conversation = $this->closedByWorkflow();
        $thread = new FakeThread($conversation);

        $this->subscriptions($conversation, [Subscription::EVENT_NEW_CONVERSATION]);
        $reads_after_first = FakeDb::$reads;

        $result = $this->usersToNotify($thread, Subscription::EVENT_TYPE_NEW, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertSame([], $result);
        $this->assertSame($reads_after_first, FakeDb::$reads, 'The second hook should reuse the memoized answer.');
    }

    // -- Memo lifetime ----------------------------------------------------

    public function test_the_process_events_action_clears_the_memo(): void
    {
        $conversation = $this->closedByWorkflow();

        $this->subscriptions($conversation, [Subscription::EVENT_NEW_CONVERSATION]);
        $reads_after_first = FakeDb::$reads;

        $flush = HookRecorder::action('subscription.process_events')['cb'];
        $flush([]);

        $this->subscriptions($conversation, [Subscription::EVENT_NEW_CONVERSATION]);

        $this->assertGreaterThan(
            $reads_after_first,
            FakeDb::$reads,
            'After the pass ends the memo must be empty, so the next pass re-reads.'
        );
    }

    public function test_the_flush_action_is_registered_for_one_argument(): void
    {
        $registration = HookRecorder::action('subscription.process_events');

        $this->assertSame(1, $registration['args']);
    }

    // -- readConversationState directly -----------------------------------

    public function test_read_conversation_state_reports_a_workflow_close(): void
    {
        $this->closedByWorkflow(70);

        $this->assertSame(
            ['closed' => true, 'closed_by_workflow' => true],
            Provider::readConversationState(70)
        );
    }

    public function test_read_conversation_state_reports_a_human_close(): void
    {
        $this->closedByHuman(71);

        $this->assertSame(
            ['closed' => true, 'closed_by_workflow' => false],
            Provider::readConversationState(71)
        );
    }

    public function test_read_conversation_state_reports_an_open_conversation(): void
    {
        $this->open(72);

        $this->assertSame(
            ['closed' => false, 'closed_by_workflow' => false],
            Provider::readConversationState(72)
        );
    }

    /**
     * An open conversation is settled by the status alone; the workflow user
     * lookup is pointless work.
     */
    public function test_read_conversation_state_does_not_look_up_the_user_for_an_open_conversation(): void
    {
        $this->open(73);

        Provider::readConversationState(73);

        $this->assertSame(1, FakeDb::$reads);
    }
}

<?php

namespace Tests\Unit;

use Modules\QuietAutoClosed\Services\SuppressionDecider;
use PHPUnit\Framework\TestCase;

/**
 * The decision these tests pin down:
 *
 *   Silence a "new conversation" alert when the conversation is already
 *   closed AND an automatic workflow is what closed it. Never silence
 *   anything else.
 *
 * The negative cases matter more than the positive one. A false positive here
 * hides a real ticket from every agent, silently and permanently.
 */
class SuppressionDeciderTest extends TestCase
{
    /**
     * \App\Subscription::EVENT_NEW_CONVERSATION in FreeScout core.
     */
    private const NEW_CONVERSATION = 1;

    /**
     * \App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY - a stand-in for "some
     * other event this module must never touch".
     */
    private const CUSTOMER_REPLIED = 3;

    /**
     * @var int Number of times the injected state reader was invoked.
     */
    private $reads = 0;

    private function decider(array $state, int $newConversationEvent = self::NEW_CONVERSATION): SuppressionDecider
    {
        $this->reads = 0;

        return new SuppressionDecider(function ($conversation_id) use ($state) {
            $this->reads++;

            return $state;
        }, $newConversationEvent);
    }

    private function conversation($id = 42)
    {
        return (object) ['id' => $id];
    }

    private function closedByWorkflow(): array
    {
        return ['closed' => true, 'closed_by_workflow' => true];
    }

    // -- The one case that suppresses -------------------------------------

    public function test_suppresses_a_new_conversation_closed_by_a_workflow(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertTrue($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));
    }

    // -- Everything else must notify --------------------------------------

    public function test_keeps_a_conversation_closed_by_a_human(): void
    {
        $decider = $this->decider(['closed' => true, 'closed_by_workflow' => false]);

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));
    }

    public function test_keeps_an_open_conversation(): void
    {
        $decider = $this->decider(['closed' => false, 'closed_by_workflow' => false]);

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));
    }

    /**
     * A workflow may have touched a conversation that is currently open (it
     * tagged it, or a human reopened it afterwards). Only "closed" counts.
     */
    public function test_keeps_an_open_conversation_a_workflow_has_touched(): void
    {
        $decider = $this->decider(['closed' => false, 'closed_by_workflow' => true]);

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));
    }

    public function test_keeps_events_other_than_new_conversation(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([self::CUSTOMER_REPLIED], $this->conversation()));
    }

    /**
     * Replies, assignments and notes are the overwhelming majority of
     * notifications. They must not pay for a database lookup that could never
     * change their outcome.
     */
    public function test_does_not_read_state_for_unrelated_events(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $decider->shouldSuppress([self::CUSTOMER_REPLIED], $this->conversation());

        $this->assertSame(0, $this->reads);
    }

    // -- Malformed input falls through to "notify" ------------------------

    public function test_keeps_when_there_is_no_conversation(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], null));
    }

    public function test_keeps_when_the_conversation_has_no_id(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], (object) []));
    }

    public function test_keeps_when_the_conversation_id_is_not_usable(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], (object) ['id' => 'abc']));
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], (object) ['id' => 0]));
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], (object) ['id' => -1]));
    }

    public function test_keeps_when_events_is_not_an_array(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress(null, $this->conversation()));
        $this->assertFalse($decider->shouldSuppress('1', $this->conversation()));
    }

    public function test_ignores_non_scalar_entries_in_the_event_list(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([['nested'], new \stdClass()], $this->conversation()));
    }

    public function test_keeps_when_the_state_reader_returns_something_unexpected(): void
    {
        $decider = $this->decider([]);
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));

        $decider = new SuppressionDecider(function () {
            return null;
        }, self::NEW_CONVERSATION);
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation()));
    }

    // -- Event id handling ------------------------------------------------

    /**
     * Core passes the integer constant, but a numeric string must not be
     * mistaken for "not a new conversation".
     */
    public function test_accepts_a_numeric_string_event_id(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertTrue($decider->shouldSuppress(['1'], $this->conversation()));
    }

    public function test_finds_the_event_anywhere_in_the_list(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertTrue($decider->shouldSuppress([self::CUSTOMER_REPLIED, self::NEW_CONVERSATION], $this->conversation()));
    }

    public function test_keeps_when_the_event_list_is_empty(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $this->assertFalse($decider->shouldSuppress([], $this->conversation()));
    }

    // -- Memoization ------------------------------------------------------

    public function test_reads_state_once_per_conversation(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7));
        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7));

        $this->assertSame(1, $this->reads);
    }

    public function test_memoizes_a_negative_answer_too(): void
    {
        $decider = $this->decider(['closed' => false, 'closed_by_workflow' => false]);

        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7)));
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7)));

        $this->assertSame(1, $this->reads);
    }

    public function test_memoizes_per_conversation_not_globally(): void
    {
        $states = [
            7 => ['closed' => true, 'closed_by_workflow' => true],
            8 => ['closed' => false, 'closed_by_workflow' => false],
        ];

        $decider = new SuppressionDecider(function ($conversation_id) use ($states) {
            $this->reads++;

            return $states[$conversation_id];
        }, self::NEW_CONVERSATION);

        $this->assertTrue($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7)));
        $this->assertFalse($decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(8)));
        $this->assertSame(2, $this->reads);
    }

    public function test_flush_forgets_memoized_decisions(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7));
        $decider->flush();
        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7));

        $this->assertSame(2, $this->reads);
    }

    /**
     * The id is normalised before it reaches the memo, so the same
     * conversation arriving as an int and as a numeric string is one entry.
     */
    public function test_normalises_the_conversation_id_for_the_memo(): void
    {
        $decider = $this->decider($this->closedByWorkflow());

        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation(7));
        $decider->shouldSuppress([self::NEW_CONVERSATION], $this->conversation('7'));

        $this->assertSame(1, $this->reads);
    }
}

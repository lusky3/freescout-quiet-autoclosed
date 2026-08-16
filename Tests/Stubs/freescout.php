<?php

/**
 * FreeScout-shaped doubles: the three models and the constants the provider
 * touches, plus a tiny in-memory store standing in for the database.
 *
 * The query builders reproduce exactly the call chains the provider uses -
 * Conversation::where()->select()->first() and User::where()->value() - and
 * nothing else. Every read increments a counter, so tests can assert not just
 * *what* the provider concluded but *how many queries it took to get there*,
 * which is how the hot-path guarantees are pinned.
 *
 * phpcs:ignoreFile -- deliberate multi-class files; these must occupy the
 * App namespace to stand in for the real models.
 */

namespace Tests\Support;

class FakeDb
{
    /** @var array<int, array{status: int, closed_by_user_id: int|null}> */
    public static $conversations = [];

    /** @var array<string, int> */
    public static $users = [];

    /** @var int */
    public static $reads = 0;

    /** @var bool Make the next read throw, to exercise the fail-open path. */
    public static $explode = false;

    public static function reset(): void
    {
        self::$conversations = [];
        self::$users = [];
        self::$reads = 0;
        self::$explode = false;
    }

    public static function read(): void
    {
        self::$reads++;

        if (self::$explode) {
            throw new \RuntimeException('SQLSTATE[HY000]: server has gone away');
        }
    }
}

class FakeConversationQuery
{
    /** @var mixed */
    private $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function select(...$columns): self
    {
        return $this;
    }

    public function first()
    {
        FakeDb::read();

        $key = is_numeric($this->id) ? (int) $this->id : $this->id;

        if (!isset(FakeDb::$conversations[$key])) {
            return null;
        }

        return (object) FakeDb::$conversations[$key];
    }
}

class FakeUserQuery
{
    /** @var mixed */
    private $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    public function value($column)
    {
        FakeDb::read();

        return FakeDb::$users[$this->email] ?? null;
    }
}

/**
 * A Thread whose `conversation` relation blows up when touched. Used to prove
 * the provider does not resolve that lazy relation for events it cannot act
 * on - the check that keeps a query off every reply and note notification.
 */
class ExplodingThread
{
    public function __isset($name)
    {
        throw new \RuntimeException('lazy relation resolved: ' . $name);
    }

    public function __get($name)
    {
        throw new \RuntimeException('lazy relation resolved: ' . $name);
    }
}

class FakeThread
{
    /** @var object|null */
    public $conversation;

    public function __construct($conversation = null)
    {
        $this->conversation = $conversation;
    }
}

namespace App;

use Tests\Support\FakeConversationQuery;
use Tests\Support\FakeUserQuery;

class Conversation
{
    const STATUS_ACTIVE = 1;
    const STATUS_PENDING = 2;
    const STATUS_CLOSED = 3;
    const STATUS_SPAM = 4;

    public static function where($column, $value): FakeConversationQuery
    {
        return new FakeConversationQuery($value);
    }
}

class Subscription
{
    // Event types (the $event_type argument).
    const EVENT_TYPE_NEW = 1;
    const EVENT_TYPE_ASSIGNED = 2;
    const EVENT_TYPE_UPDATED = 3;
    const EVENT_TYPE_CUSTOMER_REPLIED = 4;

    // Events (the $events array).
    const EVENT_NEW_CONVERSATION = 1;
    const EVENT_CONVERSATION_ASSIGNED_TO_ME = 2;
    const EVENT_CUSTOMER_REPLIED_TO_MY = 3;
}

class User
{
    public static function where($column, $value): FakeUserQuery
    {
        return new FakeUserQuery($value);
    }
}

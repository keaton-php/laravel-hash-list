<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Mail\Events\MessageSending;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\HashList;
use Keaton\HashList\Mail\RecipientGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class RecipientGuardTest extends TestCase
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    private function email(array $to, array $cc = [], array $bcc = []): Email
    {
        $email = (new Email)->from('sender@example.com')->subject('Hello')->text('Body');

        if ($to !== []) {
            $email->to(...$to);
        }

        if ($cc !== []) {
            $email->cc(...$cc);
        }

        if ($bcc !== []) {
            $email->bcc(...$bcc);
        }

        return $email;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<string>
     */
    private function plain(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
    }

    private function guard(): RecipientGuard
    {
        return app(RecipientGuard::class);
    }

    #[Test]
    public function it_cancels_a_send_when_every_recipient_is_listed(): void
    {
        $list = app(HashList::class);
        $list->add('a@example.com');
        $list->add('b@example.com');
        $email = $this->email(['A@Example.com'], ['b@example.com']);

        $this->assertFalse($this->guard()->handle(new MessageSending($email)));
        $this->assertSame([], $email->getTo());
        $this->assertSame([], $email->getCc());
    }

    #[Test]
    public function it_removes_only_the_listed_recipients_and_lets_the_send_continue(): void
    {
        app(HashList::class)->add('a@example.com');
        $email = $this->email(['a@example.com', 'b@example.com']);

        $this->assertNull($this->guard()->handle(new MessageSending($email)));
        $this->assertSame(['b@example.com'], $this->plain($email->getTo()));
    }

    #[Test]
    public function it_cleans_cc_and_bcc_as_well(): void
    {
        $list = app(HashList::class);
        $list->add('cc.gone@example.com');
        $list->add('bcc.gone@example.com');
        $email = $this->email(
            ['to@example.com'],
            ['cc.gone@example.com', 'cc.kept@example.com'],
            ['bcc.gone@example.com'],
        );

        $this->assertNull($this->guard()->handle(new MessageSending($email)));
        $this->assertSame(['to@example.com'], $this->plain($email->getTo()));
        $this->assertSame(['cc.kept@example.com'], $this->plain($email->getCc()));
        $this->assertSame([], $email->getBcc());
    }

    #[Test]
    public function it_lets_the_send_continue_on_cc_alone_when_to_is_fully_listed(): void
    {
        app(HashList::class)->add('to@example.com');
        $email = $this->email(['to@example.com'], ['cc@example.com']);

        $this->assertNull($this->guard()->handle(new MessageSending($email)));
        $this->assertSame([], $email->getTo());
        $this->assertSame(['cc@example.com'], $this->plain($email->getCc()));
    }

    #[Test]
    public function it_leaves_a_message_with_no_listed_recipient_untouched(): void
    {
        app(HashList::class)->add('someone.else@example.com');
        $email = $this->email(['a@example.com', 'b@example.com'], ['c@example.com']);

        $this->assertNull($this->guard()->handle(new MessageSending($email)));
        $this->assertSame(['a@example.com', 'b@example.com'], $this->plain($email->getTo()));
        $this->assertSame(['c@example.com'], $this->plain($email->getCc()));
    }

    #[Test]
    public function it_checks_the_configured_guard_list(): void
    {
        config()->set('hash_list.guard_list', 'news');
        $list = app(HashList::class);
        $list->add('default.only@example.com');
        $list->on('news')->add('news.only@example.com');
        $email = $this->email(['default.only@example.com', 'news.only@example.com']);

        $this->assertNull($this->guard()->handle(new MessageSending($email)));
        $this->assertSame(['default.only@example.com'], $this->plain($email->getTo()));
    }

    #[Test]
    public function it_throws_when_the_guard_list_is_not_a_list_name(): void
    {
        config()->set('hash_list.guard_list', 42);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[hash_list.guard_list]');

        $this->guard()->handle(new MessageSending($this->email(['someone@example.com'])));
    }

    #[Test]
    public function it_leaves_every_message_alone_while_the_guard_is_off(): void
    {
        app(HashList::class)->add('gone@example.com');
        $email = $this->email(['gone@example.com']);

        $this->assertNull(app('events')->until(new MessageSending($email)));
        $this->assertSame(['gone@example.com'], $this->plain($email->getTo()));
    }
}

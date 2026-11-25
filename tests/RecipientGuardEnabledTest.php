<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\HashList;
use PHPUnit\Framework\Attributes\Test;

class RecipientGuardEnabledTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hash_list.guard', true);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('mail.from', ['address' => 'sender@example.com', 'name' => 'Sender']);
    }

    private function sentCount(): int
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->count();
    }

    private function send(string $to): void
    {
        Mail::raw('Hello', function ($message) use ($to): void {
            $message->to($to);
        });
    }

    #[Test]
    public function it_registers_the_listener_when_switched_on(): void
    {
        $this->assertTrue(app('events')->hasListeners(MessageSending::class));
    }

    #[Test]
    public function it_stops_a_real_send_to_a_listed_address(): void
    {
        app(HashList::class)->add('gone@example.com');

        $this->send('Gone@Example.com');

        $this->assertSame(0, $this->sentCount());
    }

    #[Test]
    public function it_lets_a_real_send_to_anyone_else_through(): void
    {
        app(HashList::class)->add('gone@example.com');

        $this->send('kept@example.com');

        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    public function it_can_be_switched_off_and_on_at_runtime(): void
    {
        app(HashList::class)->add('gone@example.com');

        config()->set('hash_list.guard', false);
        $this->send('gone@example.com');
        $this->assertSame(1, $this->sentCount());

        config()->set('hash_list.guard', 'true');
        $this->send('gone@example.com');
        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    public function it_reads_an_empty_guard_switch_as_off(): void
    {
        app(HashList::class)->add('gone@example.com');

        // HASH_LIST_GUARD= in an .env file arrives as an empty string
        config()->set('hash_list.guard', '');
        $this->send('gone@example.com');
        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    public function it_refuses_a_guard_switch_it_cannot_read(): void
    {
        config()->set('hash_list.guard', 'ture');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[hash_list.guard]');

        $this->send('someone@example.com');
    }

    #[Test]
    public function it_refuses_to_guess_when_the_whole_config_is_not_an_array(): void
    {
        config()->set('hash_list', true);

        $this->expectException(InvalidConfiguration::class);

        $this->send('someone@example.com');
    }
}

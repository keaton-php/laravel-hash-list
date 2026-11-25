<?php

declare(strict_types=1);

namespace Keaton\HashList\Mail;

use Illuminate\Mail\Events\MessageSending;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\HashList;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A send-time guard, registered on Laravel's MessageSending event.
 *
 * Listed recipients are removed from To, Cc and Bcc. If that leaves the
 * message with nobody to send to, the send is canceled. A message with no
 * listed recipient is left exactly as it was.
 */
class RecipientGuard
{
    public function __construct(protected HashList $hashList)
    {
        //
    }

    /**
     * @return bool|null False cancels the send; null lets it carry on.
     */
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;

        $list = config('hash_list.guard_list');

        if ($list !== null && ! is_string($list)) {
            throw InvalidConfiguration::notAString('guard_list');
        }

        $hashList = $list === null ? $this->hashList : $this->hashList->on($list);

        $removed = 0;
        $remaining = 0;

        foreach (['To', 'Cc', 'Bcc'] as $header) {
            $addresses = $this->addresses($message, $header);
            $kept = array_values(array_filter(
                $addresses,
                static fn (Address $address): bool => ! $hashList->has($address->getAddress()),
            ));

            if (count($kept) !== count($addresses)) {
                $this->replace($message, $header, $kept);
            }

            $removed += count($addresses) - count($kept);
            $remaining += count($kept);
        }

        return $removed > 0 && $remaining === 0 ? false : null;
    }

    /**
     * @return list<Address>
     */
    protected function addresses(Email $message, string $header): array
    {
        return array_values(match ($header) {
            'To' => $message->getTo(),
            'Cc' => $message->getCc(),
            default => $message->getBcc(),
        });
    }

    /**
     * @param  list<Address>  $kept
     */
    protected function replace(Email $message, string $header, array $kept): void
    {
        if ($kept === []) {
            $message->getHeaders()->remove($header);

            return;
        }

        match ($header) {
            'To' => $message->to(...$kept),
            'Cc' => $message->cc(...$kept),
            default => $message->bcc(...$kept),
        };
    }
}

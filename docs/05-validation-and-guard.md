<!-- nav-group: Basic usage -->
# Validation and the guard

The package has a validation rule for forms, and a guard that checks every mail your app sends.

## Validating a form

The `NotListed` rule fails when the address is on a list, however it is spelled. In this example it keeps a banned address out of a signup form.

```php
<?php

use Illuminate\Support\Facades\Validator;
use Keaton\HashList\Facades\HashList;
use Keaton\HashList\Rules\NotListed;

HashList::on('banned')->add('spammer@example.com');

$validator = Validator::make(
    ['email' => 'Spammer@Example.com'],
    ['email' => ['required', 'email', NotListed::on('banned')]],
);

$validator->fails();                    // true
$validator->errors()->first('email');   // 'The email is not allowed.'
```

`new NotListed` checks the default list. A value that isn't a string or isn't an email address passes the rule, so combine it with the `email` rule.

## Guarding outgoing mail

A check in your own code only protects the code that calls it. The send-time guard covers every mail Laravel sends: mailables, `Mail::raw()`, mail notifications and queued mail.

When the guard is on, anyone on the list is removed from To, Cc and Bcc before a message is sent. If nobody is left, the message isn't sent at all.

```php
<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Keaton\HashList\Facades\HashList;

config(['hash_list.guard' => true]);   // in your app: HASH_LIST_GUARD=true in .env

HashList::add('jane@example.com');

Event::listen(function (MessageSent $event): void {
    $to = array_map(fn ($address) => $address->getAddress(), $event->message->getTo());

    echo "Sent '{$event->message->getSubject()}' to ", implode(', ', $to), PHP_EOL;
});

Mail::raw('Here is what we did this spring.', fn ($message) => $message
    ->to(['Jane@Example.com', 'sam@example.com'])
    ->subject('Spring update'));

Mail::raw('Here is your weekly digest.', fn ($message) => $message
    ->to('jane@example.com')
    ->subject('Weekly digest'));
```

```text
Sent 'Spring update' to sam@example.com
```

Jane was removed from the spring update, which still went to Sam. The digest had nobody left, so it wasn't sent.

The guard checks the default list, or the list in `hash_list.guard_list`. Both settings are read on every send, so you can turn the guard on and off at runtime.

**Next:** [Sharing lists](06-sharing-lists.md).

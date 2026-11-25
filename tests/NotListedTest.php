<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;
use Keaton\HashList\HashList;
use Keaton\HashList\Rules\NotListed;
use PHPUnit\Framework\Attributes\Test;

class NotListedTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, NotListed $rule): Validator
    {
        return ValidatorFacade::make($data, ['email' => [$rule]]);
    }

    #[Test]
    public function it_passes_an_email_that_is_not_listed(): void
    {
        $this->assertTrue($this->validate(['email' => 'jane@example.com'], new NotListed)->passes());
    }

    #[Test]
    public function it_fails_an_email_that_is_listed_whatever_its_spelling(): void
    {
        app(HashList::class)->add('jane.doe@example.com');

        $validator = $this->validate(['email' => '  Jane Doe <JANE.DOE@example.com> '], new NotListed);

        $this->assertTrue($validator->fails());
        $this->assertSame('The email is not allowed.', $validator->errors()->first('email'));
        $this->assertStringContainsString('email', $validator->errors()->first('email'));
    }

    #[Test]
    public function it_passes_values_that_are_not_usable_emails(): void
    {
        app(HashList::class)->add('jane@example.com');

        $this->assertTrue($this->validate(['email' => 'not an email'], new NotListed)->passes());
        $this->assertTrue($this->validate(['email' => 12345], new NotListed)->passes());
        $this->assertTrue($this->validate(['email' => ['jane@example.com']], new NotListed)->passes());
    }

    #[Test]
    public function it_checks_a_named_list_only(): void
    {
        app(HashList::class)->on('news')->add('jane@example.com');

        $this->assertTrue($this->validate(['email' => 'jane@example.com'], new NotListed)->passes());
        $this->assertTrue($this->validate(['email' => 'jane@example.com'], NotListed::on('news'))->fails());
        $this->assertTrue($this->validate(['email' => 'jane@example.com'], NotListed::on('other'))->passes());
    }

    #[Test]
    public function it_works_alongside_the_email_rule_in_a_validator(): void
    {
        app(HashList::class)->add('jane@example.com');

        $validator = ValidatorFacade::make(
            ['email' => 'Jane@Example.com'],
            ['email' => ['required', 'email', new NotListed]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['email'], array_keys($validator->errors()->toArray()));
    }
}

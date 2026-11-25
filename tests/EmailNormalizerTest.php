<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Keaton\HashList\EmailNormalizer;
use Keaton\HashList\Exceptions\InvalidEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class EmailNormalizerTest extends PHPUnitTestCase
{
    #[Test]
    #[DataProvider('spellingsOfOneMailbox')]
    public function it_can_reduce_every_spelling_to_the_canonical_address(string $spelling): void
    {
        $this->assertSame('jane.doe@example.com', (new EmailNormalizer)->normalize($spelling));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellingsOfOneMailbox(): array
    {
        return [
            'already canonical' => ['jane.doe@example.com'],
            'upper and mixed case' => ['Jane.Doe@Example.COM'],
            'surrounding spaces' => ['  jane.doe@example.com  '],
            'tabs and newlines' => ["\tjane.doe@example.com\r\n"],
            'non-breaking spaces' => ["\u{00A0}jane.doe@example.com\u{00A0}"],
            'display name' => ['Jane Doe <JANE.DOE@example.com>'],
            'quoted display name with a comma' => ['"Doe, Jane" <jane.doe@example.com>'],
            'angle brackets only' => ['<jane.doe@example.com>'],
            'mailto prefix' => ['mailto:Jane.Doe@Example.com'],
            'upper case mailto prefix' => ['MAILTO:jane.doe@example.com'],
            'mailto link with a subject' => ['mailto:jane.doe@example.com?subject=Unsubscribe'],
        ];
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $normalizer = new EmailNormalizer(stripPlusTags: true);
        $once = $normalizer->normalize(' Jane+News@Example.com ');

        $this->assertSame($once, $normalizer->normalize($once));
    }

    #[Test]
    #[DataProvider('unusableValues')]
    public function it_rejects_values_that_are_not_an_address(string $value): void
    {
        $this->expectException(InvalidEmail::class);

        (new EmailNormalizer)->normalize($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableValues(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'no at sign' => ['plain'],
            'no local part' => ['@example.com'],
            'no domain' => ['user@'],
            'two at signs' => ['a@b@example.com'],
            'space inside' => ['user name@example.com'],
            'comma inside' => ['a,b@example.com'],
            'zero width space' => ["user@exam\u{200B}ple.com"],
            'empty angle brackets' => ['Name <>'],
            'invalid utf-8' => ["\xC3\x28@example.com"],
            'dots only' => ['.@.'],
            'doubled dot in the local part' => ['a..b@example.com'],
            'leading dot in the local part' => ['.a@example.com'],
            'trailing dot in the local part' => ['a.@example.com'],
            'quote in the local part' => ['a"b@example.com'],
            'local part over 64 characters' => [str_repeat('a', 65).'@example.com'],
            'doubled dot in the domain' => ['a@example..com'],
            'domain label starting with a hyphen' => ['a@-example.com'],
            'domain label ending with a hyphen' => ['a@example-.com'],
            'one-label domain' => ['user@localhost'],
            'address literal' => ['user@[192.0.2.1]'],
            'underscore in the domain' => ['a@ex_ample.com'],
            'domain label over 63 characters' => ['a@'.str_repeat('b', 64).'.com'],
        ];
    }

    #[Test]
    public function it_never_puts_the_rejected_value_in_the_exception_message(): void
    {
        try {
            (new EmailNormalizer)->normalize('secret.person@');
            $this->fail('Expected an InvalidEmail exception.');
        } catch (InvalidEmail $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('person', $e->getMessage());
        }
    }

    #[Test]
    public function it_returns_null_instead_of_throwing_from_try_normalize(): void
    {
        $normalizer = new EmailNormalizer;

        $this->assertNull($normalizer->tryNormalize('not an email'));
        $this->assertSame('a@example.com', $normalizer->tryNormalize('A@Example.com'));
    }

    #[Test]
    public function it_keeps_plus_tags_by_default(): void
    {
        $this->assertSame('jane+news@example.com', (new EmailNormalizer)->normalize('Jane+News@example.com'));
    }

    #[Test]
    public function it_can_strip_plus_tags_when_asked_to(): void
    {
        $normalizer = new EmailNormalizer(stripPlusTags: true);

        $this->assertSame('jane@example.com', $normalizer->normalize('Jane+news@example.com'));
        $this->assertSame('a@example.com', $normalizer->normalize('a+b+c@example.com'));
    }

    #[Test]
    public function it_does_not_strip_a_plus_that_is_the_whole_local_part(): void
    {
        $normalizer = new EmailNormalizer(stripPlusTags: true);

        $this->assertSame('+tag@example.com', $normalizer->normalize('+tag@example.com'));
    }

    #[Test]
    public function it_can_punycode_an_international_domain(): void
    {
        $this->assertSame('user@xn--bcher-kva.example', (new EmailNormalizer)->normalize('User@Bücher.example'));
    }

    #[Test]
    public function it_accepts_the_addresses_real_mail_uses(): void
    {
        foreach ([
            "o'brien@example.com",
            'first.last+tag@sub.example.co.uk',
            'x@a.io',
            'user_name-1@example-mail.com',
            "jos\u{00E9}@example.com",
        ] as $address) {
            $this->assertSame(mb_strtolower($address), (new EmailNormalizer)->normalize($address));
        }
    }

    #[Test]
    public function it_strips_a_long_plus_tag_before_checking_the_length(): void
    {
        $this->assertSame('a@example.com', (new EmailNormalizer(stripPlusTags: true))->normalize('a+'.str_repeat('t', 80).'@example.com'));
    }

    #[Test]
    public function it_measures_the_local_part_in_bytes(): void
    {
        $this->assertSame(str_repeat("\u{00E9}", 32).'@example.com', (new EmailNormalizer)->normalize(str_repeat("\u{00E9}", 32).'@example.com'));

        $this->expectException(InvalidEmail::class);

        (new EmailNormalizer)->normalize(str_repeat("\u{00E9}", 33).'@example.com');
    }

    #[Test]
    public function it_treats_an_accent_typed_two_ways_as_one_mailbox(): void
    {
        $composed = "jos\u{00E9}@example.com";
        $decomposed = "jose\u{0301}@example.com";

        $this->assertNotSame($composed, $decomposed);
        $this->assertSame($composed, (new EmailNormalizer)->normalize($decomposed));
        $this->assertSame($composed, (new EmailNormalizer)->normalize(mb_strtoupper($decomposed)));
    }

    #[Test]
    public function it_accepts_an_address_of_254_bytes_and_refuses_255(): void
    {
        // a 64-byte local part and a domain of two 63-byte labels, one of $n, and .com
        $address = static fn (int $n): string => str_repeat('l', 64).'@'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', $n).'.com';

        $fits = $address(57);
        $tooLong = $address(58);

        $this->assertSame(254, strlen($fits));
        $this->assertSame(255, strlen($tooLong));
        $this->assertSame($fits, (new EmailNormalizer)->normalize($fits));

        $this->expectException(InvalidEmail::class);

        (new EmailNormalizer)->normalize($tooLong);
    }
}

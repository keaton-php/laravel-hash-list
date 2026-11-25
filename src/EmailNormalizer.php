<?php

declare(strict_types=1);

namespace Keaton\HashList;

use Keaton\HashList\Exceptions\InvalidEmail;
use Normalizer;

/**
 * Reduces any spelling of an address to one canonical string, so the same
 * mailbox always produces the same hash.
 *
 * Steps: trim (including Unicode spaces), drop a leading "mailto:" and any
 * "?subject=..." after it, reduce "Name <user@host>" to the address,
 * lowercase and Unicode-normalize (NFC), optionally drop a "+tag" from the local part, punycode a
 * non-ASCII domain, then check the local part and the domain are usable.
 */
class EmailNormalizer
{
    public function __construct(protected bool $stripPlusTags = false)
    {
        //
    }

    /**
     * @throws InvalidEmail
     */
    public function normalize(string $email): string
    {
        $address = $this->trim($email);

        if (str_starts_with(strtolower($address), 'mailto:')) {
            $address = substr($address, 7);
            $query = strpos($address, '?');

            // mailto:jane@example.com?subject=Hi is jane@example.com
            if ($query !== false) {
                $address = substr($address, 0, $query);
            }

            $address = $this->trim($address);
        }

        if (preg_match('/<([^<>]*)>\z/u', $address, $matches) === 1) {
            $address = $this->trim($matches[1]);
        }

        // one form for accented letters, so é typed two ways is one mailbox
        $address = Normalizer::normalize(mb_strtolower($address), Normalizer::FORM_C);

        if ($address === false) {
            throw InvalidEmail::malformed();
        }

        if (preg_match('/[\s\p{C}<>,;]/u', $address) !== 0) {
            throw InvalidEmail::malformed();
        }

        $parts = explode('@', $address);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw InvalidEmail::malformed();
        }

        [$local, $domain] = $parts;

        if ($this->stripPlusTags) {
            $plus = strpos($local, '+');

            if ($plus !== false && $plus > 0) {
                $local = substr($local, 0, $plus);
            }
        }

        $domain = $this->punycode($domain);

        $address = $local.'@'.$domain;

        // 254 bytes is the longest address SMTP can deliver to
        if (strlen($address) > 254 || ! $this->isUsableLocalPart($local) || ! $this->isUsableDomain($domain)) {
            throw InvalidEmail::malformed();
        }

        return $address;
    }

    /**
     * Same as normalize(), but returns null instead of throwing.
     */
    public function tryNormalize(string $email): ?string
    {
        try {
            return $this->normalize($email);
        } catch (InvalidEmail) {
            return null;
        }
    }

    protected function trim(string $value): string
    {
        $trimmed = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);

        if ($trimmed === null) {
            throw InvalidEmail::malformed();
        }

        return $trimmed;
    }

    /**
     * Up to 64 bytes, no leading, trailing or doubled dot, and none of
     * the characters an unquoted local part can't hold. Non-ASCII letters
     * are allowed (internationalized addresses).
     */
    protected function isUsableLocalPart(string $local): bool
    {
        return strlen($local) <= 64
            && preg_match('/\A[^."(),:\[\]\\\\]+(?:\.[^."(),:\[\]\\\\]+)*\z/u', $local) === 1;
    }

    /**
     * An ASCII host name of at least two labels: user@localhost and
     * address literals such as user@[192.0.2.1] are not accepted.
     */
    protected function isUsableDomain(string $domain): bool
    {
        if (strlen($domain) > 253) {
            return false;
        }

        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    protected function punycode(string $domain): string
    {
        if (preg_match('/[^\x00-\x7f]/', $domain) !== 1) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || $ascii === '') {
            throw InvalidEmail::malformed();
        }

        return $ascii;
    }
}

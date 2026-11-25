<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Keaton\HashList\Enums\Algorithm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class AlgorithmTest extends PHPUnitTestCase
{
    #[Test]
    public function it_can_hash_to_the_published_md5_and_sha256_test_vectors(): void
    {
        $this->assertSame('900150983cd24fb0d6963f7d28e17f72', Algorithm::Md5->hash('abc'));
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            Algorithm::Sha256->hash('abc'),
        );
    }

    #[Test]
    public function it_reports_the_hex_length_of_each_digest(): void
    {
        $this->assertSame(32, Algorithm::Md5->length());
        $this->assertSame(64, Algorithm::Sha256->length());
        $this->assertSame(32, strlen(Algorithm::Md5->hash('x')));
        $this->assertSame(64, strlen(Algorithm::Sha256->hash('x')));
    }

    #[Test]
    #[DataProvider('validHashes')]
    public function it_can_recognize_a_valid_hash(Algorithm $algorithm, string $hash): void
    {
        $this->assertTrue($algorithm->isValidHash($hash));
    }

    /**
     * @return array<string, array{Algorithm, string}>
     */
    public static function validHashes(): array
    {
        return [
            'md5' => [Algorithm::Md5, str_repeat('a1', 16)],
            'sha256' => [Algorithm::Sha256, str_repeat('0f', 32)],
        ];
    }

    #[Test]
    #[DataProvider('invalidHashes')]
    public function it_rejects_anything_that_is_not_a_lowercase_hex_digest_of_the_right_length(
        Algorithm $algorithm,
        string $hash,
    ): void {
        $this->assertFalse($algorithm->isValidHash($hash));
    }

    /**
     * @return array<string, array{Algorithm, string}>
     */
    public static function invalidHashes(): array
    {
        return [
            'empty' => [Algorithm::Md5, ''],
            'sha256 length for md5' => [Algorithm::Md5, str_repeat('a1', 32)],
            'md5 length for sha256' => [Algorithm::Sha256, str_repeat('a1', 16)],
            'one char short' => [Algorithm::Md5, str_repeat('a', 31)],
            'one char long' => [Algorithm::Md5, str_repeat('a', 33)],
            'not hex' => [Algorithm::Md5, str_repeat('g', 32)],
            'uppercase' => [Algorithm::Md5, str_repeat('A', 32)],
            'trailing newline' => [Algorithm::Md5, str_repeat('a', 32)."\n"],
        ];
    }
}

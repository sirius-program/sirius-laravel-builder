<?php

declare(strict_types=1);

use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Inference\NumberNormalizer;
use Sirius\LaravelBuilder\Inference\ValueNormalizer;
use Tests\Support\FieldFixtures;

it('preserves exact decimal strings and pads only missing decimal places', function (): void {
    $column = FieldFixtures::column(['type' => 'decimal', 'nativeType' => 'decimal(30,2)', 'precision' => 30, 'scale' => 2]);
    expect((new NumberNormalizer)->normalize('0012345678901234567890.1', $column))->toBe('12345678901234567890.10');
    expect((new NumberNormalizer)->normalize('-0.00', $column))->toBe('0.00');
});

it('rejects lossy or malformed currency inputs', function (mixed $value): void {
    (new NumberNormalizer)->normalize($value, FieldFixtures::column(['type' => 'decimal', 'precision' => 5, 'scale' => 2]));
})->with([0.1, '1,000.00', '1e2', '1.001', '1000', '', true, [[]], 'NaN'])->throws(InvalidArgumentException::class);

it('validates integer bounds without float conversion and retains SQLite 64-bit range', function (): void {
    $column = FieldFixtures::column(['type' => 'integer', 'nativeType' => 'bigint unsigned', 'unsigned' => true]);
    expect((new NumberNormalizer)->normalize('18446744073709551615', $column))->toBe('18446744073709551615');
    expect(fn (): string => (new NumberNormalizer)->normalize('18446744073709551616', $column))->toThrow(InvalidArgumentException::class);
    expect(fn (): string => (new NumberNormalizer)->normalize('-1', $column))->toThrow(InvalidArgumentException::class);
    $signed = FieldFixtures::column(['type' => 'integer', 'nativeType' => 'integer']);
    expect((new NumberNormalizer)->normalize('-9223372036854775808', $signed, 'sqlite'))->toBe('-9223372036854775808');
    expect(fn (): string => (new NumberNormalizer)->normalize('2147483648', $signed, 'mysql'))->toThrow(InvalidArgumentException::class);
});

it('accepts canonical booleans and rejects truthy strings', function (): void {
    $column = FieldFixtures::column(['type' => 'boolean']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Boolean);
    expect((new ValueNormalizer)->normalize($plan, '0', 'UTC'))->toBeFalse();
    expect((new ValueNormalizer)->normalize($plan, 1, 'UTC'))->toBeTrue();
    expect(fn (): mixed => (new ValueNormalizer)->normalize($plan, 'false', 'UTC'))->toThrow(InvalidArgumentException::class);
});

it('decodes cast JSON once and preserves raw JSON without casts', function (): void {
    $column = FieldFixtures::column(['type' => 'json']);
    $planner = new FieldPlanner;
    $cast = $planner->plan(FieldFixtures::table([$column]), $column, FieldType::Textarea, cast: 'array');
    $raw = $planner->plan(FieldFixtures::table([$column]), $column, FieldType::Textarea);
    $jsonCast = $planner->plan(FieldFixtures::table([$column]), $column, FieldType::Textarea, cast: 'json');
    $normalizer = new ValueNormalizer;
    expect($normalizer->normalize($cast, '{"name":"Tools"}', 'UTC'))->toBe(['name' => 'Tools']);
    expect($normalizer->forEditing($cast, ['name' => 'Tools']))->toBe('{"name":"Tools"}');
    expect(fn (): mixed => $normalizer->normalize($jsonCast, 'null', 'UTC'))->toThrow(InvalidArgumentException::class, 'SQL null');
    expect($normalizer->normalize($raw, '{ "id": 9223372036854775808 }', 'UTC'))->toBe('{ "id": 9223372036854775808 }');
    expect(fn (): mixed => $normalizer->normalize($cast, '{"id":9223372036854775808}', 'UTC'))->toThrow(InvalidArgumentException::class, 'precision');
    expect(fn (): mixed => $normalizer->normalize($cast, '{broken}', 'UTC'))->toThrow(InvalidArgumentException::class, 'valid JSON');
});

it('preserves wall clock datetime and rejects invalid dates or nonexistent DST times', function (): void {
    $column = FieldFixtures::column(['type' => 'datetime']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Datetime);
    $normalizer = new ValueNormalizer;
    expect($normalizer->normalize($plan, '2026-11-22 09:30:00', 'Asia/Jakarta'))->toBe('2026-11-22 09:30:00');
    expect(fn (): mixed => $normalizer->normalize($plan, '2026-02-30 09:30:00', 'Asia/Jakarta'))->toThrow(InvalidArgumentException::class);
    expect(fn (): mixed => $normalizer->normalize($plan, '2026-03-08 02:30:00', 'America/New_York'))->toThrow(InvalidArgumentException::class);
    expect($normalizer->forEditing($plan, new DateTimeImmutable('2026-11-22 02:30:00', new DateTimeZone('UTC')), 'Asia/Jakarta'))->toBe('2026-11-22 09:30:00');
});

it('normalizes time strings and validates canonical phone values', function (): void {
    $time = FieldFixtures::column(['type' => 'time']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$time]), $time, FieldType::Time);
    expect((new ValueNormalizer)->normalize($plan, '09:30', 'UTC'))->toBe('09:30:00');
    expect((new ValueNormalizer)->forEditing($plan, '09:30:00'))->toBe('09:30');
    $phone = FieldFixtures::column();
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$phone]), $phone, FieldType::Phone);
    expect((new ValueNormalizer)->normalize($plan, '+628123456789', 'Asia/Jakarta'))->toBe('+628123456789');
    expect(fn (): mixed => (new ValueNormalizer)->normalize($plan, '0812 345', 'Asia/Jakarta'))->toThrow(InvalidArgumentException::class);
});

it('never exposes stored password values when preparing forms', function (): void {
    $column = FieldFixtures::column(['name' => 'password']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Password, cast: 'hashed');
    expect((new ValueNormalizer)->forEditing($plan, 'stored-hash'))->toBe('');
});

it('rejects stored time precision that the selected widget cannot preserve', function (): void {
    $column = FieldFixtures::column(['type' => 'time']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Time);
    expect(fn (): mixed => (new ValueNormalizer)->forEditing($plan, '09:30:45'))->toThrow(InvalidArgumentException::class, 'nonzero seconds');
    $column = FieldFixtures::column(['type' => 'datetime']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Datetime);
    expect(fn (): mixed => (new ValueNormalizer)->forEditing($plan, new DateTimeImmutable('2026-11-22 09:30:00.123'), 'UTC'))->toThrow(InvalidArgumentException::class, 'precision');
});

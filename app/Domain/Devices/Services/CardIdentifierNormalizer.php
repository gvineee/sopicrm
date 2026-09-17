<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\DataTransferObjects\NormalizedCardNumber;
use InvalidArgumentException;

/**
 * Spec section 6: "ნომრის bytes/სიგრძე/leading zeros შეინარჩუნე; decimal/hex
 * და byte-order გარდაქმნა იყოს ადაპტერის დოკუმენტირებული ნაწილი." Hex<->
 * decimal conversion is implemented here as pure-PHP arbitrary-length
 * digit-array arithmetic (no `bcmath`/`gmp` dependency assumed available in
 * every deployment target — see docs/decisions.md), so it is correct even
 * for a card number wider than PHP's native 64-bit integer range.
 *
 * Byte order (endianness): the physical XPass2 reader's wire/byte order for
 * a given card format (EM vs MIFARE, Wiegand format variant) is a real
 * hardware behavior that must be confirmed during pilot bring-up against an
 * actual reader — the hard constraint against fabricating unknown hardware
 * parameters applies directly here. This class therefore does NOT guess an
 * endianness: `fromHex()` treats the given hex string exactly as provided
 * (most-significant nibble first, i.e. plain big-endian reading order), and
 * any byte-swap a specific deployment's readers actually need is an
 * explicit, documented adapter-level transform layered on top of this class
 * once confirmed — never baked in here as an assumption.
 */
final class CardIdentifierNormalizer
{
    /**
     * @param  string  $hex  Hex digits, optionally "0x"-prefixed or
     *                       space-separated (as copy-pasted from a reader
     *                       log or admin tool).
     */
    public function fromHex(string $hex, string $cardType, ?int $bitLength = null): NormalizedCardNumber
    {
        $clean = $this->cleanHex($hex);

        if ($clean === '') {
            throw new InvalidArgumentException('Card hex identifier is empty.');
        }

        $bitLength ??= strlen($clean) * 4;

        return new NormalizedCardNumber(
            cardType: $cardType,
            canonicalIdentifier: $this->hexToDecimal($clean),
            rawBytesHex: $clean,
            bitLength: $bitLength,
        );
    }

    /**
     * @param  string  $decimal  Decimal digits only.
     */
    public function fromDecimal(string $decimal, string $cardType, int $bitLength): NormalizedCardNumber
    {
        if (! ctype_digit($decimal)) {
            throw new InvalidArgumentException('Card decimal identifier must contain only digits.');
        }

        $hex = $this->decimalToHex($decimal);
        $expectedNibbles = (int) ceil($bitLength / 4);
        $hex = str_pad($hex, $expectedNibbles, '0', STR_PAD_LEFT);

        return new NormalizedCardNumber(
            cardType: $cardType,
            canonicalIdentifier: ltrim($decimal, '0') === '' ? '0' : ltrim($decimal, '0'),
            rawBytesHex: $hex,
            bitLength: $bitLength,
        );
    }

    private function cleanHex(string $hex): string
    {
        $hex = str_ireplace('0x', '', trim($hex));
        $hex = preg_replace('/[\s:_-]+/', '', $hex) ?? '';

        if ($hex !== '' && ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException("'{$hex}' is not a valid hex card identifier.");
        }

        return strtoupper($hex);
    }

    /**
     * Converts a hex string to a decimal string using base-10 digit-array
     * long multiplication — correct for arbitrarily large values.
     */
    private function hexToDecimal(string $hex): string
    {
        /** @var list<int> $digits base-10 digits, least-significant first */
        $digits = [0];

        foreach (str_split($hex) as $char) {
            $carry = intval($char, 16);

            foreach ($digits as $i => $digit) {
                $product = $digit * 16 + $carry;
                $digits[$i] = $product % 10;
                $carry = intdiv($product, 10);
            }

            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($digits));
    }

    /**
     * Converts a decimal string to a hex string using base-16 long division.
     */
    private function decimalToHex(string $decimal): string
    {
        $decimal = ltrim($decimal, '0');

        if ($decimal === '') {
            return '0';
        }

        /** @var list<string> $digits base-10 digits, most-significant first */
        $digits = str_split($decimal);
        $hexDigits = [];

        while (! (count($digits) === 1 && $digits[0] === '0')) {
            [$digits, $remainder] = $this->divideByBase($digits, 16);
            $hexDigits[] = dechex($remainder);
        }

        return strtoupper(implode('', array_reverse($hexDigits)));
    }

    /**
     * @param  list<string>  $digits  base-10 digits, most-significant first
     * @return array{0: list<string>, 1: int}
     */
    private function divideByBase(array $digits, int $divisor): array
    {
        $result = [];
        $remainder = 0;

        foreach ($digits as $digit) {
            $current = $remainder * 10 + (int) $digit;
            $result[] = (string) intdiv($current, $divisor);
            $remainder = $current % $divisor;
        }

        while (count($result) > 1 && $result[0] === '0') {
            array_shift($result);
        }

        return [$result, $remainder];
    }
}

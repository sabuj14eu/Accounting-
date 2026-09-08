<?php

declare(strict_types=1);

namespace Poland\Ksef\Crypto;

/**
 * Just enough arbitrary-precision arithmetic for RSA public-key operations:
 * modular exponentiation of a 2048/4096-bit message by a small public
 * exponent.
 *
 * Exists because PHP's OpenSSL binding only offers RSA-OAEP with SHA-1, KSeF
 * requires OAEP with SHA-256, and neither GMP nor bcmath can be assumed on a
 * server whose PHP was built from source. GMP is used when present; this is
 * the fallback, written for clarity over speed. A public-exponent operation
 * (17 squarings for e = 65537) takes well under a second on a 2048-bit key.
 *
 * Numbers are little-endian arrays of 16-bit limbs. Nothing here handles
 * negatives; RSA never needs them.
 */
final class BigInt
{
    private const BASE = 65536;

    /** @param list<int> $limbs little-endian, each 0..65535 */
    private function __construct(private readonly array $limbs)
    {
    }

    public static function fromBinary(string $bytes): self
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '') {
            return new self([0]);
        }
        if (strlen($bytes) % 2 === 1) {
            $bytes = "\0".$bytes;
        }
        $limbs = [];
        for ($i = strlen($bytes) - 2; $i >= 0; $i -= 2) {
            $limbs[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
        }

        return new self(self::trim($limbs));
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('BigInt is unsigned.');
        }
        $limbs = [];
        do {
            $limbs[] = $value % self::BASE;
            $value = intdiv($value, self::BASE);
        } while ($value > 0);

        return new self($limbs);
    }

    /** Big-endian bytes, left-padded with zeros to $length when given. */
    public function toBinary(?int $length = null): string
    {
        $out = '';
        foreach ($this->limbs as $limb) {
            $out = chr($limb >> 8).chr($limb & 0xFF).$out;
        }
        $out = ltrim($out, "\0");
        if ($length !== null) {
            if (strlen($out) > $length) {
                throw new \LengthException('Integer does not fit in the requested length.');
            }
            $out = str_pad($out, $length, "\0", STR_PAD_LEFT);
        }

        return $out === '' && $length === null ? "\0" : $out;
    }

    public function isZero(): bool
    {
        return count($this->limbs) === 1 && $this->limbs[0] === 0;
    }

    public function compare(self $other): int
    {
        $a = $this->limbs;
        $b = $other->limbs;
        if (count($a) !== count($b)) {
            return count($a) <=> count($b);
        }
        for ($i = count($a) - 1; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return 0;
    }

    /** ($this ^ $exponent) mod $modulus, by square-and-multiply. */
    public function powMod(self $exponent, self $modulus): self
    {
        if ($modulus->isZero()) {
            throw new \DivisionByZeroError('Modulus is zero.');
        }
        if (function_exists('gmp_powm')) {
            $result = gmp_powm(
                gmp_import($this->toBinary(), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
                gmp_import($exponent->toBinary(), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
                gmp_import($modulus->toBinary(), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
            );

            return self::fromBinary(gmp_export($result, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
        }

        $result = self::fromInt(1);
        $base = $this->mod($modulus);
        $bits = $exponent->bits();
        foreach ($bits as $bit) { // most significant first
            $result = $result->multiply($result)->mod($modulus);
            if ($bit === 1) {
                $result = $result->multiply($base)->mod($modulus);
            }
        }

        return $result;
    }

    /** @return list<int> bits, most significant first, no leading zeros */
    private function bits(): array
    {
        $bits = [];
        $top = count($this->limbs) - 1;
        for ($i = $top; $i >= 0; $i--) {
            for ($b = 15; $b >= 0; $b--) {
                $bit = ($this->limbs[$i] >> $b) & 1;
                if ($bits === [] && $bit === 0) {
                    continue;
                }
                $bits[] = $bit;
            }
        }

        return $bits === [] ? [0] : $bits;
    }

    public function multiply(self $other): self
    {
        $a = $this->limbs;
        $b = $other->limbs;
        $product = array_fill(0, count($a) + count($b), 0);
        foreach ($a as $i => $ai) {
            if ($ai === 0) {
                continue;
            }
            $carry = 0;
            foreach ($b as $j => $bj) {
                $t = $ai * $bj + $product[$i + $j] + $carry;
                $product[$i + $j] = $t & 0xFFFF;
                $carry = $t >> 16;
            }
            $k = $i + count($b);
            while ($carry > 0) {
                $t = $product[$k] + $carry;
                $product[$k] = $t & 0xFFFF;
                $carry = $t >> 16;
                $k++;
            }
        }

        return new self(self::trim($product));
    }

    /** Remainder of $this divided by $modulus (Knuth, TAOCP vol. 2, algorithm D). */
    public function mod(self $modulus): self
    {
        if ($this->compare($modulus) < 0) {
            return $this;
        }
        $v = $modulus->limbs;
        $n = count($v);
        if ($n === 1) {
            $rem = 0;
            for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
                $rem = (($rem << 16) | $this->limbs[$i]) % $v[0];
            }

            return self::fromInt($rem);
        }

        // Normalise so the top limb of the divisor has its high bit set.
        $shift = 0;
        $top = $v[$n - 1];
        while ($top < 0x8000) {
            $top <<= 1;
            $shift++;
        }
        $u = self::shiftLeft($this->limbs, $shift);
        $v = self::shiftLeft($v, $shift);
        $v = array_pad($v, $n, 0);
        $u[] = 0; // one extra limb for the algorithm
        $m = count($u) - $n - 1;

        for ($j = $m; $j >= 0; $j--) {
            $numerator = ($u[$j + $n] << 16) | $u[$j + $n - 1];
            $qhat = intdiv($numerator, $v[$n - 1]);
            $rhat = $numerator % $v[$n - 1];
            while ($qhat >= self::BASE || $qhat * $v[$n - 2] > (($rhat << 16) | $u[$j + $n - 2])) {
                $qhat--;
                $rhat += $v[$n - 1];
                if ($rhat >= self::BASE) {
                    break;
                }
            }
            // Multiply and subtract.
            $borrow = 0;
            $carry = 0;
            for ($i = 0; $i < $n; $i++) {
                $p = $qhat * $v[$i] + $carry;
                $carry = $p >> 16;
                $t = $u[$i + $j] - ($p & 0xFFFF) - $borrow;
                if ($t < 0) {
                    $t += self::BASE;
                    $borrow = 1;
                } else {
                    $borrow = 0;
                }
                $u[$i + $j] = $t;
            }
            $t = $u[$j + $n] - $carry - $borrow;
            if ($t < 0) {
                $u[$j + $n] = $t + self::BASE;
                // qhat was one too large: add the divisor back.
                $carry = 0;
                for ($i = 0; $i < $n; $i++) {
                    $s = $u[$i + $j] + $v[$i] + $carry;
                    $u[$i + $j] = $s & 0xFFFF;
                    $carry = $s >> 16;
                }
                $u[$j + $n] = ($u[$j + $n] + $carry) & 0xFFFF;
            } else {
                $u[$j + $n] = $t;
            }
        }

        // The remainder is in u[0..n-1], still shifted.
        $rem = array_slice($u, 0, $n);

        return new self(self::trim(self::shiftRight($rem, $shift)));
    }

    /** @param list<int> $limbs @return list<int> */
    private static function shiftLeft(array $limbs, int $bits): array
    {
        if ($bits === 0) {
            return $limbs;
        }
        $out = [];
        $carry = 0;
        foreach ($limbs as $limb) {
            $t = ($limb << $bits) | $carry;
            $out[] = $t & 0xFFFF;
            $carry = $t >> 16;
        }
        if ($carry > 0) {
            $out[] = $carry;
        }

        return $out;
    }

    /** @param list<int> $limbs @return list<int> */
    private static function shiftRight(array $limbs, int $bits): array
    {
        if ($bits === 0) {
            return $limbs;
        }
        $out = array_fill(0, count($limbs), 0);
        $carry = 0;
        for ($i = count($limbs) - 1; $i >= 0; $i--) {
            $t = ($carry << 16) | $limbs[$i];
            $out[$i] = ($t >> $bits) & 0xFFFF;
            $carry = $t & ((1 << $bits) - 1);
        }

        return $out;
    }

    /** @param list<int> $limbs @return list<int> */
    private static function trim(array $limbs): array
    {
        $i = count($limbs) - 1;
        while ($i > 0 && $limbs[$i] === 0) {
            array_pop($limbs);
            $i--;
        }

        return $limbs === [] ? [0] : array_values($limbs);
    }
}

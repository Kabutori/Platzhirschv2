<?php
namespace Tests\Unit;
use App\Services\Totp;
use PHPUnit\Framework\TestCase;
final class TotpTest extends TestCase
{
    public function test_rfc_6238_sha1_vector(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 1));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame(1, Totp::verify($secret, '287082', 0, 59));
        $this->assertFalse(Totp::verify($secret, '287082', 1, 59));
        $this->assertFalse(Totp::verify($secret, '123', 0, 59));
    }
    public function test_secrets_are_random_base32(): void
    {
        $a = Totp::secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, Totp::secret());
    }
}

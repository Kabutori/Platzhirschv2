<?php
namespace Tests\Feature;
use App\Registration\{WebsiteAddress, WebsiteClient, WebsiteCheck, PublicDns};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class WebsiteCheckTest extends TestCase
{
    public function test_url_and_email_normalization_are_exact_not_suffix_matches(): void
    {
        $a = new WebsiteAddress;
        $url = $a->normalize('HTTP://WWW.LINDE.DE/speisekarte');
        $this->assertSame('https://www.linde.de/speisekarte', $url);
        $this->assertTrue($a->matchingEmail($url, 'kontakt@LINDE.DE'));
        $this->assertFalse($a->matchingEmail($url, 'kontakt@evil-linde.de'));
        $this->assertFalse($a->matchingEmail($url, 'kontakt@linde.de.evil.org'));
    }
    public function test_private_reserved_and_mapped_addresses_are_rejected(): void
    {
        $dns = new PublicDns;
        foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.100.100.200','192.168.1.1',
            '224.0.0.1','::1','::ffff:127.0.0.1','fc00::1','fe80::1','64:ff9b::7f00:1'] as $ip) {
            $this->assertFalse($dns->isPublic($ip), $ip);
        }
        $this->assertTrue($dns->isPublic('8.8.8.8'));
        $this->assertTrue($dns->isPublic('2606:4700:4700::1111'));
    }
    public function test_dangerous_url_forms_are_rejected(): void
    {
        foreach (['https://127.0.0.1','https://[::1]/','https://user:pass@linde.de/',
            'https://linde.de:8443/','file:///etc/passwd','https://linde.de/?token=secret','https://intranet.local'] as $url) {
            try { (new WebsiteAddress)->normalize($url); $this->fail($url); }
            catch (ValidationException) { $this->addToAssertionCount(1); }
        }
    }
    public function test_restaurant_and_hotel_require_both_industry_and_offering_terms(): void
    {
        foreach ([['<h1>Restaurant Linde</h1><p>Speisekarte</p>', 'restaurant'],
            ['<h1>Hotel Linde</h1><p>Zimmer und Frühstück</p>', 'hotel']] as [$html,$category]) {
            $client = \Mockery::mock(WebsiteClient::class);
            $client->shouldReceive('html')->once()->andReturn($html);
            $result = (new WebsiteCheck(new WebsiteAddress, $client))->check('linde.de','info@linde.de');
            $this->assertSame($category, $result['category']);
            $this->assertGreaterThanOrEqual(2, count($result['keywords']));
        }
    }
    public function test_scripts_comments_and_single_keywords_do_not_qualify(): void
    {
        foreach (['<script>Restaurant Speisekarte</script>Software', '<!-- Hotel Zimmer -->Software', '<h1>Hotel</h1>'] as $html) {
            $client = \Mockery::mock(WebsiteClient::class);
            $client->shouldReceive('html')->once()->andReturn($html);
            try { (new WebsiteCheck(new WebsiteAddress,$client))->check('linde.de','info@linde.de'); $this->fail('Accepted unrelated page'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('website', $e->errors()); }
        }
    }
    public function test_provider_errors_never_expose_raw_details(): void
    {
        $client = \Mockery::mock(WebsiteClient::class);
        $client->shouldReceive('html')->andThrow(new \RuntimeException('secret-internal-address'));
        try { (new WebsiteCheck(new WebsiteAddress,$client))->check('linde.de','info@linde.de'); $this->fail(); }
        catch (ValidationException $e) { $this->assertStringNotContainsString('secret-internal-address', $e->getMessage()); }
    }
}

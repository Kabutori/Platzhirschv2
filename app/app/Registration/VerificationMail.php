<?php
namespace App\Registration;
use Illuminate\Mail\Mailable;
class VerificationMail extends Mailable
{
    public function __construct(public string $confirmationUrl, public string $businessName) {}
    public function build(): static
    {
        return $this->subject('Platzhirsch: E-Mail-Adresse bestätigen')
            ->text('registration.email');
    }
}

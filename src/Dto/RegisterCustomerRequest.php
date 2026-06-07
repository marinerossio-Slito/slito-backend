<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/register/customer.
 */
class RegisterCustomerRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $password = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $firstName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $lastName = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 255)]
    public ?string $homeAddress = null;
}

<?php

declare(strict_types=1);

namespace App\Component\User\Dtos;

use Symfony\Component\Serializer\Annotation\Groups;

class PersonalDataDto
{
    public function __construct(
        #[Groups(['user:personal_data:write'])]
        private string $firstName,

        #[Groups(['user:personal_data:write'])]
        private string $lastName,

        #[Groups(['user:personal_data:write'])]
        private int $age,

        #[Groups(['user:personal_data:write'])]
        private bool $isMale,

        #[Groups(['user:personal_data:write'])]
        private string $phone,

        #[Groups(['user:personal_data:write'])]
        private string $email
    ) {
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getIsMale(): bool
    {
        return $this->isMale;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}

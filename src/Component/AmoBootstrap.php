<?php

declare(strict_types=1);

namespace App\Component;

use AmoCRM\Client\AmoCRMApiClient;
use App\Component\Core\ParameterGetter;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class AmoBootstrap
{
    public function __construct(private ParameterGetter $parameterGetter)
    {
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function getApiClient(): AmoCRMApiClient
    {
        $id = $this->parameterGetter->getString('amo_client_id');
        $secretKey = $this->parameterGetter->getString('amo_secret_key');
        $authUri = $this->parameterGetter->getString('amo_auth_uri');

        return new AmoCRMApiClient($id, $secretKey, $authUri);
    }
}

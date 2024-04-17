<?php

declare(strict_types=1);

namespace App\Controller\amoCRM;

use App\Component\AmoBootstrap;
use App\Component\Core\ParameterGetter;
use App\Component\Token\TokenActions;
use App\Component\User\Dtos\TokensDto;
use App\Controller\Base\AbstractController;
use ErrorException;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

class AuthAmoCrm extends AbstractController
{
    public function __construct(
        private ParameterGetter $parameterGetter,
        private AmoBootstrap $apiClient,
        private TokenActions $tokenActions,
    ) {
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface|ErrorException
     */
    public function __invoke(Request $request, SerializerInterface $serializer): TokensDto
    {
        $code = $request->query->get('code');

        if ($code === null || $code === '') {
            throw new BadRequestHttpException('INVALID REQUEST');
        }

        $domain = $this->parameterGetter->getString('amo_acc_domain');

        $apiClient = $this->apiClient->getApiClient();
        $apiClient->setAccountBaseDomain($domain);

        try {
            $accessToken = $apiClient->getOAuthClient()->getAccessTokenByCode($code);

            if (!$accessToken->hasExpired()) {
                $this->tokenActions->saveToken($accessToken);
            }
        } catch (Exception $e) {
            throw new ErrorException((string)$e);
        }

        return new TokensDto($accessToken->getToken(), $accessToken->getRefreshToken());
    }
}

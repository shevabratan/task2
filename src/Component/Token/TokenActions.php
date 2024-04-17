<?php

declare(strict_types=1);

namespace App\Component\Token;

use App\Component\Core\ParameterGetter;
use App\Entity\Token;
use App\Repository\TokenRepository;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TokenActions
{
    public function __construct(
        private TokenRepository $tokenRepository,
        private TokenManager $tokenManager,
        private ParameterGetter $parameterGetter
    ) {
    }

    public function saveToken($token): void
    {
        $tokenEnt = $this->tokenRepository->find(1);

        if ($tokenEnt === null) {
            $tokenEnt = (new Token())
                ->setTokenType("Bearer")
                ->setAccessToken($token->getToken())
                ->setRefreshToken($token->getRefreshToken())
                ->setExpires($token->getExpires());
        } else {
            $tokenEnt->setAccessToken($token->getToken());
            $tokenEnt->setRefreshToken($token->getRefreshToken());
            $tokenEnt->setExpires($token->getExpires());
        }

        $this->tokenManager->save($tokenEnt, true);
    }

    public function getToken(): AccessToken
    {
        $tokenEnt = $this->tokenRepository->find(1);

        if ($tokenEnt === null) {
            throw new NotFoundHttpException('Access token not found');
        }

        return new AccessToken([
            'access_token' => $tokenEnt->getAccessToken(),
            'refresh_token' => $tokenEnt->getRefreshToken(),
            'expires' => $tokenEnt->getExpires(),
            'baseDomain' => $this->parameterGetter->getString('amo_acc_domain')
        ]);
    }
}

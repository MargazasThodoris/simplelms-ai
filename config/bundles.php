<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Knp\Bundle\PaginatorBundle\KnpPaginatorBundle;
use Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle;
use Nelmio\ApiDocBundle\NelmioApiDocBundle;
use Nelmio\CorsBundle\NelmioCorsBundle;
use Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    MonologBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    LexikJWTAuthenticationBundle::class => ['all' => true],
    NelmioCorsBundle::class => ['all' => true],
    NelmioApiDocBundle::class => ['all' => true],
    KnpPaginatorBundle::class => ['all' => true],
    StofDoctrineExtensionsBundle::class => ['all' => true],
    DebugBundle::class => ['dev' => true],
    WebProfilerBundle::class => ['dev' => true],
    DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    MakerBundle::class => ['dev' => true],
];
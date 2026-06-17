<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Analytics\PredictiveRetentionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scheduled nightly via AWS EventBridge → ECS Fargate scheduled task.
 * Can also be run manually: php bin/console app:retention:sweep
 */
#[AsCommand(
    name: 'app:retention:sweep',
    description: 'Run the nightly predictive retention analysis for all active organizations.',
)]
final class RetentionSweepCommand extends Command
{
    public function __construct(
        private readonly PredictiveRetentionService $retentionService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('org-id', null, InputOption::VALUE_OPTIONAL, 'Limit sweep to a specific organization ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $orgId  = $input->getOption('org-id');
        $start  = microtime(true);

        $io->title('simplelms — Predictive Retention Sweep');

        if ($orgId) {
            $orgIds = [(int) $orgId];
            $io->info("Running sweep for organization #{$orgId}");
        } else {
            // In production: fetch all active organization IDs
            $orgIds = $this->em->getRepository(\App\Entity\Organization::class)->findActiveIds();
            $io->info(sprintf('Running sweep for %d organizations', count($orgIds)));
        }

        $totalAtRisk = 0;
        foreach ($orgIds as $id) {
            try {
                $atRiskUsers = $this->retentionService->runOrganizationSweep($id);
                $totalAtRisk += count($atRiskUsers);
                $io->writeln(sprintf('  Org #%d → %d at-risk learners found', $id, count($atRiskUsers)));
            } catch (\Throwable $e) {
                $io->error("Failed for org #{$id}: {$e->getMessage()}");
            }
        }

        $elapsed = round(microtime(true) - $start, 2);
        $io->success(sprintf(
            'Sweep complete in %.2fs | Total at-risk learners: %d | Nudges dispatched.',
            $elapsed,
            $totalAtRisk
        ));

        return Command::SUCCESS;
    }
}

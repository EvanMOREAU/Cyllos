<?php

namespace App\Command;

use App\Message\FetchClientPaymentsMessage;
use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Catch-up fetch of recent HelloAsso payments, for every active client (or a
 * single one via --client). Equivalent to the manual "Synchro Hello Asso"
 * button in the legacy tool, run here as a safety net for missed webhooks.
 *
 * By default (the scheduled run) it fans out one FetchClientPaymentsMessage per
 * active client onto the async queue, each with a small random delay, so the
 * clients are fetched independently by the worker and a slow one doesn't hold up
 * the rest. Use --sync (or --client) to run the fetch inline instead.
 */
#[AsCommand(
    name: 'app:helloasso:fetch',
    description: 'Fetches recent HelloAsso payments for active clients, to catch up on any missed webhook notification.',
)]
class FetchHelloAssoPaymentsCommand extends Command
{
    /** Max random delay, in ms, spread over the per-client messages (jitter). */
    private const FANOUT_JITTER_MS = 30_000;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Only fetch for this client slug (runs inline)');
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Run every client fetch inline instead of dispatching async messages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clientSlug = $input->getOption('client');
        if ($clientSlug !== null) {
            $client = $this->clientRepository->findOneBySlug($clientSlug);
            if ($client === null) {
                $io->error(\sprintf('No client found with slug "%s".', $clientSlug));

                return Command::FAILURE;
            }

            $added = $this->paymentProcessor->fetchMissingPayments($client);
            $io->writeln(\sprintf('%s: %d nouveau(x) paiement(s)', $client->getName(), $added));

            return Command::SUCCESS;
        }

        $clients = $this->clientRepository->findAllActive();

        if ($input->getOption('sync')) {
            foreach ($clients as $client) {
                $added = $this->paymentProcessor->fetchMissingPayments($client);
                $io->writeln(\sprintf('%s: %d nouveau(x) paiement(s)', $client->getName(), $added));
            }

            return Command::SUCCESS;
        }

        foreach ($clients as $client) {
            $this->messageBus->dispatch(
                new FetchClientPaymentsMessage($client->getId()),
                [new DelayStamp(random_int(0, self::FANOUT_JITTER_MS))],
            );
        }

        $io->writeln(\sprintf('%d client(s) actif(s) : fetch dispatché sur la file async.', \count($clients)));

        return Command::SUCCESS;
    }
}

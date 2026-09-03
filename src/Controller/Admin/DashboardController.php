<?php

namespace App\Controller\Admin;

use App\Entity\PaymentStatus;
use App\Monitoring\QueueStatus;
use App\Repository\ClientRepository;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/tableau-de-bord', name: 'admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    private const STATS_WINDOW_DAYS = 30;
    private const QUIET_CLIENT_HOURS = 48;
    private const STUCK_PAYMENT_AGE = '-15 minutes';

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly QueueStatus $queueStatus,
    ) {
    }

    public function __invoke(): Response
    {
        $since = new \DateTimeImmutable(\sprintf('-%d days', self::STATS_WINDOW_DAYS));
        $counts = $this->paymentRepository->countByStatusSince($since);

        $sum = static fn (PaymentStatus ...$statuses): int => array_sum(
            array_map(static fn (PaymentStatus $s) => $counts[$s->value] ?? 0, $statuses),
        );

        $total = array_sum($counts);
        $credited = $sum(PaymentStatus::Success, PaymentStatus::SuccessAuto);
        $failed = $sum(PaymentStatus::Fail);
        $toHandle = $sum(PaymentStatus::Todo, PaymentStatus::TooHigh, PaymentStatus::TooLate, PaymentStatus::Waiting, PaymentStatus::PreviewOk);

        // Ordered breakdown for the distribution bar (only non-zero slices).
        $breakdown = [];
        foreach (PaymentStatus::cases() as $status) {
            if (($counts[$status->value] ?? 0) > 0) {
                $breakdown[] = ['status' => $status, 'count' => $counts[$status->value]];
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'windowDays' => self::STATS_WINDOW_DAYS,
            'total' => $total,
            'credited' => $credited,
            'failed' => $failed,
            'toHandle' => $toHandle,
            'failRate' => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
            'breakdown' => $breakdown,
            'queue' => [
                'pending' => $this->queueStatus->pending(),
                'failed' => $this->queueStatus->failed(),
                'oldestPendingSeconds' => $this->queueStatus->oldestPendingSeconds(),
            ],
            'stuckAutomaticPayments' => $this->paymentRepository->countStuckAutomaticTodoPayments(new \DateTimeImmutable(self::STUCK_PAYMENT_AGE)),
            'activeClients' => $this->clientRepository->countActive(),
            'quietClients' => $this->clientRepository->findActiveQuietSince(new \DateTimeImmutable(\sprintf('-%d hours', self::QUIET_CLIENT_HOURS))),
            'quietClientHours' => self::QUIET_CLIENT_HOURS,
            'recentFailures' => $this->paymentRepository->findRecentByStatus(PaymentStatus::Fail, 5),
        ]);
    }
}

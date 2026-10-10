<?php

declare(strict_types=1);
namespace App\Twig;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\UserAccounts;
use App\Service\Gmail\GmailQuotaPacer;
use Symfony\Bundle\SecurityBundle\Security;

/** No cross-request cache: FrankenPHP may serve another user next. */
final readonly class GmailSyncGlobal
{
    public function __construct(private Security $security, private UserAccounts $accounts, private GmailQuotaPacer $pacer) {}

    /** @return list<array{account: Account, warning: string, retryAt: ?\DateTimeImmutable}> */
    public function warnings(?Account $scope = null): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) { return []; }
        $result = [];
        foreach ($this->accounts->active($user) as $account) {
            if (!$account->isGmail() || (null !== $scope && $scope->id !== $account->id)) { continue; }
            $health = $this->pacer->health($account);
            if (null !== $health['warning']) { $result[] = ['account' => $account] + $health; }
        }
        return $result;
    }
}

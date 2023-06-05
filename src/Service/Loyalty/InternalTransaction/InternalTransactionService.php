<?php


namespace App\Service\Loyalty\InternalTransaction;


use App\DBAL\Types\CurrencyType;
use App\DTO\Loyalty\InternalTransaction\ClearPurchaseBlockedAmountDto;
use App\DTO\Loyalty\InternalTransaction\TransferDto;
use App\Entity\ApplicationUser\ApplicationUser;
use App\Entity\Loyalty\ExternalTransaction;
use App\Entity\Loyalty\ExternalTransactionSource;
use App\Entity\Loyalty\LoyaltyAccount;
use App\Entity\Loyalty\LoyaltyAccountType;
use App\Entity\Loyalty\LoyaltyProject;
use App\Entity\Loyalty\LoyaltyProjectUserUnits;
use App\Entity\Loyalty\LoyaltyTransaction;
use App\Entity\Loyalty\LoyaltyTransactionType;
use App\Exception\InvalidArgumentException;
use App\Repository\Loyalty\LoyaltyAccountRepository;
use App\Repository\Loyalty\LoyaltyAccountTypeRepository;
use App\Repository\Loyalty\LoyaltyProjectRepository;
use App\Repository\Loyalty\LoyaltyProjectUserUnitsRepository;
use App\Repository\Loyalty\LoyaltyTransactionRepository;
use App\Repository\Loyalty\LoyaltyTransactionTypeRepository;
use App\Service\Payment\MoneyHelper;
use App\Traits\LoggerTrait;
use Doctrine\ORM\EntityManagerInterface;
use Money\Currency;
use Money\Money as MoneyPattern;
use Throwable;

/**
 * Class InternalTransactionService
 * @package App\Service\Loyalty\InternalTransaction
 */
class InternalTransactionService
{
    use LoggerTrait;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var LoyaltyAccountRepository
     */
    private $loyaltyAccountRepository;

    /**
     * @var LoyaltyTransactionTypeRepository
     */
    private $loyaltyTransactionTypeRepository;

    /**
     * @var LoyaltyAccountTypeRepository
     */
    private $loyaltyAccountTypeRepository;

    /**
     * @var LoyaltyTransactionRepository
     */
    private $loyaltyTransactionRepository;

    /**
     * @var LoyaltyProjectRepository
     */
    private $loyaltyProjectRepository;

    /**
     * @var LoyaltyProjectUserUnitsRepository
     */
    private $loyaltyProjectUserUnitsRepository;

    /**
     * InternalTransactionService constructor.
     * @param EntityManagerInterface $entityManager
     * @param LoyaltyAccountRepository $loyaltyAccountRepository
     * @param LoyaltyAccountTypeRepository $loyaltyAccountTypeRepository
     * @param LoyaltyTransactionRepository $loyaltyTransactionRepository
     * @param LoyaltyTransactionTypeRepository $loyaltyTransactionTypeRepository
     * @param LoyaltyProjectRepository $loyaltyProjectRepository
     * @param LoyaltyProjectUserUnitsRepository $loyaltyProjectUserUnitsRepository
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoyaltyAccountRepository $loyaltyAccountRepository,
        LoyaltyAccountTypeRepository $loyaltyAccountTypeRepository,
        LoyaltyTransactionRepository $loyaltyTransactionRepository,
        LoyaltyTransactionTypeRepository $loyaltyTransactionTypeRepository,
        LoyaltyProjectRepository $loyaltyProjectRepository,
        LoyaltyProjectUserUnitsRepository $loyaltyProjectUserUnitsRepository
    )
    {
        $this->entityManager = $entityManager;
        $this->loyaltyAccountRepository = $loyaltyAccountRepository;
        $this->loyaltyTransactionTypeRepository = $loyaltyTransactionTypeRepository;
        $this->loyaltyAccountTypeRepository = $loyaltyAccountTypeRepository;
        $this->loyaltyTransactionRepository = $loyaltyTransactionRepository;
        $this->loyaltyProjectRepository = $loyaltyProjectRepository;
        $this->loyaltyProjectUserUnitsRepository = $loyaltyProjectUserUnitsRepository;
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @throws Throwable
     */
    public function processExternalTransactionClearing(ExternalTransaction $externalTransaction): void
    {
        $this->logger->info(self::class . '::processExternalTransactionClearing', [
            'action' => 'event_received',
            'transactionId' => $externalTransaction->getId(),
        ]);

        $this->entityManager->beginTransaction();

        $this->entityManager->persist($externalTransaction);

        $clearPurchaseBlockedAmountDto = $this->processClearingPurchaseBlockedAmount($externalTransaction);

        $this->clearPurchaseBlockedAmount($clearPurchaseBlockedAmountDto);

        try {
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info(self::class . '::processExternalTransactionClearing', [
                'action' => 'doctrine_transaction_committed',
                'transactionId' => $externalTransaction->getId(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error(self::class . '::processExternalTransactionClearing', [
                'action' => 'exception',
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTrace(),
            ]);

            $this->entityManager->rollback();
            throw $exception;
        }
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @throws Throwable
     */
    public function processExternalTransactionPurchase(ExternalTransaction $externalTransaction): void
    {
        $this->logger->info(self::class . '::processExternalTransactionPurchase', [
            'action' => 'event_received',
            'transactionId' => $externalTransaction->getId(),
        ]);

        $this->entityManager->beginTransaction();

        $this->entityManager->persist($externalTransaction);

        $this->processExternalTransferCommissionTransaction($externalTransaction);
        $this->processExternalUserShareTransaction($externalTransaction);

        if ($externalTransaction->getImpactProjectTransaction()) {
            $this->transferExternalImpactDonate($externalTransaction);
        }

        try {
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info(self::class . '::processExternalTransactionPurchase', [
                'action' => 'doctrine_transaction_committed',
                'transactionId' => $externalTransaction->getId(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error(self::class . '::processExternalTransactionPurchase', [
                'action' => 'exception',
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTrace(),
            ]);

            $this->entityManager->rollback();
            throw $exception;
        }
    }

    /**
     * @param ExternalTransaction $externalTransaction
     *
     * @throws Throwable
     */
    public function processExternalTransactionRefund(ExternalTransaction $externalTransaction): void
    {
        $this->logger->info(self::class . '::processExternalTransactionRefund', [
            'action' => 'event_received',
            'transactionId' => $externalTransaction->getId(),
        ]);

        $this->entityManager->beginTransaction();

        $this->entityManager->persist($externalTransaction->getRefundedTransaction());
        $this->entityManager->persist($externalTransaction);

        if (!$externalTransaction->getRefundedTransaction() instanceof ExternalTransaction) {
            $this->logger->error(self::class . '::processExternalTransactionRefund', [
                'action' => 'undefined_refunded_transaction',
                'transactionId' => $externalTransaction->getId(),
            ]);

            return;
        }


        if ($externalTransaction->getImpactProjectTransaction()) {
            $this->transferExternalImpactDonate($externalTransaction, true);
        }
        $this->processExternalUserShareTransaction($externalTransaction, true);
        $this->processExternalTransferCommissionTransaction($externalTransaction, true);

        try {
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info(self::class . '::processExternalTransactionRefund', [
                'action' => 'doctrine_transaction_committed',
                'transactionId' => $externalTransaction->getId(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error(self::class . '::processExternalTransactionRefund', [
                'action' => 'exception',
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTrace(),
            ]);

            $this->entityManager->rollback();
            throw $exception;
        }
    }

    /**
     * @param TransferDto $transferDto
     * @param bool $shouldBlockAmount
     * @return LoyaltyTransaction
     * @throws Throwable
     */
    public function transfer(TransferDto $transferDto, bool $shouldBlockAmount = true): LoyaltyTransaction
    {
        $this->logger->info(self::class . '::transfer', [
            'action' => 'initialize_transfer_process',
            'credit_account_id' => $transferDto->getCreditAccount()->getId(),
            'debit_account_id' => $transferDto->getDebitAccount()->getId(),
            'amount' => $transferDto->getAmount(),
        ]);

        $this->entityManager->beginTransaction();

        $loyaltyTransactionEntity = new LoyaltyTransaction();
        $loyaltyTransactionEntity->setCreditAccount($transferDto->getCreditAccount());
        $loyaltyTransactionEntity->setDebitAccount($transferDto->getDebitAccount());
        $loyaltyTransactionEntity->setType($transferDto->getType());

        if ($transferDto->getDateTime() instanceof \DateTime) {
            $loyaltyTransactionEntity->setDate($transferDto->getDateTime());
        } else {
            $loyaltyTransactionEntity->setDate(new \DateTime('now'));
        }

        if ($transferDto->getPayoutDate() instanceof \DateTime) {
            $loyaltyTransactionEntity->setPayoutDate($transferDto->getPayoutDate());
        }

        $loyaltyTransactionEntity->setPayoutStatus($transferDto->getPayoutStatus());

        $creditAmount = new MoneyPattern(
            $transferDto->getAmount(),
            new Currency($transferDto->getCurrency())
        );
        $loyaltyTransactionEntity->setCreditAmount($creditAmount);

        $debitAmount = new MoneyPattern(
            $transferDto->getAmount(),
            new Currency($transferDto->getCurrency())
        );
        $loyaltyTransactionEntity->setDebitAmount($debitAmount);

        if ($transferDto->getExternalTransaction() instanceof ExternalTransaction) {
            $loyaltyTransactionEntity->setExternalTransaction($transferDto->getExternalTransaction());
        }

        $this->entityManager->persist($loyaltyTransactionEntity);

        $isRefund = $transferDto->getExternalTransaction() instanceof ExternalTransaction
            && $transferDto->getExternalTransaction()->getRefundedTransaction() instanceof ExternalTransaction
        ;

        $this->modifyLoyaltyAccountBalance($loyaltyTransactionEntity, $shouldBlockAmount, $isRefund);

        if ($loyaltyTransactionEntity->getDebitAccount()->getProject() instanceof LoyaltyProject) {
            $this->updateProjectUnitsAmount($transferDto, $loyaltyTransactionEntity);
        }

        try {
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info(self::class . '::transfer', [
                'action' => 'transfer_completed',
                'loyalty_transaction_id' => $loyaltyTransactionEntity->getId(),
                'credit_account_id' => $transferDto->getCreditAccount()->getId(),
                'debit_account_id' => $transferDto->getDebitAccount()->getId(),
                'amount' => $transferDto->getAmount(),
            ]);

            return $loyaltyTransactionEntity;
        } catch (Throwable $exception) {
            $this->logger->error(self::class . '::transfer', [
                'action' => 'exception',
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTrace(),
            ]);

            $this->entityManager->rollback();
            throw $exception;
        }
    }

    /**
     * @param LoyaltyAccount $loyaltyAccount
     *
     * @return float
     */
    public function getPointsEarned(LoyaltyAccount $loyaltyAccount): float
    {
        $points = 0;
        $loyaltyTransactions = $this->loyaltyTransactionRepository->findBy([
            'debitAccount' => $loyaltyAccount,
        ]);

        /** @var LoyaltyTransaction $loyaltyTransaction */
        foreach ($loyaltyTransactions as $loyaltyTransaction) {
            $points += (float) $loyaltyTransaction->getDebitAmount()->getAmount();
        }

        return $points;
    }

    /**
     * @param LoyaltyAccount $loyaltyAccount
     *
     * @return float
     */
    public function getPointsCleared(LoyaltyAccount $loyaltyAccount): float
    {
        return
            $this->getPointsEarned($loyaltyAccount)
            - (float) $loyaltyAccount->getBlockedAmount()->getAmount()
        ;
    }

    /**
     * @param LoyaltyAccount $loyaltyAccount
     *
     * @return float
     */
    public function getAvailableBalance(LoyaltyAccount $loyaltyAccount): float
    {
        $points = 0;
        $loyaltyTransactions = $this->loyaltyTransactionRepository->findBy([
            'creditAccount' => $loyaltyAccount,
        ]);

        /** @var LoyaltyTransaction $loyaltyTransaction */
        foreach ($loyaltyTransactions as $loyaltyTransaction) {
            $points += (float) $loyaltyTransaction->getCreditAmount()->getAmount();
        }

        return $this->getPointsCleared($loyaltyAccount) - $points;
    }

    /**
     * @param TransferDto $transferDto
     * @param LoyaltyTransaction $loyaltyTransactionEntity
     */
    protected function updateProjectUnitsAmount(TransferDto $transferDto, LoyaltyTransaction $loyaltyTransactionEntity): void
    {
        /** @var LoyaltyProject $project */
        $project = $loyaltyTransactionEntity->getDebitAccount()->getProject();
        /** @var ApplicationUser $user */
        $user = $loyaltyTransactionEntity->getCreditAccount()->getUser();

        if (!$transferDto->getAmount()) {
            return;
        }

        $pointsRate = $transferDto->getAmount() / $project->getUnitPoints();
        $unitsRate = $pointsRate * $project->getUnitAmount();

        $this->loyaltyProjectRepository->updateTotalAmountById(
            $project->getId(),
            $unitsRate
        );

        $userProjectUnitsEntity = $this->loyaltyProjectUserUnitsRepository->findOneBy([
            'project' => $project,
            'user' => $user,
        ]);

        if ($userProjectUnitsEntity instanceof LoyaltyProjectUserUnits) {
            $this->loyaltyProjectUserUnitsRepository->updateTotalAmountById(
                $userProjectUnitsEntity->getId(),
                $unitsRate
            );
        } else {
            $userProjectUnitsEntity = new LoyaltyProjectUserUnits();
            $userProjectUnitsEntity->setUser($user);
            $userProjectUnitsEntity->setProject($project);
            $userProjectUnitsEntity->setTotalUnitsAmount($unitsRate);

            $this->entityManager->persist($userProjectUnitsEntity);
        }
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @return ClearPurchaseBlockedAmountDto
     */
    protected function processClearingPurchaseBlockedAmount(ExternalTransaction $externalTransaction): ClearPurchaseBlockedAmountDto
    {
        $this->logger->info(self::class . '::processClearingPurchaseBlockedAmount', [
            'action' => 'initialize_clearing_external_transaction',
            'externalTransactionId' => $externalTransaction->getId(),
        ]);

        $refundedTransaction = $externalTransaction->getRefundedTransaction();
        $isRefund = $refundedTransaction instanceof ExternalTransaction;
        $loyaltyTransactions = $this->getLoyaltyTransactionsByExternalTransaction($externalTransaction);

        $clearPurchaseBlockedAmountDto = new ClearPurchaseBlockedAmountDto();

        /** @var LoyaltyTransaction $loyaltyTransaction */
        foreach ($loyaltyTransactions as $loyaltyTransaction) {
            $bankAccount = $isRefund ? $loyaltyTransaction->getDebitAccount() : $loyaltyTransaction->getCreditAccount();
            $userAccount = $isRefund ? $loyaltyTransaction->getCreditAccount() : $loyaltyTransaction->getDebitAccount();

            if (in_array($bankAccount->getId(), LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_BANKS)) {
                $clearPurchaseBlockedAmountDto->setExternalBankAccount($bankAccount);
                $clearPurchaseBlockedAmountDto->setExternalBankAccountClearedAmount(
                    (int) $loyaltyTransaction->getCreditAmount()->getAmount()
                );

                $this->logger->info(self::class . '::processClearingPurchaseBlockedAmount', [
                    'action' => 'external_bank_account_founded',
                    'accountId' => $clearPurchaseBlockedAmountDto->getExternalBankAccount()->getId(),
                    'amount' => $clearPurchaseBlockedAmountDto->getExternalBankAccountClearedAmount(),
                ]);
            }

            if ($bankAccount->getId() === LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_AMPLE_COMMISSION) {
                $clearPurchaseBlockedAmountDto->setAmpleCommissionAccount($bankAccount);

                $commissionAmount = $userCommission = 0;
                if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
                    $commissionAmount = $externalTransaction->getCommission();
                } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
                    $commissionAmount = $externalTransaction->getUserShare();
                }

                $externalCommission = new MoneyPattern(
                    MoneyHelper::negativeValueToPositiveValue($commissionAmount),
                    $loyaltyTransaction->getCreditAmount()->getCurrency()
                );

                if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
                    $userCommission = (int) $externalCommission
                        ->multiply(($externalTransaction->getUserShare() - 100) / 100)
                        ->getAmount()
                    ;
                } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
                    $userCommission = $externalTransaction->getCommission() - $externalTransaction->getUserShare();
                }

                $clearPurchaseBlockedAmountDto->setAmpleCommissionAccountClearedAmount(
                    MoneyHelper::positiveValueToNegativeValue($userCommission)
                );

                $this->logger->info(self::class . '::processClearingPurchaseBlockedAmount', [
                    'action' => 'ample_commission_account_founded',
                    'accountId' => $clearPurchaseBlockedAmountDto->getAmpleCommissionAccount()->getId(),
                    'amount' => $clearPurchaseBlockedAmountDto->getAmpleCommissionAccountClearedAmount(),
                ]);
            }

            if (
                $userAccount->getUser() instanceof ApplicationUser &&
                $userAccount->getType()->getId() === LoyaltyAccountType::LOYALTY_ACCOUNT_TYPE_USER
            ) {
                $clearPurchaseBlockedAmountDto->setApplicationUserAccount($userAccount);

                $commissionAmount = $userCommission = 0;
                if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
                    $commissionAmount = $externalTransaction->getCommission();
                } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
                    $commissionAmount = $externalTransaction->getUserShare();
                }

                $externalCommission = new MoneyPattern(
                    MoneyHelper::negativeValueToPositiveValue($commissionAmount),
                    $loyaltyTransaction->getCreditAmount()->getCurrency()
                );

                if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
                    $userCommission = (int) $externalCommission
                        ->multiply($externalTransaction->getUserShare() / 100)
                        ->getAmount()
                    ;
                } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
                    $userCommission = $externalTransaction->getUserShare();
                }

                $clearPurchaseBlockedAmountDto->setApplicationUserAccountClearedAmount(
                    MoneyHelper::positiveValueToNegativeValue($userCommission)
                );

                $this->logger->info(self::class . '::processClearingPurchaseBlockedAmount', [
                    'action' => 'application_user_account_founded',
                    'accountId' => $clearPurchaseBlockedAmountDto->getApplicationUserAccount()->getId(),
                    'amount' => $clearPurchaseBlockedAmountDto->getApplicationUserAccountClearedAmount(),
                ]);
            }
        }

        return $clearPurchaseBlockedAmountDto;
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @param bool $isRefund
     *
     * @throws Throwable
     */
    protected function processExternalTransferCommissionTransaction(ExternalTransaction $externalTransaction, bool $isRefund = false): void
    {
        $this->logger->info(self::class . '::processExternalTransferCommissionTransaction', [
            'transactionId' => $externalTransaction->getId(),
            'isRefund' => $isRefund,
        ]);

        $externalBankAccount = $this->getSystemLoyaltyAccountByExternalTransaction($externalTransaction);
        $ampleCommissionAccount = $this->getLoyaltyAccountById(LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_AMPLE_COMMISSION);

        $transferCommissionDto = new TransferDto();
        $transferCommissionDto->setExternalTransaction($externalTransaction);
        $transferCommissionDto->setCurrency($externalTransaction->getAmount()->getCurrency());

        if ($isRefund) {
            $transferCommissionDto->setCreditAccount($ampleCommissionAccount);
            $transferCommissionDto->setDebitAccount($externalBankAccount);
        } else {
            $transferCommissionDto->setCreditAccount($externalBankAccount);
            $transferCommissionDto->setDebitAccount($ampleCommissionAccount);
        }

        $amount = $externalTransaction->getCommission();

        $transferCommissionDto->setAmount($amount);

        $loyaltyTransactionTypeId = $isRefund
            ? LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_REFUND
            : LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_PURCHASE
        ;
        $transferCommissionDto->setType(
            $this->getLoyaltyTransactionTypeById($loyaltyTransactionTypeId)
        );

        $this->logger->info(self::class . '::processExternalTransferCommissionTransaction', [
            'action' => 'calculate_transfer_dto',
            'transactionId' => $externalTransaction->getId(),
            'credit_account_id' => $transferCommissionDto->getCreditAccount()->getId(),
            'debit_account_id' => $transferCommissionDto->getDebitAccount()->getId(),
            'amount' => $transferCommissionDto->getAmount(),
            'isRefund' => $isRefund,
        ]);

        $shouldBlockAmount = true;
        if ($isRefund && ($externalTransaction->getSource()->getId() === ExternalTransactionSource::LOYALTY_SOURCE_SHOPIFY)) {
            $shouldBlockAmount = false;
        }

        $this->transfer($transferCommissionDto, $shouldBlockAmount);
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @param bool $isRefund
     *
     * @throws Throwable
     */
    protected function transferExternalImpactDonate(ExternalTransaction $externalTransaction, bool $isRefund = false): void
    {
        $transactionType = $isRefund
            ? LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_REFUND
            : LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_DONATION
        ;

        $transferDto = new TransferDto();
        $transferDto->setType(
            $this->getLoyaltyTransactionTypeById($transactionType)
        );
        $transferDto->setAmount($externalTransaction->getUserShare());
        $transferDto->setCurrency(CurrencyType::DEFAULT_SYSTEM_CURRENCY);
        $transferDto->setExternalTransaction($externalTransaction);
        $transferDto->setPayoutDate(
            (new \DateTime('last day of next month'))
        );

        /** @var LoyaltyAccount $projectAccount */
        $projectAccount = $externalTransaction->getImpactProject()->getLoyaltyAccount();
        /** @var LoyaltyAccount $userAccount */
        $userAccount = $externalTransaction->getUser()->getLoyaltyAccount();

        if ($isRefund) {
            $transferDto->setCreditAccount($projectAccount);
            $transferDto->setDebitAccount($userAccount);
        } else {
            $transferDto->setCreditAccount($userAccount);
            $transferDto->setDebitAccount($projectAccount);
        }

        $this->transfer($transferDto, false);
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @param bool $isRefund
     *
     * @throws Throwable
     */
    protected function processExternalUserShareTransaction(ExternalTransaction $externalTransaction, bool $isRefund = false): void
    {
        $this->logger->info(self::class . '::processExternalUserShareTransaction', [
            'transactionId' => $externalTransaction->getId(),
            'isRefund' => $isRefund,
        ]);

        $ampleCommissionAccount = $this->getLoyaltyAccountById(LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_AMPLE_COMMISSION);

        if ($externalTransaction->getUser() instanceof ApplicationUser) {
            $userAccount = $this->getLoyaltyAccountByApplicationUser($externalTransaction->getUser());
        } else {
            $userAccount = $externalTransaction->getDefaultBankAccount();
        }

        $transferUserShareDto = new TransferDto();
        $transferUserShareDto->setExternalTransaction($externalTransaction);
        $transferUserShareDto->setDateTime((new \DateTime('now'))->modify('+1 ms'));
        $transferUserShareDto->setCurrency($externalTransaction->getAmount()->getCurrency());

        if ($isRefund) {
            $transferUserShareDto->setCreditAccount($userAccount);
            $transferUserShareDto->setDebitAccount($ampleCommissionAccount);
        } else {
            $transferUserShareDto->setCreditAccount($ampleCommissionAccount);
            $transferUserShareDto->setDebitAccount($userAccount);
        }

        $commissionAmount = $userCommission = 0;
        if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
            $commissionAmount = $externalTransaction->getCommission();
        } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
            $commissionAmount = $externalTransaction->getUserShare();
        }

        $commissionAmount = $isRefund
            ? MoneyHelper::positiveValueToNegativeValue($commissionAmount)
            : MoneyHelper::negativeValueToPositiveValue($commissionAmount)
        ;
        $externalCommission = new MoneyPattern($commissionAmount, new Currency($transferUserShareDto->getCurrency()));

        if ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_PERCENT) {
            $userCommission = (int) $externalCommission
                ->multiply($externalTransaction->getUserShare() / 100)
                ->getAmount()
            ;
        } elseif ($externalTransaction->getCommissionType() === ExternalTransaction::COMMISSION_TYPE_STATIC) {
            $userCommission = $externalTransaction->getUserShare();
        }

        $transferUserShareDto->setAmount($userCommission);

        $loyaltyTransactionTypeId = $isRefund
            ? LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_REFUND
            : LoyaltyTransactionType::LOYALTY_TRANSACTION_TYPE_PURCHASE
        ;
        $transferUserShareDto->setType(
            $this->getLoyaltyTransactionTypeById($loyaltyTransactionTypeId)
        );

        $this->logger->info(self::class . '::processExternalUserShareTransaction', [
            'action' => 'calculate_transfer_dto',
            'transactionId' => $externalTransaction->getId(),
            'credit_account_id' => $transferUserShareDto->getCreditAccount()->getId(),
            'debit_account_id' => $transferUserShareDto->getDebitAccount()->getId(),
            'amount' => $transferUserShareDto->getAmount(),
            'isRefund' => $isRefund,
        ]);

        $shouldBlockAmount = true;
        if ($isRefund && ($externalTransaction->getSource()->getId() === ExternalTransactionSource::LOYALTY_SOURCE_SHOPIFY)) {
            $shouldBlockAmount = false;
        }

        $this->transfer($transferUserShareDto, $shouldBlockAmount);
    }

    /**
     * @param ClearPurchaseBlockedAmountDto $clearPurchaseBlockedAmountDto
     */
    protected function clearPurchaseBlockedAmount(ClearPurchaseBlockedAmountDto $clearPurchaseBlockedAmountDto): void
    {
        if ($clearPurchaseBlockedAmountDto->getExternalBankAccount() instanceof LoyaltyAccount) {
            $this->logger->info(self::class . '::clearPurchaseBlockedAmount', [
                'action' => 'clear_blocked_amount_for_external_bank_account',
                'accountId' => $clearPurchaseBlockedAmountDto->getExternalBankAccount()->getId(),
                'amount' => $clearPurchaseBlockedAmountDto->getExternalBankAccountClearedAmount(),
            ]);

            $this->loyaltyAccountRepository->updateAmountById(
                $clearPurchaseBlockedAmountDto->getExternalBankAccount()->getId(),
                0,
                $clearPurchaseBlockedAmountDto->getExternalBankAccountClearedAmount()
            );
        } else {
            throw new InvalidArgumentException('ExternalBankAccount is undefined');
        }

        if ($clearPurchaseBlockedAmountDto->getAmpleCommissionAccount() instanceof LoyaltyAccount) {
            $this->logger->info(self::class . '::clearPurchaseBlockedAmount', [
                'action' => 'clear_blocked_amount_for_ample_commission_account',
                'accountId' => $clearPurchaseBlockedAmountDto->getAmpleCommissionAccount()->getId(),
                'amount' => $clearPurchaseBlockedAmountDto->getAmpleCommissionAccountClearedAmount(),
            ]);

            $this->loyaltyAccountRepository->updateAmountById(
                $clearPurchaseBlockedAmountDto->getAmpleCommissionAccount()->getId(),
                0,
                $clearPurchaseBlockedAmountDto->getAmpleCommissionAccountClearedAmount()
            );
        } else {
            throw new InvalidArgumentException('AmpleCommissionAccount is undefined');
        }

        if ($clearPurchaseBlockedAmountDto->getApplicationUserAccount() instanceof LoyaltyAccount) {
            $this->logger->info(self::class . '::clearPurchaseBlockedAmount', [
                'action' => 'clear_blocked_amount_for_application_user_account',
                'accountId' => $clearPurchaseBlockedAmountDto->getApplicationUserAccount()->getId(),
                'amount' => $clearPurchaseBlockedAmountDto->getApplicationUserAccountClearedAmount(),
            ]);

            $this->loyaltyAccountRepository->updateAmountById(
                $clearPurchaseBlockedAmountDto->getApplicationUserAccount()->getId(),
                0,
                $clearPurchaseBlockedAmountDto->getApplicationUserAccountClearedAmount()
            );
        } else {
            throw new InvalidArgumentException('ApplicationUserAccount is undefined');
        }
    }

    /**
     * @param LoyaltyTransaction $loyaltyTransaction
     * @param bool $shouldBlockAmount
     * @param bool $isRefund
     */
    protected function modifyLoyaltyAccountBalance(LoyaltyTransaction $loyaltyTransaction, bool $shouldBlockAmount = true, bool $isRefund = false): void
    {
        $creditAccountAmount = MoneyHelper::positiveValueToNegativeValue((int) $loyaltyTransaction->getCreditAmount()->getAmount());
        $debitAccountAmount = $isRefund
            ? MoneyHelper::negativeValueToPositiveValue((int) $loyaltyTransaction->getDebitAmount()->getAmount())
            : (int) $loyaltyTransaction->getDebitAmount()->getAmount()
        ;

        $this->loyaltyAccountRepository->updateAmountById(
            $loyaltyTransaction->getCreditAccount()->getId(),
            $creditAccountAmount,
            $shouldBlockAmount ? $creditAccountAmount : 0
        );

        $this->logger->info(self::class . '::modifyLoyaltyAccountBalance', [
            'action' => 'for_credit_account',
            'amount' => $creditAccountAmount,
            'blocked_amount' => $creditAccountAmount,
        ]);

        $this->loyaltyAccountRepository->updateAmountById(
            $loyaltyTransaction->getDebitAccount()->getId(),
            $debitAccountAmount,
            $shouldBlockAmount ? $debitAccountAmount : 0
        );

        $this->logger->info(self::class . '::modifyLoyaltyAccountBalance', [
            'action' => 'for_debit_account',
            'amount' => $debitAccountAmount,
            'blocked_amount' => $debitAccountAmount,
            'should_block_amount' => $shouldBlockAmount,
        ]);
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @return LoyaltyAccount
     */
    protected function getSystemLoyaltyAccountByExternalTransaction(ExternalTransaction $externalTransaction): LoyaltyAccount
    {
        $this->logger->info(self::class . '::getSystemLoyaltyAccountByExternalTransaction', [
            'transactionId' => $externalTransaction->getId(),
        ]);

        switch ($externalTransaction->getSource()->getId()) {
            case ExternalTransactionSource::LOYALTY_SOURCE_FIDEL:
                $systemAccountId = LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_FIDEL_BANK;
                break;

            case ExternalTransactionSource::LOYALTY_SOURCE_AWIN:
                $systemAccountId = LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_AWIN_BANK;
                break;

            case ExternalTransactionSource::LOYALTY_SOURCE_RAKUTEN:
                $systemAccountId = LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_RAKUTEN_BANK;
                break;

            case ExternalTransactionSource::LOYALTY_SOURCE_SHOPIFY:
                $systemAccountId = LoyaltyAccount::LOYALTY_ACCOUNT_SYSTEM_SHOPIFY_BANK;
                break;

            default:
                throw new InvalidArgumentException('Undefined External transaction source');
        }

        return $this->getLoyaltyAccountById($systemAccountId);
    }

    /**
     * @param string $id
     * @return LoyaltyAccount
     */
    public function getLoyaltyAccountById(string $id): LoyaltyAccount
    {
        $this->logger->info(self::class . '::getLoyaltyAccountById', [
            'accountId' => $id,
        ]);

        /** @var LoyaltyAccount $loyaltyAccount */
        $loyaltyAccount = $this->loyaltyAccountRepository->find($id);

        if (!$loyaltyAccount instanceof LoyaltyAccount) {
            throw new InvalidArgumentException('Undefined LoyaltyAccount with id=' . $id);
        }

        return $loyaltyAccount;
    }

    /**
     * @param ApplicationUser $applicationUser
     * @return LoyaltyAccount
     */
    protected function getLoyaltyAccountByApplicationUser(ApplicationUser $applicationUser): LoyaltyAccount
    {
        $this->logger->info(self::class . '::getLoyaltyAccountByApplicationUser', [
            'applicationUserId' => $applicationUser->getId(),
        ]);

        $loyaltyAccount = $this->loyaltyAccountRepository->findOneBy(['user' => $applicationUser]);

        if (!$loyaltyAccount instanceof LoyaltyAccount) {
            throw new InvalidArgumentException('Undefined LoyaltyAccount with user=' . $applicationUser->getId());
        }

        return $loyaltyAccount;
    }

    /**
     * @param string $id
     * @return LoyaltyTransactionType
     */
    public function getLoyaltyTransactionTypeById(string $id): LoyaltyTransactionType
    {
        $this->logger->info(self::class . '::getLoyaltyTransactionTypeById', [
            'loyaltyTransactionType' => $id,
        ]);

        /** @var LoyaltyTransactionType $loyaltyTransactionType */
        $loyaltyTransactionType = $this->loyaltyTransactionTypeRepository->find($id);

        if (!$loyaltyTransactionType instanceof LoyaltyTransactionType) {
            throw new InvalidArgumentException('Undefined LoyaltyTransactionType with id=' . $id);
        }

        return $loyaltyTransactionType;
    }

    /**
     * @param string $id
     * @return LoyaltyAccountType
     */
    protected function getLoyaltyAccountTypeById(string $id): LoyaltyAccountType
    {
        $this->logger->info(self::class . '::getLoyaltyAccountTypeById', [
            'loyaltyAccountType' => $id,
        ]);

        /** @var LoyaltyAccountType $loyaltyAccountType */
        $loyaltyAccountType = $this->loyaltyAccountTypeRepository->find($id);

        if (!$loyaltyAccountType instanceof LoyaltyAccountType) {
            throw new InvalidArgumentException('Undefined LoyaltyAccountType with id=' . $id);
        }

        return $loyaltyAccountType;
    }

    /**
     * @param ExternalTransaction $externalTransaction
     * @return array
     */
    protected function getLoyaltyTransactionsByExternalTransaction(ExternalTransaction $externalTransaction): array
    {
        $this->logger->info(self::class . '::getLoyaltyTransactionsByExternalTransaction', [
            'externalTransactionId' => $externalTransaction->getId(),
        ]);

        return $this->loyaltyTransactionRepository->findBy(['externalTransaction' => $externalTransaction]);
    }

    /**
     * @param LoyaltyAccount $debitAccount
     * @param LoyaltyTransactionType $loyaltyTransactionType
     *
     * @return LoyaltyTransaction|null
     */
    public function getLoyaltyTransactionByDebitAccountAndType(LoyaltyAccount $debitAccount, LoyaltyTransactionType $loyaltyTransactionType): ?LoyaltyTransaction
    {
        $loyaltyTransaction = $this->loyaltyTransactionRepository->findOneBy([
            'debitAccount' => $debitAccount,
            'type' => $loyaltyTransactionType,
        ]);

        if ($loyaltyTransaction instanceof LoyaltyTransaction) {
            return $loyaltyTransaction;
        }

        return null;
    }
}
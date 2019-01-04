<?php
/**
 * Copyright (c) 2018 Adshares sp. z o.o.
 *
 * This file is part of AdServer
 *
 * AdServer is free software: you can redistribute and/or modify it
 * under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * AdServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AdServer. If not, see <https://www.gnu.org/licenses/>
 */

namespace Adshares\Adserver\Console\Commands;

use Adshares\Ads\AdsClient;
use Adshares\Ads\Driver\CommandError;
use Adshares\Ads\Entity\Transaction\SendManyTransaction;
use Adshares\Ads\Entity\Transaction\SendManyTransactionWire;
use Adshares\Ads\Entity\Transaction\SendOneTransaction;
use Adshares\Ads\Exception\CommandException;
use Adshares\Adserver\Exceptions\MissingInitialConfigurationException;
use Adshares\Adserver\Facades\DB;
use Adshares\Adserver\Models\AdsPayment;
use Adshares\Adserver\Models\NetworkHost;
use Adshares\Adserver\Models\User;
use Adshares\Adserver\Models\UserLedgerEntry;
use Adshares\Adserver\Services\PaymentDetailsProcessor;
use Adshares\Supply\Application\Service\DemandClient;
use Adshares\Supply\Application\Service\Exception\EmptyInventoryException;
use Adshares\Supply\Application\Service\Exception\UnexpectedClientResponseException;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Response;

class AdsProcessTx extends Command
{
    public const EXIT_CODE_SUCCESS = 0;

    public const EXIT_CODE_CANNOT_GET_BLOCK_IDS = 1;

    protected $signature = 'ads:process-tx';

    protected $description = 'Processes incoming txs';

    private $adServerAddress;

    /** @var DemandClient $demandClient */
    private $demandClient;

    /** @var PaymentDetailsProcessor $paymentDetailsProcessor */
    private $paymentDetailsProcessor;

    public function __construct()
    {
        parent::__construct();
        $this->adServerAddress = config('app.adshares_address');
    }

    public function handle(
        AdsClient $adsClient,
        PaymentDetailsProcessor $paymentDetailsProcessor,
        DemandClient $demandClient
    ): int {
        $this->info('Start processing incoming txs');
        $this->demandClient = $demandClient;
        $this->paymentDetailsProcessor = $paymentDetailsProcessor;

        try {
            $this->updateBlockIds($adsClient);
        } catch (CommandException $exc) {
            $code = $exc->getCode();
            $message = $exc->getMessage();
            $this->error(
                "Cannot update blocks due to CommandException:\n"."Code:\n  ${code}\n"."Message:\n  ${message}\n"
            );

            $this->info('Premature finish processing incoming txs.');

            return self::EXIT_CODE_CANNOT_GET_BLOCK_IDS;
        }

        $dbTxs = AdsPayment::where('status', AdsPayment::STATUS_NEW)->get();

        foreach ($dbTxs as $dbTx) {
            $this->handleDbTx($adsClient, $dbTx);
        }

        $this->info('Finish processing incoming txs');

        return self::EXIT_CODE_SUCCESS;
    }

    private function updateBlockIds(AdsClient $adsClient): void
    {
        $attempt = 0;
        $attemptMax = 5;
        while (true) {
            try {
                $response = $adsClient->getBlockIds();
                $updatedBlocks = $response->getUpdatedBlocks();
                $this->info("Updated blocks: ${updatedBlocks}");
                if ($updatedBlocks === 0) {
                    break;
                }
            } catch (CommandException $exc) {
                if (++$attempt < $attemptMax && CommandError::GET_SIGNATURE_UNAVAILABLE === $exc->getCode()) {
                    // try again after 3 seconds sleep
                    sleep(3);
                } else {
                    throw $exc;
                }
            }
        }
    }

    private function handleDbTx(AdsClient $adsClient, $dbTx): void
    {
        try {
            $txid = $dbTx->txid;
            $transaction = $adsClient->getTransaction($txid)->getTxn();
        } catch (CommandException $exc) {
            $code = $exc->getCode();
            $message = $exc->getMessage();
            $this->info(
                "Cannot get transaction [$txid] data due to CommandException:\nCode:\n  ${code}\n"
                ."Message:\n  ${message}\n"
            );

            return;
        }

        $type = $transaction->getType();
        switch ($type) {
            case 'send_many':
                /** @var $transaction SendManyTransaction */
                $this->handleSendManyTx($dbTx, $transaction);
                break;

            case 'send_one':
                /** @var $transaction SendOneTransaction */
                $this->handleSendOneTx($dbTx, $transaction);
                break;

            default:
                $dbTx->status = AdsPayment::STATUS_INVALID;
                $dbTx->save();
                break;
        }
    }

    private function handleSendManyTx(AdsPayment $dbTx, SendManyTransaction $transaction): void
    {
        $isTxTargetValid = false;
        $wiresCount = $transaction->getWireCount();

        if ($wiresCount > 0) {
            $wires = $transaction->getWires();

            foreach ($wires as $wire) {
                /** @var $wire SendManyTransactionWire */
                $targetAddr = $wire->getTargetAddress();
                if ($targetAddr === $this->adServerAddress) {
                    $isTxTargetValid = true;
                    break;
                }
            }
        }

        if ($isTxTargetValid) {
            $this->handleReservedTx($dbTx);
        } else {
            $dbTx->status = AdsPayment::STATUS_INVALID;
            $dbTx->save();
        }
    }

    private function handleReservedTx(AdsPayment $dbTx): void
    {
        if (!$this->handleIfEventPayment($dbTx)) {
            $dbTx->status = AdsPayment::STATUS_RESERVED;
            $dbTx->save();
        }
    }

    private function handleIfEventPayment(AdsPayment $dbTx): bool
    {
        $senderAddress = $dbTx->address;
        $networkHost = NetworkHost::fetchByAddress($senderAddress);

        if ($networkHost === null) {
            return false;
        }

        $host = $networkHost->host;
        $txid = $dbTx->txid;
        $adsPaymentId = $dbTx->id;

        try {
            $paymentDetails = $this->demandClient->fetchPaymentDetails($host, $txid);
        } catch (EmptyInventoryException $exception) {
            return false;
        } catch (UnexpectedClientResponseException $exception) {
            if ($exception->getCode() === Response::HTTP_NOT_FOUND) {
                return false;
            }

            return true;
        }

        try {
            $this->paymentDetailsProcessor->processPaymentDetails($senderAddress, $adsPaymentId, $paymentDetails);
        } catch (MissingInitialConfigurationException $exception) {
            $this->error('Missing initial configuration: '.$exception->getMessage());

            return true;
        } catch (Exception $exception) {
            $this->error('Unexpected error: '.$exception->getMessage());

            return true;
        }

        $dbTx->status = AdsPayment::STATUS_EVENT_PAYMENT;
        $dbTx->save();

        return true;
    }

    private function handleSendOneTx(AdsPayment $dbTx, SendOneTransaction $transaction): void
    {
        $targetAddr = $transaction->getTargetAddress();

        if ($targetAddr === $this->adServerAddress) {
            $message = $transaction->getMessage();
            $user = User::fetchByUuid($this->extractUuidFromMessage($message));

            if (null === $user) {
                $this->handleReservedTx($dbTx);
            } else {
                $senderAddress = $transaction->getSenderAddress();
                $amount = $transaction->getAmount();
                // add to ledger
                $ul = new UserLedgerEntry();
                $ul->user_id = $user->id;
                $ul->amount = $amount;
                $ul->address_from = $senderAddress;
                $ul->address_to = $targetAddr;
                $ul->txid = $dbTx->txid;
                $ul->type = UserLedgerEntry::TYPE_DEPOSIT;

                $dbTx->status = AdsPayment::STATUS_USER_DEPOSIT;
                // dbTx added to ledger will not be processed again
                DB::transaction(
                    function () use ($ul, $dbTx) {
                        $ul->save();
                        $dbTx->save();
                    }
                );
            }
        } else {
            $dbTx->status = AdsPayment::STATUS_INVALID;
            $dbTx->save();
        }
    }

    private function extractUuidFromMessage(string $message): string
    {
        return hex2bin(substr($message, -32));
    }
}
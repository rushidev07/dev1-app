<?php

namespace Ahy\Authorizenet\Service;

use GuzzleHttp\Client;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Ahy\Authorizenet\Model\CustomerProfileRepository;
use Magento\Framework\App\State;
use Ahy\Authorizenet\Logger\SavedCCFrontendLogger;
use Ahy\Authorizenet\Logger\SavedCCAdminLogger;

class AuthorizeNetCimService
{
    protected Client $client;
    protected Json $json;
    protected LoggerInterface $logger;
    protected AuthorizeNetApi $authorizeNetApi;
    protected CustomerProfileRepository $profileRepository;
    protected State $appState;
    protected SavedCCFrontendLogger $savedCCFrontendLogger;
    protected SavedCCAdminLogger $savedCCAdminLogger;

    public function __construct(
        Client $client,
        Json $json,
        LoggerInterface $logger,
        AuthorizeNetApi $authorizeNetApi,
        CustomerProfileRepository $profileRepository,
        State $appState,
        SavedCCFrontendLogger $savedCCFrontendLogger,
        SavedCCAdminLogger $savedCCAdminLogger
    ) {
        $this->client            = $client;
        $this->json              = $json;
        $this->logger            = $logger;
        $this->authorizeNetApi   = $authorizeNetApi;
        $this->profileRepository = $profileRepository;
        $this->appState = $appState;
        $this->savedCCFrontendLogger = $savedCCFrontendLogger;
        $this->savedCCAdminLogger = $savedCCAdminLogger;
    }

    /* =========================================================================
       ==============   CREATE PAYMENT PROFILE (main entry)   ==================
       ========================================================================= */

    /**
     * Creates a new Authorize.Net payment profile.
     * If customerProfileId is missing, creates customer profile first.
     *
     * @param array $data
     * @return array
     */

    protected function logSavedCC(string $message, array $context = []): void
    {
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (\Exception $e) {
            $areaCode = 'unknown';
        }

        switch ($areaCode) {
            case \Magento\Framework\App\Area::AREA_ADMINHTML:
                $this->savedCCAdminLogger->info($message, $context);
                break;

            case \Magento\Framework\App\Area::AREA_FRONTEND:
                $this->savedCCFrontendLogger->info($message, $context);
                break;

            default:
                $this->savedCCFrontendLogger->info('[CHECKOUT] ' . $message, $context);
                break;
        }
    }

    public function createCustomerPaymentProfile(array $data): array
    {
        $customerProfileId = $this->getCustomerProfileIdByCustomerId((int)$data['customer_id']);

        // ---------------- CASE 2: No profile found ----------------
        if (!$customerProfileId) {
            $this->logger->info('[CIM] No customerProfileId found – creating profile first.');
            $this->logSavedCC('[CIM] No customerProfileId found – creating profile first.');

            $createProfileResp = $this->authorizeNetApi->createCustomerProfile(array_merge(
                [
                    'merchant_customer_id' => (string)$data['customer_id'],
                    'email'                => $data['email'] ?? '',
                    'validation_mode'      => $data['validation_mode'] ?? 'testMode',
                ],
                $data
            ));

            if (!$createProfileResp['success']) {
                $this->logSavedCC('[SavedCC] Failed to create customer profile', ['error' => $createProfileResp['message']]);
                return ['success' => false, 'message' => $createProfileResp['message']];
            }

            $customerProfileId = $createProfileResp['customer_profile_id'];
            $paymentProfileId  = $createProfileResp['payment_profile_id'];

            try {
                $this->profileRepository->saveProfileMapping((int)$data['customer_id'], $customerProfileId);
                $this->logger->info("[CIM] Saved new customerProfileId {$customerProfileId} for customer {$data['customer_id']}");
                $this->logSavedCC('[SavedCC] Saved new customerProfileId', [
                    'customer_id' => $data['customer_id'],
                    'profile_id' => $customerProfileId
                ]);
            } catch (\Exception $e) {
                $this->logger->error('[CIM] Failed to save profile mapping: ' . $e->getMessage());
                $this->logSavedCC('[SavedCC] Failed to save profile mapping', ['exception' => $e->getMessage()]);
                // Continue; do not fail card save
            }

            return [
                'success'             => true,
                'payment_profile_id'  => $paymentProfileId,
                'customer_profile_id' => $customerProfileId,
            ];
        }

        // ---------------- CASE 1: Profile exists ----------------

        $apiCredentials = $this->authorizeNetApi->getApiCredentials();
        $apiEndpoint    = $this->authorizeNetApi->getApiEndpoint();

        $payload = [
            'createCustomerPaymentProfileRequest' => [
                'merchantAuthentication' => [
                    'name'           => $apiCredentials['apiKey'],
                    'transactionKey' => $apiCredentials['transactionKey'],
                ],
                'customerProfileId' => $customerProfileId,
                'paymentProfile'    => [
                    'billTo' => [
                        'firstName'   => $data['billing_first_name'] ?? '',
                        'lastName'    => $data['billing_last_name']  ?? '',
                        'address'     => $data['billing_street']     ?? '',
                        'city'        => $data['billing_city']       ?? '',
                        'state'       => $data['billing_state']      ?? '',
                        'zip'         => $data['billing_zip']        ?? '',
                        'country'     => $data['billing_country']    ?? '',
                        'phoneNumber' => $data['billing_phone']      ?? '',
                    ],
                    'payment' => [
                        'creditCard' => [
                            'cardNumber'     => $data['card_number'],
                            'expirationDate' => $data['expiration_date'],
                            'cardCode'       => $data['cvv'],
                        ],
                    ],
                    'defaultPaymentProfile' => false,
                ],
                'validationMode' => $data['validation_mode'] ?? 'testMode',
            ],
        ];

        $jsonPayload = $this->json->serialize($payload);
        $maskedLog   = $this->json->serialize($this->maskCardData($payload));
        $this->logger->info('[CIM] Payload for createCustomerPaymentProfileRequest: ' . $maskedLog);
        $this->logSavedCC('[CIM] Payload for createCustomerPaymentProfileRequest: ' . $maskedLog);

        try {
            $response = $this->client->post($apiEndpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $jsonPayload,
            ]);

            $decoded = $this->json->unserialize(
                trim($response->getBody()->getContents(), "\xEF\xBB\xBF\x00..\x1F")
            );

            if (
                !isset($decoded['messages']['resultCode']) ||
                $decoded['messages']['resultCode'] !== 'Ok'
            ) {
                $messages = $decoded['messages']['message'] ?? [];
                foreach ($messages as $error) {
                    $code = $error['code'] ?? '';
                    $text = $error['text'] ?? 'Unknown error';

                    $this->logger->error("[CIM] Error [$code]: $text");
                    $this->logSavedCC('[CIM] Error response', ['code' => $code, 'text' => $text]);

                    if ($code === 'E00042' || stripos($text, 'maximum number') !== false) {
                        return [
                            'success' => false,
                            'message' => __('You have reached the maximum number of saved cards (10). Please delete an existing card to add a new one.')
                        ];
                    }
                }

                $defaultMsg = $messages[0]['text'] ?? 'Unknown error occurred.';
                return ['success' => false, 'message' => $defaultMsg];
            }

            $paymentProfileId = $decoded['customerPaymentProfileId'];
            $this->logger->info('[CIM] createCustomerPaymentProfile success ID: ' . $paymentProfileId);
            $this->logSavedCC('[SavedCC] Created payment profile successfully', [
                'payment_profile_id' => $paymentProfileId,
                'customer_id' => $data['customer_id']
            ]);
            if (!empty($decoded['validationDirectResponse'])) {
                $this->logger->info('[CIM] Create validationDirectResponse: ' . $decoded['validationDirectResponse']);
                $this->logSavedCC('[CIM] Create validationDirectResponse: ' . $decoded['validationDirectResponse']);
            }
            return [
                'success'            => true,
                'payment_profile_id' => $paymentProfileId,
            ];
        } catch (\Exception $e) {
            $this->logger->error('[CIM] Error in createCustomerPaymentProfile: ' . $e->getMessage());
            $this->logSavedCC('[CIM] Error in createCustomerPaymentProfile: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /* =========================================================================
       ===================  UPDATE PAYMENT PROFILE ============================
       ========================================================================= */

    public function updateCustomerPaymentProfile(array $data): void
    {
        $apiCredentials = $this->authorizeNetApi->getApiCredentials();
        $apiEndpoint    = $this->authorizeNetApi->getApiEndpoint();

        $payload = [
            'updateCustomerPaymentProfileRequest' => [
                'merchantAuthentication' => [
                    'name'           => $apiCredentials['apiKey'],
                    'transactionKey' => $apiCredentials['transactionKey'],
                ],
                'customerProfileId' => $data['customerProfileId'],
                'paymentProfile'    => [
                    'billTo' => [
                        'firstName'   => $data['billing_first_name'] ?? '',
                        'lastName'    => $data['billing_last_name'] ?? '',
                        'company'     => '',
                        'address'     => $data['billing_street'] ?? '',
                        'city'        => $data['billing_city'] ?? '',
                        'state'       => $data['billing_state'] ?? '',
                        'zip'         => $data['billing_zip'] ?? '',
                        'country'     => $data['billing_country'] ?? '',
                        'phoneNumber' => $data['billing_phone'] ?? '',
                        'faxNumber'   => '',
                    ],
                    'payment' => [
                        'creditCard' => [
                            'cardNumber'     => $data['cardNumber'] ?? '',
                            'expirationDate' => $data['expirationDate'] ?? '',
                            'cardCode'       => $data['cvv'] ?? '',
                        ],
                    ],
                    'defaultPaymentProfile' => false,
                    'customerPaymentProfileId' => $data['paymentProfileId'],
                ],
                'validationMode' => $data['validation_mode'] ?? 'testMode',
            ],
        ];

        $jsonPayload = $this->json->serialize($payload);
        $maskedLog   = $this->json->serialize($this->maskCardData($payload));
        $this->logger->info('[AuthorizeNetCIM] Payload for updateCustomerPaymentProfileRequest: ' . $maskedLog);
        $this->logSavedCC('[AuthorizeNetCIM] Payload for updateCustomerPaymentProfileRequest: ' . $maskedLog);

        try {
            $response = $this->client->post($apiEndpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $jsonPayload,
            ]);

            $body = trim($response->getBody()->getContents(), "\xEF\xBB\xBF\x00..\x1F");
            $decoded = $this->json->unserialize($body);

            if (
                !isset($decoded['messages']['resultCode']) ||
                $decoded['messages']['resultCode'] !== 'Ok'
            ) {
                $message = $decoded['messages']['message'][0]['text'] ?? 'Unknown error.';
                throw new \Exception('Authorize.Net update failed: ' . $message);
            }

            $this->logger->info('[AuthorizeNetCIM] Update success response: ', $decoded);
            $this->logSavedCC('[AuthorizeNetCIM] Update success response: ', $decoded);
        } catch (\Exception $e) {
            $this->logger->error('[CIM] API error during updateCustomerPaymentProfile: ' . $e->getMessage());
            $this->logSavedCC('[CIM] API error during updateCustomerPaymentProfile: ' . $e->getMessage());
            throw $e;
        }
    }

    /* =========================================================================
       ===================  DELETE PAYMENT PROFILE ============================
       ========================================================================= */

    public function deleteCustomerPaymentProfile(string $customerProfileId, string $paymentProfileId): void
    {
        $apiCredentials = $this->authorizeNetApi->getApiCredentials();
        $apiEndpoint    = $this->authorizeNetApi->getApiEndpoint();

        $payload = [
            'deleteCustomerPaymentProfileRequest' => [
                'merchantAuthentication' => [
                    'name'           => $apiCredentials['apiKey'],
                    'transactionKey' => $apiCredentials['transactionKey'],
                ],
                'customerProfileId'        => $customerProfileId,
                'customerPaymentProfileId' => $paymentProfileId,
            ],
        ];

        $jsonPayload = $this->json->serialize($payload);
        $this->logger->info('[AuthorizeNetCIM] Payload for deleteCustomerPaymentProfileRequest: ' . $jsonPayload);
        $this->logSavedCC('[AuthorizeNetCIM] Payload for deleteCustomerPaymentProfileRequest: ' . $jsonPayload);

        try {
            $response = $this->client->post($apiEndpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $jsonPayload,
            ]);

            $body = trim($response->getBody()->getContents(), "\xEF\xBB\xBF\x00..\x1F");
            $decoded = $this->json->unserialize($body);

            if ($decoded['messages']['resultCode'] !== 'Ok') {
                $msg = $decoded['messages']['message'][0]['text'] ?? 'Unknown error.';
                $this->logger->error('[AuthorizeNetCIM] Full error response: ' . print_r($decoded, true));
                $this->logSavedCC('[AuthorizeNetCIM] Full error response: ' . print_r($decoded, true));
                throw new \Exception('Authorize.Net delete failed: ' . $msg);
            }

            $this->logger->info("[AuthorizeNetCIM] Deleted payment profile ID: {$paymentProfileId}");
            $this->logSavedCC("[AuthorizeNetCIM] Deleted payment profile ID: {$paymentProfileId}");
        } catch (\Exception $e) {
            $this->logger->error('[CIM] Error deleting card: ' . $e->getMessage());
            $this->logSavedCC('[CIM] Error deleting card: ' . $e->getMessage());
            throw $e;
        }
    }

    /* =========================================================================
       ===================  HELPER FUNCTIONS  =================================
       ========================================================================= */

    private function maskCardData(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->maskCardData($value);
            } elseif ($key === 'cardNumber' && is_string($value)) {
                $value = str_repeat('X', max(0, strlen($value) - 4)) . substr($value, -4);
            } elseif ($key === 'cardCode' && is_string($value)) {
                $value = '***';
            }
        }
        return $data;
    }

    public function getCustomerProfileIdByCustomerId(int $customerId): ?string
    {
        try {
            return $this->profileRepository->getProfileIdByCustomerId($customerId);
        } catch (\Exception $e) {
            $this->logger->error('[AuthorizeNetCIM] Failed to get customerProfileId: ' . $e->getMessage());
            $this->logSavedCC('[AuthorizeNetCIM] Failed to get customerProfileId: ' . $e->getMessage());
            return null;
        }
    }
}

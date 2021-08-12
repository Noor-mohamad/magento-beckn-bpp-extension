<?php

namespace Beckn\Bpp\Model;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class DigitalSignature
 * @author Indglobal
 * @package Beckn\Bpp\Model
 */
class DigitalSignature
{
    const SIGN_PUBLIC_KEY_PATH = 'bpp_config/subscriber/signing_public_key';
    const SIGN_PRIVATE_KEY_PATH = 'bpp_config/subscriber/signing_private_key';
    const ENCRYPTION_PUBLIC_KEY_PATH = 'bpp_config/subscriber/encryption_public_key';
    const ENCRYPTION_PRIVATE_KEY_PATH = 'bpp_config/subscriber/encryption_private_key';
    /**
     * @var \Magento\Framework\App\Config\ConfigResource\ConfigInterface
     */
    protected $_configInterface;
    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;
    /**
     * @var Pool
     */
    protected $_cacheFrontendPool;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * DigitalSignature constructor.
     * @param \Magento\Framework\App\Config\ConfigResource\ConfigInterface $configInterface
     */
    public function __construct(
        \Magento\Framework\App\Config\ConfigResource\ConfigInterface $configInterface,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->_configInterface = $configInterface;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_cacheFrontendPool = $cacheFrontendPool;
        $this->_scopeConfig = $scopeConfig;
    }

    /**
     * @param string $path
     * @param string $scope
     * @param int $storeId
     * @return mixed
     */
    public function getConfigData($path, $scope = ScopeInterface::SCOPE_STORE, $storeId = 0)
    {
        return $this->_scopeConfig->getValue($path, $scope, $storeId);
    }

    /**
     * @param string $type
     * @return bool
     * @throws \SodiumException
     */
    public function generateKeyPair($type)
    {
        try {
            switch ($type) {
                case "signing":
                    $this->generateSigningkeys();
                    break;
                case "encryption":
                    $this->generateEncryptionKeys();
                    break;
            }
            return true;
        } catch (\SodiumException $ex) {
            return $ex->getMessage();
        }
    }

    /**
     * @return bool|string
     */
    private function generateSigningKeys()
    {
        try {
            $cryptoSignkeyPair = $this->crypto_sign_keypair();
            $alice_sign_secretkey = \Sodium_crypto_sign_secretkey($cryptoSignkeyPair);
            $alice_sign_publickey = \Sodium_crypto_sign_publickey($cryptoSignkeyPair);
            $secretKey = sodium_bin2base64($alice_sign_secretkey, SODIUM_BASE64_VARIANT_ORIGINAL);
            $publicKey = sodium_bin2base64($alice_sign_publickey, SODIUM_BASE64_VARIANT_ORIGINAL);
            $this->saveConfigData(self::SIGN_PUBLIC_KEY_PATH, $publicKey);
            $this->saveConfigData(self::SIGN_PRIVATE_KEY_PATH, $secretKey);
            $this->flushCache();
        } catch (\SodiumException $ex) {
            return $ex->getMessage();
        }
        return true;
    }

    /**
     * @return bool|string
     */
    private function generateEncryptionKeys()
    {
        try {
            $cryptoBoxKeyPair = \Sodium_crypto_box_keypair();
            $aliceSecretkey = \Sodium_crypto_box_secretkey($cryptoBoxKeyPair);
            $alicePublickey = \Sodium_crypto_box_publickey($cryptoBoxKeyPair);
            $secretKey = sodium_bin2base64($aliceSecretkey, SODIUM_BASE64_VARIANT_ORIGINAL);
            $publicKey = sodium_bin2base64($alicePublickey, SODIUM_BASE64_VARIANT_ORIGINAL);
            $this->saveConfigData(self::ENCRYPTION_PUBLIC_KEY_PATH, $publicKey);
            $this->saveConfigData(self::ENCRYPTION_PRIVATE_KEY_PATH, $secretKey);
            $this->flushCache();
        } catch (\SodiumException $ex) {
            return $ex->getMessage();
        }
        return true;
    }

    /**
     * @return mixed
     */
    private function getPrivateKey()
    {
        return $this->getConfigData(self::SIGN_PRIVATE_KEY_PATH);
    }

    /**
     * @return mixed
     */
    private function getPublicKey()
    {
        return $this->getConfigData(self::SIGN_PUBLIC_KEY_PATH);
    }

    /**
     * @return mixed
     */
    public function getSubscriberId()
    {
        $path = 'bpp_config/subscriber/subscriber_id';
        return $this->getConfigData($path);
    }

    /**
     * @return string
     * @throws \SodiumException
     */
    public function crypto_sign_keypair(): string
    {
        $alice_sign_kp = \Sodium_crypto_sign_keypair();
        return $alice_sign_kp;
    }


    /**
     * @param $path
     * @param $value
     * @return mixed
     */
    public function saveConfigData($path, $value)
    {
        return $this->_configInterface->saveConfig($path, $value, $scope = \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0);
    }

    /**
     * @param string $body
     * @return array
     */
    public function createAuthorization(string $body): array
    {
        $authResponse = [
            "success" => false,
            "message" => "",
            "auth" => "",
        ];
        try {
            $digestSize = 64; // Blake hash 512
            $digest = \Sodium_crypto_generichash($body, "", $digestSize);
            $digest = bin2hex($digest);
            $blake512 = base64_encode(pack('H*', $digest));
            $created = time();
            $expires = time() + 36000;

            $signing_string = "(created): " . $created . "\n(expires): " . $expires . "\ndigest: BLAKE-512=" . $blake512;
            $privateKey = \Sodium_base642bin($this->getPrivateKey(), SODIUM_BASE64_VARIANT_ORIGINAL);
            $publicKey = \Sodium_base642bin($this->getPublicKey(), SODIUM_BASE64_VARIANT_ORIGINAL);
            $signature = \Sodium_crypto_sign_detached($signing_string, $privateKey);
            $subscriberId = $this->getSubscriberId();
            $headers = "(created) (expires) digest";
            $signature = \Sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
            $authorizationHeader = 'Signature keyId="' . $subscriberId . '|key1|xed25519" algorithm="xed25519" created="' . $created . '" expires="' . $expires . '" headers="' . $headers . '" signature="' . $signature . '"';
            $authResponse["auth"] = $authorizationHeader;
            $authResponse["success"] = true;
        } catch (\SodiumException $e) {
            $authResponse["success"] = false;
            $authResponse["message"] = __($e->getMessage());
        }
        return $authResponse;
    }


    /**
     * @return array
     * @throws \SodiumException
     */
    private function getEncryptionSecretkeyAndPublickey()
    {
        $configSecretKey = $this->getConfigData(self::ENCRYPTION_PRIVATE_KEY_PATH);
        $configPublicKey = $this->getConfigData(self::ENCRYPTION_PUBLIC_KEY_PATH);
        $secretKey = \Sodium_base642bin($configSecretKey, SODIUM_BASE64_VARIANT_ORIGINAL);
        $publicKey = \Sodium_base642bin($configPublicKey, SODIUM_BASE64_VARIANT_ORIGINAL);
        return [
            "secret_key" => $secretKey,
            "public_key" => $publicKey
        ];
    }

    /**
     * @return string
     * @throws \SodiumException
     */
    public function getSodiumCryptoBoxKeypairFromSecretkeyAndPublickey()
    {
        try {
            $keys = $this->getEncryptionSecretkeyAndPublickey();
            return \Sodium_crypto_box_keypair_from_secretkey_and_publickey($keys["secret_key"], $keys["public_key"]);
        } catch (\SodiumException $ex) {
            throw new \SodiumException('Unable to generate key pair. ' . $ex->getMessage());
        }
    }

    /**
     * Flush Cache
     */
    public function flushCache()
    {
        $types = array('config', 'layout', 'block_html', 'collections', 'reflection', 'db_ddl', 'eav', 'config_integration', 'config_integration_api', 'full_page', 'translate', 'config_webservice');

        foreach ($types as $type) {
            $this->_cacheTypeList->cleanType($type);
        }
        foreach ($this->_cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }
}

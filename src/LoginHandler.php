<?php

namespace SilverStripe\WebAuthn;

use CBOR\Decoder;
use CBOR\OtherObject\OtherObjectManager;
use CBOR\Tag\TagObjectManager;
use GuzzleHttp\Psr7\ServerRequest;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\MFA\Method\Handler\LoginHandlerInterface;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\MFA\Store\StoreInterface;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TokenBinding\TokenBindingNotSupportedHandler;

class LoginHandler implements LoginHandlerInterface
{
    /**
     * Stores any data required to handle a login process with a method, and returns relevant state to be applied to the
     * front-end application managing the process.
     *
     * @param StoreInterface $store An object that hold session data (and the Member) that can be mutated
     * @param RegisteredMethod $method The RegisteredMethod instance that is being verified
     * @return array Props to be passed to a front-end component
     */
    public function start(StoreInterface $store, RegisteredMethod $method)
    {
        return [
            'publicKey' => $this->getCredentialRequestOptions($store, $method),
        ];
    }

    /**
     * Verify the request has provided the right information to verify the member that aligns with any sessions state
     * that may have been set prior
     *
     * @param HTTPRequest $request
     * @param StoreInterface $store
     * @param RegisteredMethod $registeredMethod The RegisteredMethod instance that is being verified
     * @return bool
     */
    public function verify(HTTPRequest $request, StoreInterface $store, RegisteredMethod $registeredMethod)
    {
        $options = $this->getCredentialRequestOptions($store, $registeredMethod);
        $data = json_decode($request->getBody(), true);

        // CBOR
        $decoder = new Decoder(new TagObjectManager(), new OtherObjectManager());

        // Attestation statement support manager
        $attestationStatementSupportManager = new AttestationStatementSupportManager();
        $attestationStatementSupportManager->add(new NoneAttestationStatementSupport());
        $attestationStatementSupportManager->add(new FidoU2FAttestationStatementSupport($decoder));

        // Attestation object loader
        $attestationObjectLoader = new AttestationObjectLoader($attestationStatementSupportManager, $decoder);

        $publicKeyCredentailLoader = new PublicKeyCredentialLoader($attestationObjectLoader, $decoder);

        $credentialRepository = new CredentialRepository($store->getMember(), $registeredMethod);

        $authenticatorAssertionResponseValidator = new AuthenticatorAssertionResponseValidator(
            $credentialRepository,
            $decoder,
            new TokenBindingNotSupportedHandler(),
            new ExtensionOutputCheckerHandler()
        );

        // Create a PSR-7 request
        $request = ServerRequest::fromGlobals();

        try {
            $publicKeyCredential = $publicKeyCredentailLoader->load(base64_decode($data['credentials']));
            $response = $publicKeyCredential->getResponse();

            if (!$response instanceof AuthenticatorAssertionResponse) {
                throw new \Exception('why even have this?');
            }

            $authenticatorAssertionResponseValidator->check(
                $publicKeyCredential->getRawId(),
                $publicKeyCredential->getResponse(),
                $options,
                $request,
                $store->getMember()->ID
            );

            return true;
        } catch (\Exception $e) {
            throw $e;
        }

        return false;
    }

    /**
     * Provide a localised string that serves as a lead in for choosing this option for authentication
     *
     * eg. "Enter one of your recovery codes"
     *
     * @return string
     */
    public function getLeadInLabel()
    {
        return _t(__CLASS__ . '.LEAD_IN', 'Login using a security key device');
    }

    /**
     * Get the key that a React UI component is registered under (with @silverstripe/react-injector on the front-end)
     *
     * @return string
     */
    public function getComponent()
    {
        return 'WebAuthnLogin';
    }

    protected function getCredentialRequestOptions(StoreInterface $store, RegisteredMethod $registeredMethod)
    {
        $state = $store->getState();

        if (empty($state) || empty($state['challenge'])) {
            $challenge = random_bytes(32);
            $store->setState(['challenge' => $challenge]);
        } else {
            $challenge = $state['challenge'];
        }

        $data = json_decode($registeredMethod->Data, true);
        $descriptor = PublicKeyCredentialDescriptor::createFromJson($data['descriptor']);

        return new PublicKeyCredentialRequestOptions(
            $challenge,
            40000,
            null,
            [$descriptor],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED
        );
    }
}

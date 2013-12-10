<?php

namespace AerialShip\SamlSPBundle\Bridge;

use AerialShip\LightSaml\Binding\HttpPost;
use AerialShip\LightSaml\Binding\HttpRedirect;
use AerialShip\LightSaml\Meta\LogoutRequestBuilder;
use AerialShip\LightSaml\Meta\SerializationContext;
use AerialShip\SamlSPBundle\Config\ServiceInfoCollection;
use AerialShip\SamlSPBundle\Config\SpEntityDescriptorBuilder;
use AerialShip\SamlSPBundle\Config\SPSigningProviderFile;
use AerialShip\SamlSPBundle\Config\SPSigningProviderInterface;
use AerialShip\SamlSPBundle\RelyingParty\RelyingPartyInterface;
use AerialShip\SamlSPBundle\Security\Core\Token\SamlSpToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\SecurityContextInterface;


class Logout implements RelyingPartyInterface
{
    /** @var \Symfony\Component\Security\Core\SecurityContextInterface  */
    protected $securityContext;

    /** @var  ServiceInfoCollection */
    protected $serviceInfoCollection;



    /**
     * @param SecurityContextInterface $securityContext
     * @param ServiceInfoCollection $serviceInfoCollection
     */
    public function __construct(
        SecurityContextInterface $securityContext,
        ServiceInfoCollection $serviceInfoCollection
    ) {
        $this->securityContext = $securityContext;
        $this->serviceInfoCollection = $serviceInfoCollection;
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return bool
     */
    function supports(Request $request) {
        if ($request->attributes->get('logout_path') != $request->getPathInfo()) {
            return false;
        }
        /** @var $token SamlSpToken */
        $token = $this->securityContext->getToken();
        if (!$token || !$token instanceof SamlSpToken) {
            return false;
        }
        if (!$token->getSamlSpInfo()) {
            return false;
        }
        return true;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @throws \RuntimeException  if no signing provider set
     * @throws \InvalidArgumentException if cannot manage the Request
     * @return \Symfony\Component\HttpFoundation\Response|SamlSpInfo
     */
    function manage(Request $request) {
        if (!$this->supports($request)) {
            throw new \InvalidArgumentException('Unsupported request');
        }

        /** @var $token SamlSpToken */
        $token = $this->securityContext->getToken();
        $samlInfo = $token->getSamlSpInfo();

        $serviceInfo = $this->serviceInfoCollection->get($samlInfo->getAuthenticationServiceID());
        if (!$serviceInfo) {
            throw new \RuntimeException("redirect to discovery");
        }
        if (!$serviceInfo->getSpSigningProvider()->isEnabled()) {
            throw new \RuntimeException('Signing is required for Logout');
        }
        $serviceInfo->getSpProvider()->setRequest($request);


//        $ed = $this->sp->getEntityDescriptor();
//        $ed->getSpSsoDescriptors()[0]->findSingleLogoutServices()[0]->setLocation('https://192.168.100.10/aerial/test/web/app_dev.php/saml/logout');

        $builder = new LogoutRequestBuilder(
            $serviceInfo->getSpProvider()->getEntityDescriptor(),
            $serviceInfo->getIdpProvider()->getEntityDescriptor(),
            $serviceInfo->getSpMetaProvider()->getSpMeta()
        );

        $logoutRequest = $builder->build(
            $samlInfo->getNameID()->getValue(),
            $samlInfo->getNameID()->getFormat(),
            $samlInfo->getAuthnStatement()->getSessionIndex()
        );
        $logoutRequest->sign($serviceInfo->getSpSigningProvider()->getCertificate(), $serviceInfo->getSpSigningProvider()->getPrivateKey());

        $bindingResponse = $builder->send($logoutRequest);
        if ($bindingResponse instanceof \AerialShip\LightSaml\Binding\PostResponse) {
            return new Response($bindingResponse->render());
        } else if ($bindingResponse instanceof \AerialShip\LightSaml\Binding\RedirectResponse) {
            return new RedirectResponse($bindingResponse->getUrl());
        }

        throw new \RuntimeException('Unknown binding response '.get_class($bindingResponse));

//        $context = new SerializationContext();
//        $context->getDocument()->formatOutput = true;
//        $logoutRequest->getXml($context->getDocument(), $context);
//        return new Response($context->getDocument()->saveXML(), 200, array('Content-Type'=> 'text/xml'));


        $binding = new HttpPost();
        $bindingResponse = $binding->send($logoutRequest);
        return new Response($bindingResponse->render());

//        $binding = new HttpRedirect();
//        $bindingResponse = $binding->send($logoutRequest);
//        //return new Response($bindingResponse->getUrl());
//        return new RedirectResponse($bindingResponse->getUrl());
    }

} 
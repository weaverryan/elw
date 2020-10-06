<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use OAT\Bundle\Lti1p3Bundle\Security\Authentication\Token\Message\LtiMessageToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Security;

class DefaultController extends AbstractController
{
    /** @var Security */
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    /**
     * @Route("/home", name="default")
     */
    public function index()
    {
        return $this->render('default/index.html.twig', [
            'controller_name' => 'DefaultController',
        ]);
    }

    /**
     * @Route("/lti_test", name="lti_test")
     */
    public function testing()
    {
        /** @var LtiMessageToken $token */
        $token = $this->security->getToken();

        // Related registration
        $registration = $token->getRegistration();

        // Related LTI message
        $ltiMessage = $token->getLtiMessage();
        $userIdentity = $ltiMessage->getUserIdentity();
        $email_key = 'email';
        $email = $userIdentity[$email_key];
        $firstname_key = 'givenName';
        $firstname = $userIdentity[$firstname_key];
        $lastname_key = 'familyName';
        $lastname = $userIdentity[$lastname_key];
        $username_claim = $ltiMessage->getClaim("http://www.brightspace.com");
        $username_key='username';
        $username = $username_claim[$username_key];
//        $user = $this->getDoctrine()->getManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        // You can even access validation results
        $validationResults = $token->getValidationResult();

//        dd($username);

        return $this->render('default/index.html.twig', [
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'username' => $username,
        ]);
//        return $this->redirectToRoute('course_index');
    }


}
<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CourseRepository;
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
    public function testing(CourseRepository $courseRepository)
    {
        /** @var LtiMessageToken $token */
        $token = $this->security->getToken();

        // Related registration
        $registration = $token->getRegistration();

        // Related LTI message
        $ltiMessage = $token->getLtiMessage();
        $userIdentity = $ltiMessage->getUserIdentity();
        $email = $userIdentity['email'];
        $firstname = $userIdentity['givenName'];
        $laststname = $userIdentity['familyName'];
        $username_claim = $ltiMessage->getClaim("http://www.brightspace.com");
        $username = $username_claim['username'];
        $user = $this->getDoctrine()->getManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        
        // You can even access validation results
        $validationResults = $token->getValidationResult();

        return $this->render('course/index.html.twig', [
            'courses' => $courseRepository->findByUser($user),
        ]);

    }


}
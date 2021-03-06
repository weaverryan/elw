<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Classlist;
use App\Entity\Course;
use App\Entity\User;
use App\Form\LtiAgsLineitemType;
use App\Form\LtiAgsLineType;
use App\Repository\CourseRepository;
use App\Security\LtiAuthenticator;
use Carbon\Carbon;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer;
use OAT\Library\Lti1p3Core\Exception\LtiException;
use OAT\Library\Lti1p3Core\Exception\LtiExceptionInterface;
use OAT\Library\Lti1p3Core\Message\Payload\MessagePayloadInterface;
use OAT\Library\Lti1p3Core\Registration\RegistrationInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OAT\Bundle\Lti1p3Bundle\Security\Authentication\Token\Message\LtiToolMessageSecurityToken;
use OAT\Library\Lti1p3Nrps\Service\Client\MembershipServiceClient;
use OAT\Library\Lti1p3Core\Registration\RegistrationRepositoryInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use OAT\Library\Lti1p3Core\Service\Client\ServiceClientInterface;
use GuzzleHttp\ClientInterface;
use Throwable;
use RuntimeException;

class LtiController extends AbstractController
{
    /** @var Security */
    private $security;

    /** @var RegistrationRepositoryInterface */
    private $repository;

    /** @var ClientInterface */
    private $guzzle;

    /** @var Builder */
    private $builder;

    /** @var Signer */
    private $signer;


    public function __construct(
        Security $security,
        RegistrationRepositoryInterface $repository,
        ClientInterface $guzzle,
        Builder $builder,
        Signer $signer
    )
    {
        $this->security = $security;
        $this->repository = $repository;
        $this->guzzle = $guzzle;
        $this->builder = $builder;
        $this->signer = $signer;
    }


    /**
     * @Route("/lti_test", name="lti_test")
     */
    public function lti_launch(CourseRepository $courseRepository, GuardAuthenticatorHandler $guardAuthenticatorHandler, LtiAuthenticator $ltiAuthenticator, Request $request)
    {
        /** @var LtiToolMessageSecurityToken $token */
        $token = $this->security->getToken();
        if (!$token instanceof LtiToolMessageSecurityToken) {
            return $this->redirectToRoute('course_index');
        }

        // Related registration
        $registration = $token->getRegistration();
        // You can even access validation results
        $validationResults = $token->getValidationResult();

        // Related LTI message
        //all the payload from ELC; payload depend on how Deployment is created on platform;
        // be sure to include all user and course info in Security Settings
        $ltiMessage = $token->getPayload();

        $userIdentity = $ltiMessage->getUserIdentity();
        $firstname = $userIdentity->getGivenName();
        $lastname = $userIdentity->getFamilyName();
        $roles = $ltiMessage->getClaim("https://purl.imsglobal.org/spec/lti/claim/roles");

        $username_claim = $ltiMessage->getClaim("http://www.brightspace.com");
        $username_key = 'username';
        $username = $username_claim[$username_key];

        $user = $this->getDoctrine()->getManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user) {
            $user = new User();
            $user->setUsername($username);
            //Set User Role
            //Check if Instructor
            if (in_array("http://purl.imsglobal.org/vocab/lis/v2/institution/person#Instructor", $roles)) {
                $user->setRoles(["ROLE_INSTRUCTOR"]);
            } else {
                $user->setRoles(["ROLE_USER"]);
            }
            $user->setLastname($lastname);
            $user->setFirstname($firstname);
            $this->getDoctrine()->getManager()->persist($user);
            $this->getDoctrine()->getManager()->flush();
        }


        $context = $ltiMessage->getClaim("https://purl.imsglobal.org/spec/lti/claim/context");
        $context_key_id = 'id';
        $context_key_name = 'title';
        $lti_id = $context[$context_key_id];
        $course_name = $context[$context_key_name];
        $course = $courseRepository->findOneByLtiId($lti_id);

        //Check for Course
        if (!$course) {
            //Check if Instructor
            if (in_array("http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor", $roles)) {
                $labelsets = $this->getDoctrine()->getManager()->getRepository('App:Labelset')->findDefault();
                $markupsets = $this->getDoctrine()->getManager()->getRepository('App:Markupset')->findDefault();
                $course = new Course();
                $course->setName($course_name);
                $course->setLtiId($lti_id);
                foreach ($labelsets as $labelset) {
                    $course->addLabelset($labelset);
                }
                foreach ($markupsets as $markupset) {
                    $course->addMarkupset($markupset);
                }
                $classlist = new Classlist();
                $classlist->setUser($user);
                $classlist->setCourse($course);
                $classlist->setRole('Instructor');
                $classlist->setStatus('Approved');
                $this->getDoctrine()->getManager()->persist($classlist);
                $this->getDoctrine()->getManager()->persist($course);
                $this->getDoctrine()->getManager()->flush();
                return $this->redirectToRoute('course_edit', ['courseid' => $course->getId()]);
            } else {
                throw $this->createAccessDeniedException("This course is not yet available in ELW");
            }

        }

        //Check if on Roll (Classlist)
        $classuser = $this->getDoctrine()->getManager()->getRepository('App:Classlist')->findCourseUser($course, $user);
        if (!$classuser) {
            $classlist = new Classlist();
            $classlist->setUser($user);
            $classlist->setCourse($course);
            $classlist->setRole('Student');
            $classlist->setStatus('Approved');
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($classlist);
            $entityManager->flush();
        }
        $courseid = $course->getId();

        // Actual passing of auth to Symfony firewall and sessioning
        $guardAuthenticatorHandler->authenticateUserAndHandleSuccess($user, $request, $ltiAuthenticator, 'main');

        if ($this->get('security.authorization_checker')->isGranted('ROLE_ADMIN')) {
            return $this->render('lti/ltiLaunch.html.twig', [
                'course' => $course,
                'token' => $token,
            ]);
        } else {
            return $this->redirectToRoute('course_show', ['courseid' => $courseid]);
        }
    }



    /**
     * @Route("/{courseid}/lti_nrps", name="lti_nrps", methods={"GET","POST"})
     */
    public function nrps(String $courseid)
    {
        //needs to move to config
        $registration_name = 'ugatest2';
        //move to course entity??
        $deployment_id = 'ce0f6d44-e598-4400-a2bd-ce6884eb416d';

        $course = $this->getDoctrine()->getManager()->getRepository('App:Course')->findOneByCourseid($courseid);
        $classlists = $this->getDoctrine()->getManager()->getRepository('App:Classlist')->findByCourseid($courseid);

        $method = 'get';
        $scope = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';
        $accept_header = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';

        $registration = $this->repository->find($registration_name);
        $uri = $registration->getPlatform()->getAudience().'/d2l/api/lti/nrps/2.0/deployment/'.$deployment_id.'/orgunit/'.$course->getLtiId().'/memberships';
        $access_token = $this->getAccessToken($registration, $scope);
        $options = $this->getHeaderOptions($access_token, $accept_header);
        $response = $this->guzzle->request($method, $uri, $options);
        $data = json_decode($response->getBody()->__toString(), true);

        return $this->render('lti/nrps.html.twig', [
            'membership' => $data,
            'classlists' => $classlists,
            'course' => $course,
        ]);
    }


    /**
     * @Route("/{courseid}/lti_ags_index", name="lti_ags", methods={"GET"})
     */
    public function ags(String $courseid)
    {
        //needs to move to config
        $registration_name = 'ugatest2';
        //move to course entity??
        $deployment_id = 'ce0f6d44-e598-4400-a2bd-ce6884eb416d';

        $course = $this->getDoctrine()->getManager()->getRepository('App:Course')->findOneByCourseid($courseid);
        $classlists = $this->getDoctrine()->getManager()->getRepository('App:Classlist')->findByCourseid($courseid);

        $method = 'get';
        $scope = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
        $accept_header = 'application/vnd.ims.lis.v2.lineitemcontainer+json';

        $registration = $this->repository->find($registration_name);
        $uri = $registration->getPlatform()->getAudience().'/d2l/api/lti/ags/2.0/deployment/'.$deployment_id.'/orgunit/'.$course->getLtiId().'/lineitems';
        $access_token = $this->getAccessToken($registration, $scope);
        $options = $this->getHeaderOptions($access_token, $accept_header);
        $response = $this->guzzle->request($method, $uri, $options);
        $data = json_decode($response->getBody()->__toString(), true);

        return $this->render('lti/ags_index.html.twig', [
            'lineitems' => $data,
            'classlists' => $classlists,
            'course' => $course,
        ]);
    }

    /**
     * @Route("/{courseid}/lti_ags_new", name="lti_ags_new", methods={"GET","POST"})
     */
    public function ags_new(Request $request, string $courseid)
    {
        $form = $this->createForm(LtiAgsLineitemType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            //needs to move to config
            $registration_name = 'ugatest2';
            //move to course entity??
            $deployment_id = 'ce0f6d44-e598-4400-a2bd-ce6884eb416d';

            $course = $this->getDoctrine()->getManager()->getRepository('App:Course')->findOneByCourseid($courseid);

            $method = 'post';
            $scope = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
            $accept_header = 'application/vnd.ims.lis.v2.lineitem+json';
            $data = $form->getData();
            $registration = $this->repository->find($registration_name);
            $uri = $registration->getPlatform()->getAudience().'/d2l/api/lti/ags/2.0/deployment/'.$deployment_id.'/orgunit/'.$course->getLtiId().'/lineitems';
            $access_token = $this->getAccessToken($registration, $scope);
            $options = [
                'headers' => ['Authorization' => sprintf('Bearer %s', $access_token), 'Accept' => $accept_header],
                'json' => [
                    "scoreMaximum" => $data['scoreMaximum'],
                    "label" => $data['label'],
                    "resourceId" => $data['resourceId'],
                    "tag" => $data['tag'],
                    "startDateTime"=> $data['startDateTime'],
                    "endDateTime"=> $data['endDateTime']
                ]
            ];
            $response = $this->guzzle->request($method, $uri, $options);
            $data = json_decode($response->getBody()->__toString(), true);


            dd($data);
        }

        return $this->render('lti/new.html.twig', [
            'form' => $form->createView()
        ]);
    }

    private function getHeaderOptions($access_token, $accept_header) {
        $options = [
            'headers' => ['Authorization' => sprintf('Bearer %s', $access_token), 'Accept' => $accept_header]
        ];
        return $options;
    }

    /**
     * @throws LtiExceptionInterface
     */
    private function getAccessToken(RegistrationInterface $registration, $scope): string
    {
            $response = $this->guzzle->request('POST', $registration->getPlatform()->getOAuth2AccessTokenUrl(), [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                    'client_assertion' => $this->generateCredentials($registration),
                    'scope' => $scope
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('invalid response http status code');
            }

            $responseData = json_decode($response->getBody()->__toString(), true);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new RuntimeException(sprintf('json_decode error: %s', json_last_error_msg()));
            }

            $accessToken = $responseData['access_token'] ?? '';
            $expiresIn = $responseData['expires_in'] ?? '';

            if (empty($accessToken) || empty($expiresIn)) {
                throw new RuntimeException('invalid response body');
            }

            return $accessToken;
    }

    /**
     * @throws LtiExceptionInterface
     */
    public function generateCredentials(RegistrationInterface $registration): string
    {
        try {
            if (null === $registration->getToolKeyChain()) {
                throw new LtiException('Tool key chain is not configured');
            }

            $now = Carbon::now();
            return $this->builder
                ->withHeader(MessagePayloadInterface::HEADER_KID, $registration->getToolKeyChain()->getIdentifier())
                ->identifiedBy(sprintf('%s-%s', $registration->getIdentifier(), $now->getTimestamp()))
                ->issuedBy($registration->getClientId())
                ->relatedTo($registration->getClientId())
                ->permittedFor($registration->getTool()->getAudience())
                ->issuedAt($now->getTimestamp())
                ->expiresAt($now->addSeconds(MessagePayloadInterface::TTL)->getTimestamp())
                ->getToken($this->signer, $registration->getToolKeyChain()->getPrivateKey())
                ->__toString();

        } catch (Throwable $exception) {
            throw new LtiException(
                sprintf('Cannot generate credentials: %s', $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
        }

    }
}

<?php

declare(strict_types=1);

namespace App\Controller\amoCRM;

use AmoCRM\Collections\CatalogElementsCollection;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\TasksCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\CatalogElementModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\Customers\CustomerModel;
use AmoCRM\Models\CustomFieldsValues\NumericCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\RadiobuttonCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NumericCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\RadiobuttonCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\RadiobuttonCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\TaskModel;
use ApiPlatform\Validator\ValidatorInterface;
use App\Component\AmoBootstrap;
use App\Component\Core\ParameterGetter;
use App\Component\Token\TokenActions;
use App\Component\User\CurrentUser;
use App\Component\User\Dtos\PersonalDataDto;
use App\Controller\Base\AbstractController;
use DateTime;
use DateTimeZone;
use Exception;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class IntegratePersonalDataAction extends AbstractController
{
    private $apiClient;
    private bool $hasContactLead = false;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        ValidatorInterface $validator,
        CurrentUser $currentUser,
        private SerializerInterface $serializer,
        private ParameterGetter $parameterGetter,
        private AmoBootstrap $amoBootstrap
    ) {
        parent::__construct($serializer, $validator, $currentUser);
        $this->apiClient = $this->amoBootstrap->getApiClient();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws AmoCRMApiException
     * @throws Exception
     */
    public function __invoke(Request $request, TokenActions $tokenActions): Response
    {
        /** @var PersonalDataDto $personalDataDto */
        $personalDataDto = $this->serializer->deserialize(
            $request->getContent(),
            PersonalDataDto::class,
            'json'
        );

//      validation
        $this->validate($personalDataDto);
        $isNotValid = $this->validatePersonalData($personalDataDto);

        if ($isNotValid) {
            throw new BadRequestException('Some fields are not filled in');
        } else {
            $accessToken = $tokenActions->getToken();

//          refresh access token
            $this->apiClient->setAccessToken($accessToken)
                ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
                ->onAccessTokenRefresh(
                    function (AccessTokenInterface $accessToken, string $baseDomain) {
                        saveToken(
                            [
                                'accessToken' => $accessToken->getToken(),
                                'refreshToken' => $accessToken->getRefreshToken(),
                                'expires' => $accessToken->getExpires(),
                                'baseDomain' => $baseDomain,
                            ]
                        );
                    }
                );

            try {
                $contacts = $this->apiClient->contacts()->get()->all();

                $duplicatedContact = $this->getDuplicateContact($contacts, $personalDataDto->getPhone());
                $isContactLeadSuccessStatus = $this->isContactLeadWonStatus($contacts, $personalDataDto->getPhone());

//              check contact to duplicate and his lead in success status, if it has duplicate than creates customer
                if ($duplicatedContact !== null) {
                    if ($isContactLeadSuccessStatus) {
                        $customer = (new CustomerModel())->setName($duplicatedContact->getName());
                        $customer = $this->apiClient->customers()->addOne($customer);

//                      link customer and contact
                        $linksCustomer = new LinksCollection();
                        $linksCustomer->add($duplicatedContact);

                        $this->apiClient->customers()->link($customer, $linksCustomer);
                    } elseif (!$this->hasContactLead) {
                        $this->createLeadTaskProduct($duplicatedContact);
                    }
                } else {
//                  create new contact and lead
                    $contact = $this->createContact($personalDataDto);
                    $this->createLeadTaskProduct($contact);
                }
            } catch (AmoCRMApiException $e) {
//              create new contact and lead
                $contact = $this->createContact($personalDataDto);
                $this->createLeadTaskProduct($contact);
            }

            return $this->responseNormalized(['status' => 'OK'], 201);
        }
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMApiException
     * @throws Exception
     */
    private function createLeadTaskProduct(ContactModel $contact): void
    {
        $lead = $this->createLead();

//      link contact and lead
        $linksContacts = new LinksCollection();
        $linksContacts->add($lead);
        $this->apiClient->contacts()->link($contact, $linksContacts);

//      get all account users
        $usersCollection = $this->apiClient->users()->get();

//      prepare data for creating task
        $deadline = 4;
        $completeTill = $this->calculateCompletionDate($deadline);
        $randomResponsibleUser = $usersCollection[array_rand($usersCollection->toArray())];

//      create task
        $tasksCollection = $this->createTask($completeTill, $lead, $randomResponsibleUser->getId());
        $this->apiClient->tasks()->add($tasksCollection);

//      create products
        $products = $this->createProducts();

//      link products and lead
        $linksLeads = new LinksCollection();

        $quantities = [30, 20];

        foreach ($products as $key => $product) {
            $product->setQuantity($quantities[$key]);
            $linksLeads->add($product);
        }

        $this->apiClient->leads()->link($lead, $linksLeads);
    }

    private function validatePersonalData(PersonalDataDto $personalData): bool
    {
        if (
            $this->isEmpty($personalData->getFirstName()) ||
            $this->isEmpty($personalData->getLastName()) ||
            $this->isEmpty($personalData->getEmail()) ||
            $this->isEmpty($personalData->getPhone()) ||
            $this->isEmpty((string)$personalData->getAge()) ||
            !preg_match('/^\+?\d+$/', $personalData->getPhone()) ||
            !preg_match('/^[a-z.-]+@[a-z.-]+\.[a-z]+$/i', $personalData->getEmail()) ||
            !preg_match('/^[1-9][0-9]*$/', (string)$personalData->getAge()) ||
            $personalData->getAge() > 120 ||
            !is_bool($personalData->getIsMale())
        ) {
            return true;
        }

        return false;
    }

    private function isEmpty(string $value): bool
    {
        return $value === '';
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     * @throws NotFoundExceptionInterface
     * @throws AmoCRMApiException
     */
    private function createContact(PersonalDataDto $personalDataDto): ContactModel
    {
        $phoneField = +$this->parameterGetter->getString('phone_field_id');
        $emailField = +$this->parameterGetter->getString('email_field_id');
        $ageField = +$this->parameterGetter->getString('age_field_id');
        $genderField = +$this->parameterGetter->getString('gender_field_id');
        $maleEnumId = +$this->parameterGetter->getString('male_enum_id');
        $femaleEnumId = +$this->parameterGetter->getString('female_enum_id');

        $contact = (new ContactModel)
            ->setFirstName($personalDataDto->getFirstName())
            ->setLastName($personalDataDto->getLastName())
            ->setCustomFieldsValues(
                (new CustomFieldsValuesCollection)
                    ->add(
                        (new TextCustomFieldValuesModel)
                            ->setFieldId($phoneField)
                            ->setValues(
                                (new TextCustomFieldValueCollection)
                                    ->add(
                                        (new TextCustomFieldValueModel)
                                            ->setValue($personalDataDto->getPhone())
                                    )
                            )
                    )
                    ->add(
                        (new TextCustomFieldValuesModel)
                            ->setFieldId($emailField)
                            ->setValues(
                                (new TextCustomFieldValueCollection)
                                    ->add(
                                        (new TextCustomFieldValueModel)
                                            ->setValue($personalDataDto->getEmail())
                                    )
                            )
                    )
                    ->add(
                        (new NumericCustomFieldValuesModel)
                            ->setFieldId($ageField)
                            ->setValues(
                                (new TextCustomFieldValueCollection)
                                    ->add(
                                        (new NumericCustomFieldValueModel)
                                            ->setValue($personalDataDto->getAge())
                                    )
                            )
                    )
                    ->add(
                        (new RadiobuttonCustomFieldValuesModel)
                            ->setFieldId($genderField)
                            ->setValues(
                                (new RadiobuttonCustomFieldValueCollection)
                                    ->add(
                                        (new RadiobuttonCustomFieldValueModel)
                                            ->setEnumId(
                                                $personalDataDto->getIsMale() ? $maleEnumId : $femaleEnumId
                                            )
                                    )
                            )
                    )
            );

        return $this->apiClient->contacts()->addOne($contact);
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    private function createLead(): LeadModel
    {
        $lead = (new LeadModel)
            ->setName('Сделка №' . rand(1, 999))
            ->setPrice(rand(10000, 500000));

        return $this->apiClient->leads()->addOne($lead);
    }

    private function createTask(int $time, LeadModel $lead, int $userId): TasksCollection
    {
        $tasksCollection = new TasksCollection();

        $task = (new TaskModel)
            ->setTaskTypeId(TaskModel::TASK_TYPE_ID_FOLLOW_UP)
            ->setText('Новая задача к сделку ИД: ' . $lead->getId())
            ->setCompleteTill($time)
            ->setEntityType(EntityTypesInterface::LEADS)
            ->setEntityId($lead->getId())
            ->setDuration(0)
            ->setResponsibleUserId($userId);

        return $tasksCollection->add($task);
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    private function createProducts(): CatalogElementsCollection
    {
        $catalogId = +$this->parameterGetter->getString('catalog_id');
        $catalogsCollection = $this->apiClient->catalogs()->get();
        $catalog = $catalogsCollection->getBy('id', $catalogId);

        $catalogElementsCollection = (new CatalogElementsCollection())
            ->add((new CatalogElementModel())
                ->setName('Hydrolife')
                ->setCustomFieldsValues($this->makeProductPrice(120))
            )
            ->add((new CatalogElementModel())
                ->setName('Silver Water')
                ->setCustomFieldsValues($this->makeProductPrice(100))
            );

        return $this->apiClient->catalogElements($catalog->getId())->add($catalogElementsCollection);
    }

    private function makeProductPrice(int $price): CustomFieldsValuesCollection
    {
        $productFieldCode = $this->parameterGetter->getString('product_field_code');

        return (new CustomFieldsValuesCollection)
            ->add((new NumericCustomFieldValuesModel())
                ->setFieldCode($productFieldCode)
                ->setValues((new NumericCustomFieldValueCollection())
                    ->add((new NumericCustomFieldValueModel())
                        ->setValue($price)
                    )
                )
            );
    }

    /**
     * @throws Exception
     */
    private function calculateCompletionDate(int $workingDaysToAdd): int
    {
        $workHoursStart = 9; // начало рабочего дня (9:00)
        $workHoursEnd = 18; // конец рабочего дня (18:00)

        // Установка временной зоны
        $timezone = new DateTimeZone('Asia/Tashkent');
        $creationDate = new DateTime('now', $timezone);
        $currentDate = $creationDate->getTimestamp();
        $workDayCounter = 0;

        // Пока не достигнуто необходимое количество рабочих дней
        while ($workDayCounter < $workingDaysToAdd) {
            // Увеличиваем текущую дату на 1 день
            $creationDate->modify('+1 day');
            $currentDate = $creationDate->setTime($workHoursEnd, 0)->getTimestamp();

            // Проверяем, является ли текущий день рабочим
            if ($this->isWorkingDay($currentDate)) {
                // Устанавливаем время на конец рабочего дня (18:00)
                $currentHour = $creationDate->format('G');

                if ($currentHour < $workHoursStart) {
                    // Если текущее время меньше начала рабочего дня, устанавливаем на начало рабочего дня
                    $creationDate->setTime($workHoursStart, 0);
                    $currentDate = $creationDate->getTimestamp();
                } elseif ($currentHour >= $workHoursEnd) {
                    // Если текущее время больше или равно концу рабочего дня, устанавливаем на конец рабочего дня
                    $creationDate->setTime($workHoursEnd, 0);
                    $currentDate = $creationDate->getTimestamp();
                }

                // Увеличиваем счетчик рабочих дней
                $workDayCounter++;
            }
        }

        return $currentDate;
    }

    private function isWorkingDay($timestamp): bool
    {
        // Получаем день недели (0 - воскресенье, 1 - понедельник, ..., 6 - суббота)
        $dayOfWeek = date('w', $timestamp);

        // Проверяем, что это понедельник-пятница (дни с 1 по 5)
        if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
            return true;
        }

        return false;
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    private function checkContactToValid(ContactsCollection $contacts, string $phone): ?ContactModel
    {
        $contact = $this->getDuplicateContact($contacts, $phone);

        if ($contact === null) {
            return null;
        }
        if (!$this->isContactLeadWonStatus($contacts, $phone)) {
            return null;
        }

        return $contact;
    }

    private function getDuplicateContact(array $contacts, string $phone): ?ContactModel
    {
        $phoneFieldCode = $this->parameterGetter->getString('phone_field_code');

        /**
         * @var ContactModel $contact
         */
        foreach ($contacts as $contact) {
            $contactPhoneNumbers = $contact->getCustomFieldsValues()
                ->getBy('fieldCode', $phoneFieldCode)
                ->getValues();

            foreach ($contactPhoneNumbers as $phoneNumber) {
                // Удаляю все нечисловые символы из значении переменных $phoneNumber->getValue() и $phone
                $phone1 = preg_replace('/\D/', '', $phoneNumber->getValue());
                $phone2 = preg_replace('/\D/', '', $phone);

                if ($phone1 === $phone2) {
                    return $contact;
                }
            }
        }

        return null;
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    private function isContactLeadWonStatus(?array $contacts, string $phone): bool
    {
        $phoneFieldCode = $this->parameterGetter->getString('phone_field_code');

        /**
         * @var ContactModel $contact
         */
        foreach ($contacts as $contact) {
            $contactPhoneNumbers = $contact->getCustomFieldsValues()
                ->getBy('fieldCode', $phoneFieldCode)
                ->getValues();

            foreach ($contactPhoneNumbers as $phoneNumber) {
                // Удаляю все нечисловые символы из значении переменных $phoneNumber->getValue() и $phone
                $phone1 = preg_replace('/\D/', '', $phoneNumber->getValue());
                $phone2 = preg_replace('/\D/', '', $phone);

                if ($phone1 === $phone2) {
                    $leads = $contact->getLeads();

                    if ($leads !== null) {
                        $this->hasContactLead = true;
                        /** @var LeadModel $lead */
                        foreach ($leads as $lead) {
                            $lead = $this->apiClient->leads()->getOne($lead->getId());
                            if ($lead->getStatusId() === LeadModel::WON_STATUS_ID) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }
}

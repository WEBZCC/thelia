<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Thelia\Form;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\ValidatorBuilder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

/**
 * Base form class for creating form objects.
 *
 * Class BaseForm
 *
 * @author Manuel Raynaud <manu@raynaud.io>
 */
abstract class BaseForm implements FormInterface
{
    /**
     * @var FormBuilderInterface
     */
    protected $formBuilder;

    /**
     * @var FormFactoryBuilderInterface
     */
    protected $formFactoryBuilder;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var ValidatorBuilder
     */
    protected $validatorBuilder;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    private $view;

    /**
     * true if the form has an error, false otherwise.
     *
     * @var bool
     */
    private $has_error = false;

    /**
     * The form error message.
     *
     * @var string
     */
    private $error_message = '';

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $dispatcher;

    /**
     * @var string
     */
    private $type;

    /**
     * @deprecated Thelia forms should not be instantiated directly. Please use BaseController::createForm() instead or form factory
     * @see BaseController::createForm()
     * @see TheliaFormFactory::createForm()
     */
    public function __construct(
        Request $request,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        FormFactoryBuilderInterface $formFactoryBuilder,
        ValidatorBuilder $validationBuilder,
        string $type = "Symfony\Component\Form\Extension\Core\Type\FormType",
        array $data = [],
        array $options = []
    ) {
        $this->request = $request;
        $this->type = $type;

        $this->dispatcher = $eventDispatcher;
        $this->translator = $translator;
        $this->formFactoryBuilder = $formFactoryBuilder;
        $this->validatorBuilder = $validationBuilder;

        $this->initFormWithRequest($type, $data, $options);

        if (!isset($options['csrf_protection']) || $options['csrf_protection'] !== false) {
            $this->formFactoryBuilder
                ->addExtension(
                    new CsrfExtension(
                        new CsrfTokenManager(null, new SessionTokenStorage(
                            $this->getRequest()->getSession()
                        ))
                    )
                )
            ;
        }

        /**
         * Build the form.
         */
        $name = $this::getName();

        $this->formBuilder = $this->formFactoryBuilder
            ->addExtension(new ValidatorExtension($this->validatorBuilder->getValidator()))
            ->getFormFactory()
            ->createNamedBuilder($name, $type, $data, $this->cleanOptions($options))
        ;

        $event = new TheliaFormEvent($this);

        $this->dispatcher->dispatch(
            $event,
            TheliaEvents::FORM_BEFORE_BUILD.'.'.$name
        );

        $this->buildForm();

        $this->dispatcher->dispatch(
            $event,
            TheliaEvents::FORM_AFTER_BUILD.'.'.$name
        );

        // If not already set, define the success_url field
        // This field is not included in the standard form hidden fields
        // This field is not included in the hidden fields generated by form_hidden_fields Smarty function
        if (!$this->formBuilder->has('success_url')) {
            $this->formBuilder->add('success_url', HiddenType::class);
        }

        // If not already set, define the error_url field
        // This field is not included in the standard form hidden fields
        // This field is not included in the hidden fields generated by form_hidden_fields Smarty function
        if (!$this->formBuilder->has('error_url')) {
            $this->formBuilder->add('error_url', HiddenType::class);
        }

        // The "error_message" field defines the error message displayed if
        // the form could not be validated. If it is empty, a standard error message is displayed instead.
        // This field is not included in the hidden fields generated by form_hidden_fields Smarty function
        if (!$this->formBuilder->has('error_message')) {
            $this->formBuilder->add('error_message', HiddenType::class);
        }

        $this->form = $this->formBuilder->getForm();
    }

    protected function initFormWithRequest($type, $data, $options): void
    {
        $this->validatorBuilder = Validation::createValidatorBuilder();

        $this->formFactoryBuilder = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
        ;

        $this->translator = Translator::getInstance();

        $this->validatorBuilder
            ->setTranslationDomain('validators')
            ->setTranslator($this->translator);
    }

    /**
     * @return FormBuilderInterface
     */
    public function getFormBuilder()
    {
        return $this->formBuilder;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Return true if the given field value is defined only in the HTML template, and its value is defined
     * in the template file, not the form builder.
     * Thus, it should not be included in the form hidden fields generated by form_hidden_fields
     * Smarty function, to prevent it from existing twice in the form.
     *
     * @return bool
     */
    public function isTemplateDefinedHiddenField(FormView $fieldView)
    {
        return $this->isTemplateDefinedHiddenFieldName($fieldView->vars['name']);
    }

    public function isTemplateDefinedHiddenFieldName($fieldName)
    {
        return $fieldName == 'success_url' || $fieldName == 'error_url' || $fieldName == 'error_message';
    }

    /**
     * @return \Thelia\Core\HttpFoundation\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    protected function cleanOptions($options)
    {
        unset($options['csrf_protection']);

        return $options;
    }

    /**
     * Returns the absolute URL to redirect the user to if the form is not successfully processed,
     * using the 'error_url' form parameter value.
     *
     * @param string $default the default URL. If not given, the configured base URL is used.
     *
     * @return string an absolute URL
     */
    public function getErrorUrl(string $default = null)
    {
        return $this->getFormDefinedUrl('error_url', $default);
    }

    /**
     * @return bool true if an error URL is defined in the 'error_url' form parameter, false otherwise
     */
    public function hasErrorUrl()
    {
        return null !== $this->form->get('error_url')->getData();
    }

    /**
     * Returns the absolute URL to redirect the user to if the form is successfully processed.
     * using the 'success_url' form parameter value.
     *
     * @param string|null $default the default URL. If not given, the configured base URL is used.
     *
     * @return string an absolute URL
     */
    public function getSuccessUrl(string $default = null)
    {
        return $this->getFormDefinedUrl('success_url', $default);
    }

    /**
     * @return bool true if a success URL is defined in the form, false otherwise
     */
    public function hasSuccessUrl()
    {
        return null !== $this->form->get('success_url')->getData();
    }

    /**
     * Build an absolute URL using the value of a form parameter.
     *
     * @param string      $parameterName the form parameter name
     * @param string|null $default       a default value for the form parameter. If not defined, the configured base URL is used.
     *
     * @return string an absolute URL
     */
    public function getFormDefinedUrl(string $parameterName, string $default = null): string
    {
        $formDefinedUrl = $this->form->get($parameterName)->getData();

        if (empty($formDefinedUrl)) {
            if ($default === null) {
                $default = ConfigQuery::read('base_url', '/');
            }

            $formDefinedUrl = $default;
        }

        return URL::getInstance()->absoluteUrl($formDefinedUrl);
    }

    public function createView()
    {
        $this->view = $this->form->createView();

        return $this;
    }

    /**
     * @return FormView
     *
     * @throws \LogicException
     */
    public function getView()
    {
        if ($this->view === null) {
            throw new \LogicException('View was not created. Please call BaseForm::createView() first.');
        }

        return $this->view;
    }

    // -- Error and errro message ----------------------------------------------

    /**
     * Set the error status of the form.
     *
     * @param bool $has_error
     *
     * @return $this
     */
    public function setError($has_error = true): self
    {
        $this->has_error = $has_error;

        return $this;
    }

    /**
     * Get the cuirrent error status of the form.
     *
     * @return bool
     */
    public function hasError()
    {
        return $this->has_error;
    }

    /**
     * Set the error message related to global form error.
     *
     * @return $this
     */
    public function setErrorMessage(string $message): self
    {
        $this->setError(true);
        $this->error_message = $message;

        return $this;
    }

    /**
     * Get the form error message.
     *
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->error_message;
    }

    /**
     * @return Form
     */
    public function getForm()
    {
        return $this->form;
    }

    /**
     * Override this method if you don't want to use the standard name, which is created from the full class name.
     *
     * @return string the name of you form. This name must be unique
     */
    public static function getName()
    {
        $classParts = explode('\\', static::class);
        $nameParts = array_map(function ($classPart, $index) use ($classParts) {
            if ($index !== \count($classParts) - 1) {
                return strtolower($classPart);
            }

            return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $classPart));
        }, $classParts, array_keys($classParts));

        return implode('_', $nameParts);
    }

    /**
     * in this function you add all the fields you need for your Form.
     * Form this you have to call add method on $this->formBuilder attribute :.
     *
     * $this->formBuilder->add("name", TextType::class)
     *   ->add("email", EmailType::class, array(
     *           "attr" => array(
     *               "class" => "field"
     *           ),
     *           "label" => "email",
     *           "constraints" => array(
     *               new \Symfony\Component\Validator\Constraints\NotBlank()
     *           )
     *       )
     *   )
     *   ->add('age', IntegerType::class);
     *
     * @return null
     */
    abstract protected function buildForm();
}

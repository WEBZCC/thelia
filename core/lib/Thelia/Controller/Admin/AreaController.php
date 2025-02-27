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

namespace Thelia\Controller\Admin;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Area\AreaAddCountryEvent;
use Thelia\Core\Event\Area\AreaRemoveCountryEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Element\Exception\ElementNotFoundException;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\BaseForm;
use Thelia\Form\Definition\AdminForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Area;
use Thelia\Model\AreaQuery;
use Thelia\Model\Event\AreaEvent;

/**
 * Class AreaController.
 *
 * @author Manuel Raynaud <manu@raynaud.io>
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class AreaController extends AbstractCrudController
{
    public function __construct()
    {
        parent::__construct(
            'area',
            null,
            null,
            AdminResources::AREA,
            TheliaEvents::AREA_CREATE,
            TheliaEvents::AREA_UPDATE,
            TheliaEvents::AREA_DELETE
        );
    }

    protected function getAreaId()
    {
        return $this->getRequest()->get('area_id', 0);
    }

    /**
     * Return the creation form for this object.
     */
    protected function getCreationForm()
    {
        return $this->createForm(AdminForm::AREA_CREATE);
    }

    /**
     * Return the update form for this object.
     */
    protected function getUpdateForm()
    {
        return $this->createForm(AdminForm::AREA_MODIFICATION);
    }

    /**
     * Hydrate the update form for this object, before passing it to the update template.
     *
     * @param Area $object
     *
     * @return BaseForm
     */
    protected function hydrateObjectForm(ParserContext $parserContext, $object)
    {
        $data = [
            'area_id' => $object->getId(),
            'name' => $object->getName(),
        ];

        return $this->createForm(AdminForm::AREA_MODIFICATION, FormType::class, $data);
    }

    /**
     * Creates the creation event with the provided form data.
     *
     * @param array $formData
     */
    protected function getCreationEvent($formData)
    {
        $area = new Area();
        $event = new AreaEvent($area);

        return $this->hydrateEvent($event, $formData);
    }

    /**
     * Creates the update event with the provided form data.
     *
     * @param array $formData
     */
    protected function getUpdateEvent($formData): AreaEvent
    {
        $area = $this->findAreaOrFail($formData['area_id']);
        $event = new AreaEvent($area);

        $this->hydrateEvent($event, $formData);

        return $event;
    }

    private function hydrateEvent(AreaEvent $event, $formData): AreaEvent
    {
        $event->getModel()->setName($formData['name']);

        return $event;
    }

    /**
     * Creates the delete event with the provided form data.
     */
    protected function getDeleteEvent(): AreaEvent
    {
        $area = $this->findAreaOrFail($this->getAreaId());

        return new AreaEvent($area);
    }

    /**
     * Load an existing object from the database.
     */
    protected function getExistingObject()
    {
        return AreaQuery::create()->findPk($this->getAreaId());
    }

    /**
     * Returns the object label form the object event (name, title, etc.).
     *
     * @param \Thelia\Model\Area $object
     *
     * @return string
     */
    protected function getObjectLabel($object)
    {
        return $object->getName();
    }

    /**
     * Returns the object ID from the object.
     *
     * @param \Thelia\Model\Area $object
     *
     * @return int
     */
    protected function getObjectId($object)
    {
        return $object->getId();
    }

    /**
     * Render the main list template.
     *
     * @return \Thelia\Core\HttpFoundation\Response
     */
    protected function renderListTemplate($currentOrder)
    {
        return $this->render('shipping-configuration');
    }

    /**
     * Render the edition template.
     */
    protected function renderEditionTemplate()
    {
        return $this->render(
            'shipping-configuration-edit',
            [
            'area_id' => $this->getAreaId(),
            ]
        );
    }

    /**
     * Redirect to the edition template.
     */
    protected function redirectToEditionTemplate()
    {
        return $this->generateRedirectFromRoute(
            'admin.configuration.shipping-configuration.update.view',
            [],
            [
                'area_id' => $this->getAreaId(),
            ]
        );
    }

    /**
     * Redirect to the list template.
     */
    protected function redirectToListTemplate()
    {
        return $this->generateRedirectFromRoute('admin.configuration.shipping-configuration.default');
    }

    /**
     * add a country to a define area.
     */
    public function addCountry(EventDispatcherInterface $eventDispatcher)
    {
        // Check current user authorization
        if (null !== $response = $this->checkAuth($this->resourceCode, [], AccessManager::UPDATE)) {
            return $response;
        }

        $areaCountryForm = $this->createForm(AdminForm::AREA_COUNTRY);
        $error_msg = null;
        try {
            $form = $this->validateForm($areaCountryForm);

            $area = $this->findAreaOrFail($form->get('area_id')->getData());

            $event = new AreaAddCountryEvent($area, $form->get('country_id')->getData());

            $eventDispatcher->dispatch($event, TheliaEvents::AREA_ADD_COUNTRY);

            // Log object modification
            if (null !== $changedObject = $event->getModel()) {
                $this->adminLogAppend(
                    $this->resourceCode,
                    AccessManager::UPDATE,
                    sprintf(
                        '%s %s (ID %s) modified, new country added',
                        ucfirst($this->objectName),
                        $this->getObjectLabel($changedObject),
                        $this->getObjectId($changedObject)
                    ),
                    $this->getObjectId($changedObject)
                );
            }

            // Redirect to the success URL
            return $this->generateSuccessRedirect($areaCountryForm);
        } catch (FormValidationException $ex) {
            // Form cannot be validated
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
            $error_msg = $ex->getMessage();
        }

        $this->setupFormErrorContext(
            $this->getTranslator()->trans('%obj modification', ['%obj' => $this->objectName]),
            $error_msg,
            $areaCountryForm
        );

        // At this point, the form has errors, and should be redisplayed.
        return $this->renderEditionTemplate();
    }

    /**
     * delete several countries from a shipping zone.
     */
    public function removeCountries(EventDispatcherInterface $eventDispatcher)
    {
        // Check current user authorization
        if (null !== $response = $this->checkAuth($this->resourceCode, [], AccessManager::UPDATE)) {
            return $response;
        }

        $areaDeleteCountriesForm = $this->createForm(AdminForm::AREA_DELETE_COUNTRY);

        try {
            $form = $this->validateForm($areaDeleteCountriesForm);

            $data = $form->getData();
            $area = $this->findAreaOrFail($form->get('area_id')->getData());

            foreach ($data['country_id'] as $countryId) {
                $country = explode('-', $countryId);
                $this->removeOneCountryFromArea($eventDispatcher, $area, $country[0], $country[1]);
            }
            // Redirect to the success URL
            return $this->generateSuccessRedirect($areaDeleteCountriesForm);
        } catch (FormValidationException $ex) {
            // Form cannot be validated
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
            $error_msg = $ex->getMessage();
        }

        $this->setupFormErrorContext(
            $this->getTranslator()->trans('Failed to delete selected countries'),
            $error_msg,
            $areaDeleteCountriesForm
        );

        // At this point, the form has errors, and should be redisplayed.
        return $this->renderEditionTemplate();
    }

    protected function removeOneCountryFromArea(EventDispatcherInterface $eventDispatcher, Area $area, $countryId, $stateId): void
    {
        if ((int) $stateId === 0) {
            $stateId = null;
        }

        $removeCountryEvent = new AreaRemoveCountryEvent($area, $countryId, $stateId);

        $eventDispatcher->dispatch($removeCountryEvent, TheliaEvents::AREA_REMOVE_COUNTRY);

        if (null !== $changedObject = $removeCountryEvent->getModel()) {
            $this->adminLogAppend(
                $this->resourceCode,
                AccessManager::UPDATE,
                sprintf(
                    '%s %s (ID %s) removed country ID %s from shipping zone ID %s',
                    ucfirst($this->objectName),
                    $this->getObjectLabel($changedObject),
                    $this->getObjectId($changedObject),
                    $countryId,
                    $area->getId()
                ),
                $this->getObjectId($changedObject)
            );
        }
    }

    public function removeCountry(EventDispatcherInterface $eventDispatcher)
    {
        // Check current user authorization
        if (null !== $response = $this->checkAuth($this->resourceCode, [], AccessManager::UPDATE)) {
            return $response;
        }

        $this->removeOneCountryFromArea(
            $eventDispatcher,
            $this->getRequest()->get('area_id', 0),
            $this->getRequest()->get('country_id', 0),
            $this->getRequest()->get('state_id', null)
        );

        return $this->redirectToEditionTemplate();
    }

    protected function findAreaOrFail($areaId): Area
    {
        $area = AreaQuery::create()
            ->filterById($areaId)
            ->findOne();

        if (null === $area) {
            throw new ElementNotFoundException(
                $this->getTranslator()->trans('Area not found')
            );
        }

        return $area;
    }
}

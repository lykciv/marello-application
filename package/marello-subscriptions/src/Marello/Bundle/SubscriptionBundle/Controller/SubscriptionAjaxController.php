<?php

namespace Marello\Bundle\SubscriptionBundle\Controller;

use Marello\Bundle\LayoutBundle\Context\FormChangeContext;
use Marello\Bundle\SubscriptionBundle\Entity\Subscription;
use Marello\Bundle\SubscriptionBundle\Form\Type\SubscriptionType;
use Marello\Bundle\SubscriptionBundle\Form\Type\SubscriptionUpdateType;
use Oro\Bundle\SecurityBundle\Annotation\AclAncestor;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as Config;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SubscriptionAjaxController extends Controller
{
    /**
     * @Config\Route("/form-changes/{id}", name="marello_subscription_form_changes", defaults={"id" = 0})
     * @Config\Method({"POST"})
     * @AclAncestor("marello_subscription_create")
     *
     * @param Request $request
     * @param Subscription|null $subscription
     * @return JsonResponse
     */
    public function formChangesAction(Request $request, Subscription $subscription = null)
    {
        if (!$subscription) {
            $subscription = new Subscription();
        }

        $form = $this->getType($subscription);
        $submittedData = $request->get($form->getName());

        $form->submit($submittedData);

        $context = new FormChangeContext(
            [
                FormChangeContext::FORM_FIELD => $form,
                FormChangeContext::SUBMITTED_DATA_FIELD => $submittedData,
                FormChangeContext::RESULT_FIELD => [],
            ]
        );

        $formChangesProvider = $this->get('marello_layout.provider.form_changes_data.composite');
        $formChangesProvider
            ->setRequiredDataClass(Subscription::class)
            ->setRequiredFields($request->get('updateFields', []))
            ->processFormChanges($context);

        return new JsonResponse($context->getResult());
    }

    /**
     * @param Subscription $subscription
     * @return FormInterface
     */
    protected function getType(Subscription $subscription)
    {
        if ($subscription->getId()) {
            return $this->createForm(SubscriptionUpdateType::class, $subscription);
        }

        return $this->createForm(SubscriptionType::class, $subscription);
    }
}

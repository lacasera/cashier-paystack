<?php
namespace Wisdomanthoni\Cashier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Routing\Controller;
use Wisdomanthoni\Cashier\Cashier;
use Wisdomanthoni\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Wisdomanthoni\Cashier\Http\Middleware\VerifyWebhookSignature;


class WebhookController extends Controller
{
    /**
     * Create a new webhook controller instance.
     *
     * @return voCode
     */
    public function __construct()
    {
        $this->middleware(VerifyWebhookSignature::class);
    }
    /**
     * Handle a Paystack webhook call.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        $method = 'handle'.studly_case(str_replace('.', '_', $payload['event']));
        if (method_exists($this, $method)) {
            return $this->{$method}($payload);
        }
        return $this->missingMethod();
    }
    /**
     * Handle a subscription cancellation notification from paystack.
     *
     * @param  array $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleSubscriptionCanceled($payload)
    {
        return $this->cancelSubscription($payload->data->Code);
    }
    /**
     * Handle a subscription cancellation notification from paystack.
     *
     * @param  string  $subscriptionCode
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function cancelSubscription($subscriptionCode)
    {
        $subscription = $this->getSubscriptionByCode($subscriptionCode);
        if ($subscription && (! $subscription->cancelled() || $subscription->onGracePeriod())) {
            $subscription->markAsCancelled();
        }
        return new Response('Webhook Handled', 200);
    }
    /**
     * Get the model for the given subscription Code.
     *
     * @param  string  $subscriptionCode
     * @return \Wisdomanthoni\Cashier\Subscription|null
     */
    protected function getSubscriptionByCode($subscriptionCode): ?Subscription
    {
        return Subscription::where('paystack_code', $subscriptionCode)->first();
    }
    /**
     * Handle customer subscription create.
     *
     * @param  array $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleSubscriptionCreate(array $payload)
    {
        $user = $this->getUserByPaystackCode($payload['data']['customer']['customer_code']);
        if ($user) {
            $data = $payload['data'];
            $user->subscriptions->filter(function (Subscription $subscription) use ($data) {
                return $subscription->paystack_code === $data['subscription_code'];
            })->each(function (Subscription $subscription) use ($data) {
                // Quantity...
                if (isset($data['quantity'])) {
                    $subscription->quantity = $data['quantity'];
                }
                // Plan...
                if (isset($data['plan']['plan_code'])) {
                    $subscription->paystack_plan = $data['plan']['plan_code'];
                }
                // Trial ending date...
               
                // Cancellation date...
                
                $subscription->save();
            });
        }
        return new Response('Webhook Handled', 200);
    }
    /**
     * Get the billable entity instance by Paystack Code.
     *
     * @param  string  $paystackCode
     * @return \Wisdomanthoni\Cashier\Billable
     */
    protected function getUserByPaystackCode($paystackCode)
    {
        $model = Cashier::paystackModel();
        return (new $model)->where('paystack_code', $paystackCode)->first();
    }
    /**
     * Handle calls to missing methods on the controller.
     *
     * @param  array  $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function missingMethod($parameters = [])
    {
        return new Response;
    }
}
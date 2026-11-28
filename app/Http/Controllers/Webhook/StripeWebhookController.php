<?php

namespace App\Http\Controllers\Webhook;

use Throwable;
use App\Enums\OrderStatusEnum;
use App\Enums\PaymentStatusEnum;
use Illuminate\Http\Request;
use App\Support\Modules\ModuleStatus;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Ecommerce\Models\Order;
use Stripe\Webhook as StripeWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Stripe\Exception\SignatureVerificationException;
use Cms\Ecommerce\Services\Order\OrderInventorySettlementManager;

/**
 * Stripe webhook entry point (`POST /api/stripe/webhook`), owned by the core app because Stripe calls one fixed URL.
 *
 * Every order, payment and settlement class belongs to the Ecommerce module, which may be absent or switched off on a
 * site (it is online-installable). Without an enabled Ecommerce module the endpoint answers 404 before
 * any Ecommerce class is loaded or resolved.
 */
class StripeWebhookController extends Controller
{
    /**
     * Verify the Stripe signature and apply `checkout.session.completed` to the order named in the session metadata.
     *
     * Answers 404 when Ecommerce is not enabled, 500 when no webhook secret is configured, 400 for a bad signature or
     * payload, and 200 `{received: true}` otherwise (also for event types it ignores, so Stripe stops retrying).
     *
     * @param Request           $request  raw Stripe request
     * @param CMSSettingRuntime $settings settings runtime that reads the webhook secrets
     *
     * @return Responsable
     */
    public function handle(Request $request, CMSSettingRuntime $settings): Responsable
    {
        if (! ModuleStatus::isEnabled('Ecommerce') || ! class_exists(Order::class)) {
            return ires()->notFound('Not found.');
        }

        // 1) Read raw payload + signature header
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        // 2) Resolve the webhook secret of the current mode. The secrets are private settings rows (`isPublic: false`),
        //    never written to config/setting.php, so they are read from the row. The test-mode switch is a boolean row
        //    (a PHP bool in config/setting.php, the string 'true' in older generated files).
        $endpointSecret = $settings->secret(
            filter_var(config('setting.order.payment.enable_test_mode'), FILTER_VALIDATE_BOOLEAN)
                ? 'order.payment.test_webhook_secret'
                : 'order.payment.webhook_secret'
        );

        if (empty($endpointSecret)) {
            CMSLogger::error([
                'message' => 'Stripe webhook secret is not configured',
            ]);
            return ires()->error()->code(500)->data(['error' => 'webhook secret not configured']);
        }

        try {
            // 3) Verify signature with secret BEFORE parsing/using data
            // If your StripeService::constructEvent can accept $secret, use that.
            // Otherwise, use Stripe\Webhook directly (shown here):
            $event = StripeWebhook::constructEvent($payload, $signature, $endpointSecret);

            // Optional: idempotency guard (avoid reprocessing the same event)
            // if (ProcessedStripeEvent::where('event_id', $event->id)->exists()) {
            //     return ires()->data(['received' => true]); // already handled
            // }
            // ProcessedStripeEvent::create(['event_id' => $event->id]);

        } catch (SignatureVerificationException $e) {
            CMSLogger::warning([
                'message' => 'Stripe webhook invalid signature',
                'error' => $e->getMessage(),
            ]);
            return ires()->error()->code(400)->data(['error' => 'invalid signature']);
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => 'Stripe webhook constructEvent failed',
                'error' => $e->getMessage(),
            ]);
            return ires()->error()->code(400)->data(['error' => 'cannot parse event']);
        }

        // Debug (safe—`toArray()` only after verified)
        CMSLogger::info(['message' => 'Stripe event received', ...$event->toArray()]);

        // 4) Handle events
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $orderId = $session->metadata->order_id ?? null;

            if ($orderId && ($order = Order::query()->find($orderId))) {
                // Seeded enumerate keys: the status columns have a foreign key to enumerates on MySQL/MariaDB.
                $order->order_status = OrderStatusEnum::ORDER_PROCESSING->value;
                $order->payment_status = PaymentStatusEnum::PAYMENT_PAID->value;
                $order->total_paid = $session->amount_total / 100; // Convert cents to dollars
                // Update order_payment status
                $payment = $order->payments()->first();
                if ($payment) {
                    $payment->status = PaymentStatusEnum::PAYMENT_PAID->value;
                    $payment->amount = $order->total_paid;

                    $payment->save();
                }

                $order->save();

                // --- Inventory settlement ---
                try {
                    app(OrderInventorySettlementManager::class)->settlePaidOrder($order);
                } catch (\Throwable $e) {
                    CMSLogger::error([
                        'message' => 'Inventory settlement failed',
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ires()->data(['received' => true]);
    }
}

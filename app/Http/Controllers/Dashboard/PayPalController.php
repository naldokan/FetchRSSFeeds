<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Invoice;
use App\IPNStatus;
use App\Plan;
use App\Transaction;
use App\User;

use Illuminate\Http\Request;
use Srmklive\PayPal\Services\AdaptivePayments;
use Srmklive\PayPal\Services\ExpressCheckout;
use Carbon\Carbon; 

class PayPalController extends Controller
{
    /**
     * @var ExpressCheckout
     */
    protected $provider;
    
    public function __construct()
    {
        $this->provider = new ExpressCheckout();
    }
    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function getExpressCheckout(Plan $plan, Request $request)
    {
        // check if payment is recurring
        $recurring = ($request->get('mode') === 'recurring') ? true : false;

        //get new invoice id
        $invoice_id = Invoice::count() + 1;

        //Get the cart data
        $cart = $this->getCheckoutData($recurring, $plan, $invoice_id);

        //create new invoice
        $invoice = new Invoice();
        $invoice->title = $cart['invoice_description'];
        $invoice->price = $cart['total'];
        $invoice->user_id = auth()->user()->id;
        $invoice->save();

        try {
            $response = $this->provider->setExpressCheckout($cart, $recurring);
            return redirect($response['paypal_link']);
        } catch (\Exception $e) {
            return redirect()->route('home')->with(['code' => 'danger', 'message' => 'Error processing PayPal payment']);
        }
    }
    /**
     * Process payment on PayPal.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function getExpressCheckoutSuccess(Plan $plan, Request $request)
    {
        $recurring = ($request->get('mode') === 'recurring') ? true : false;
        $token = $request->get('token');
        $PayerID = $request->get('PayerID');
        // Verify Express Checkout Token
        $response = $this->provider->getExpressCheckoutDetails($token);
        
        if (!in_array(strtoupper($response['ACK']), ['SUCCESS', 'SUCCESSWITHIWARNING'])) {
            return redirect()->route('home')->with(['code' => 'danger', 'message' => 'Error processing PayPal payment']);
        }
        
        $invoice_id = explode('_', $response['INVNUM'])[1];
        
        $cart = $this->getCheckoutData($recurring, $plan, $invoice_id);
        
        if ($recurring === true) {
            if ($plan->payment_plan == 'monthly') {
                $response = $this->provider->createMonthlySubscription($response['TOKEN'], $cart['total'], $cart['subscription_desc']);
            } else {
                $response = $this->provider->createYearlySubscription($response['TOKEN'], $cart['total'], $cart['subscription_desc']);
            }

            $status = 'Invalid';
            if (!empty($response['PROFILESTATUS']) && in_array($response['PROFILESTATUS'], ['ActiveProfile', 'PendingProfile'])) {
                $status = 'Processed';
            } 
        } else {
            // Perform transaction on PayPal
            $payment_status = $this->provider->doExpressCheckoutPayment($cart, $token, $PayerID);
            $status = $payment_status['PAYMENTINFO_0_PAYMENTSTATUS'];
        }
        
        $invoice = Invoice::find($invoice_id);
        $invoice->payment_status = $status;

        //if payment is recurring lets set a recurring id for later user
        if ($recurring === true) {
            $invoice->recurring_id = $response['PROFILEID'];
        }

        $invoice->save();

        if ($invoice->paid) {
            $user = auth()->user();
            $user->status = 'active';
            $user->plan_id = $plan->id;
            $user->payment_method = 'paypal';
            if ($user->trial_ends_at == NULL) {
                $user->trial_ends_at = Carbon::now()->addDays(7);
            }
            $user->save();
            return redirect()->route('home')->with(['code' => 'success', 'message' => 'Your plan subscribed successfully!']);
        } 
        
        return redirect()->route('home')->with(['code' => 'danger', 'message' => 'Error processing PayPal payment']);
    }

    public function cancel(Request $request) 
    {
        if (!($this->provider instanceof ExpressCheckout)) {
            $this->provider = new ExpressCheckout();
        }
        $response = $this->provider->cancelRecurringPaymentsProfile(Invoice::where('user_id', auth()->user()->id)->latest()->first()->recurring_id);
        if ($response['ACK'] == 'Success') {
            Invoice::where('recurring_id', $response['PROFILEID'])->update(['payment_status' => 'canceled']);
            auth()->user()->status = 'pending';
            auth()->user()->save();

            return view('dashboard.home');
        }
    }

    /**
     * Parse PayPal IPN.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function notify(Request $request)
    {
        if (!($this->provider instanceof ExpressCheckout)) {
            $this->provider = new ExpressCheckout();
        }
        $request->merge(['cmd' => '_notify-validate']);
        $post = $request->all();
        
        $response = (string) $this->provider->verifyIPN($post);
        $ipn = new IPNStatus();
        $ipn->payload = json_encode($post);
        $ipn->status = $response;
        $ipn->save();
        
        if ($response === 'VERIFIED') {
            if ($post['txn_type'] == 'recurring_payment' && $post['payment_status'] == 'Completed') {
                $invoice = Invoice::where('recurring_id', $post['recurring_payment_id'])->latest()->first();
                if ($invoice) {
                    $invoice->payment_status = 'Completed';
                    $invoice->save();
    
                    $transaction = new Transaction();
                    $transaction->invoice_id = $invoice->id;
                    $transaction->price = $post['amount'];
                    $transaction->payment_status = 'Completed';
                    $transaction->recurring_id = $invoice->recurring_id;
                    $transaction->transaction_id = $post['txn_id'];
                    $transaction->user_id = $invoice->user_id;
                    $transaction->save();
    
                    $user = User::find($invoice->user_id);
                    $user->status = 'active';
                    $user->save();
                }
            }

            if ($post['txn_type'] == 'recurring_payment_failed') {
                $invoice = Invoice::where('recurring_id', $post['recurring_payment_id'])->latest()->first();
                
                if ($invoice) {
                    $invoice->payment_status = 'Failed';
                    $invoice->save();
    
                    $transaction = new Transaction();
                    $transaction->invoice_id = $invoice->id;
                    $transaction->price = $post['amount'];
                    $transaction->payment_status = 'Failed';
                    $transaction->recurring_id = $invoice->recurring_id;
                    $transaction->transaction_id = $post['txn_id'];
                    $transaction->user_id = $invoice->user_id;
                    $transaction->save();
    
                    $user = User::find($invoice->user_id);
                    $user->status = 'pending';
                    $user->save();
                    //some code for de-activated email
                }
            }

            if ($post['txn_type'] == 'recurring_payment_profile_cancel') {
                $invoice = Invoice::where('recurring_id', $post['recurring_payment_id'])->latest()->first();
                
                if ($invoice) {
                    $invoice->payment_status = 'Canceled';
                    $invoice->save();
    
                    $user = User::find($invoice->user_id);
                    $user->status = 'pending';
                    $user->save();
                }
            }
        }
        
        return '';
    }
    /**
     * Set cart data for processing payment on PayPal.
     *
     * @param bool $recurring
     *
     * @return array
     */
    protected function getCheckoutData($recurring = false, Plan $plan, $invoice_id)
    {
        $data = [];

        if ($recurring === true) {
            $payment_plan = '';

            if ($plan->payment_plan == 'monthly') {
              $payment_plan = 'Monthly';
            } else {
              $payment_plan = 'Yearly';
            }

            $data['items'] = [
                [
                    'name'  => $payment_plan.' Subscription '.config('paypal.invoice_prefix').' #'.$invoice_id,
                    'price' => $plan->cost,
                    'qty'   => 1,
                ],
            ];
            $data['return_url'] = url('/dashboard/paypal/ec-checkout-success/'.$plan->slug.'?mode=recurring');
            $data['subscription_desc'] = $payment_plan.' Subscription '.config('paypal.invoice_prefix').' #'.$invoice_id;
        } else {
            $data['items'] = [
                [
                    'name'  => 'Product 1',
                    'price' => $plan->cost,
                    'qty'   => 1,
                ],
            ];
            $data['return_url'] = url('/dashboard/paypal/ec-checkout-success/'.$plan->slug);
        }
        $data['invoice_id'] = config('paypal.invoice_prefix').'_'.$invoice_id;
        $data['invoice_description'] = "Order #". $invoice_id ."Invoice";
        $data['cancel_url'] = url('/');
        $total = 0;
        foreach ($data['items'] as $item) {
            $total += $item['price'] * $item['qty'];
        }
        $data['total'] = $total;
        return $data;
    }
}
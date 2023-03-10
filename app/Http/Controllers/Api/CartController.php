<?php

namespace App\Http\Controllers\Api;

use App\Cart;
use App\CartProduct;
use App\Kit;
use App\Models\CartKitProduct;
use App\Utils\Api\ApiHelper;
use App\Utils\Api\ApiResponse;
use App\Utils\Constants\Messages;
use App\Utils\Constants\ValidationMessage;
use App\Utils\Constants\ValidationRule;
use App\Utils\Helpers\CartHelper;
use App\Utils\Helpers\Helper;
use Braintree\PaymentMethod;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends ApiBaseController
{
  /**
     * CartController constructor.
     */
    public function __construct() {
      parent::__construct();
    }

    /**
     * @param string $sessionID
     * @return JsonResponse
     */
    public function add(string $sessionID)
    {
        $cart = Cart::findBySessionID($sessionID);

        if (Helper::empty($cart)) {
            $cart = new Cart;
            $cart->session_id = $sessionID;
            if ($this->getUserID()) {
                $cart->user_id = $this->getUserID();
            }
            $cart->save();
        }

        try {

            if (request()->has("kit_id")) {/*if kit add to cart*/

                $kit = Kit::find(request("kit_id"));
                if (! is_null($kit)) {
                    Cart::addProductToCart($cart->id, $kit, 1);
                    $this->addKitProductsToCart($cart->id, $kit);
                    $response['cart'] = Cart::findCartById($cart->id);
                    $response['message'] = Messages::KIT_ADDED_TO_CART;
                    if (Helper::empty($response['cart'])) {
                        return ApiResponse::cart(422);
                    }
                    return ApiResponse::success($response);
                }
                return ApiResponse::failure(__('generic.kit.not_found'));
            }

            $product = $this->getProduct(request('sku', null));
            $quantity = request('quantity', 1);
            if ($product->is_in_stock) {
                $alreadyAddedQuantity = (int)Cart::findProducts($cart->id, $product->id)->sum('quantity') + $quantity;
                if ($alreadyAddedQuantity <= $product->qty) {

                    Cart::addProductToCart($cart->id, $product, $quantity);
                    $response['cart'] = Cart::findCartById($cart->id);
                    $response['message'] = Messages::PRODUCT_ADDED_TO_CART;
                    if (Helper::empty($response['cart'])) {
                        return ApiResponse::cart(422);
                    }
                    return ApiResponse::success($response);

                } else {
                    return ApiResponse::quantity();
                }
            } else {
                return ApiResponse::quantity();
            }

        } catch (Exception $e) {
            return ApiResponse::failure($e->getMessage());
        }
    }

    private function addKitProductsToCart($cart_id, $kit) {
        $exist = CartKitProduct::where(['cart_id' => $cart_id, 'kit_id' => $kit->id])->exists();
        if (!$exist) {
            if (isset($kit->products) && $kit->products->count() > 0) {
                foreach ($kit->products as $product) {
                    $kitProducts = new CartKitProduct();
                    $kitProducts->cart_id = $cart_id;
                    $kitProducts->kit_id = $kit->id;
                    $kitProducts->product_id = $product->id;
                    $kitProducts->name = $product->name;
                   // $kitProducts->price = $product->price;
                    $kitProducts->sku = $product->sku;
                    $kitProducts->quantity = $product->pivot->quantity;
                    $kitProducts->save();
                }
            }
        }

        return true;
    }

    /**
     * @param string $sessionID
     * @return JsonResponse
     */
    public function updateQty(string $sessionID)
    {
      $cart = Cart::findBySessionID($sessionID);
      if($cart) {
        $cartProduct = CartProduct::ofCart($cart->id)->findOrFail(request('cartProductID'));
        try {
          $quantity = request('quantity', 1);
          if($cartProduct->hydro_product_id) {
            $product = $this->findProductByID($cartProduct->hydro_product_id);
            if($product->is_in_stock) {
              $alreadyAddedQuantity = ((int)Cart::findProducts($cart->id, $product->id)->sum('quantity') - $cartProduct->quantity) + $quantity;
              if ($alreadyAddedQuantity <= $product->qty) {
                Cart::updateCartProductQty($cartProduct, $product, $quantity);

                $response['cart'] = Cart::findCartById($cart->id);
                $response['message'] = Messages::QTY_UPDATED;
                if (Helper::empty($response['cart'])) {
                  return ApiResponse::cart(422, Messages::CART_CLEARED);
                }
                return ApiResponse::success($response);
              } else {
                return ApiResponse::quantity();
              }
            } else {
              return ApiResponse::quantity();
            }
          } else if ($cartProduct->kit_id) {
            $kit = Kit::find($cartProduct->kit_id);
            if($kit) {
              Cart::updateCartProductQty($cartProduct, $kit, $quantity);
                $this->updateCartKitProductsQty($cart->id, $kit->id, $quantity);
              $response['cart'] = Cart::findCartById($cart->id);
              $response['message'] = Messages::QTY_UPDATED;
              if (Helper::empty($response['cart'])) {
                return ApiResponse::cart(422, Messages::CART_CLEARED);
              }
              return ApiResponse::success($response);
            } else {
              return ApiResponse::failure('kit not found');
            }
          }
        } catch (Exception $exception) {
          return ApiResponse::failure($exception->getMessage());
        }
      } else {
        return ApiResponse::cart(422);
      }
    }

    private function updateCartKitProductsQty($cartID, $kit_id, $quantity) {

        $kit = Kit::with('products')->find($kit_id);
        if ($kit->products->count() > 0) {
            foreach ($kit->products as $product) {

                CartKitProduct::where([
                    'cart_id' => $cartID,
                    'kit_id' => $kit_id,
                    'product_id' => $product->id
                ])->update([
                    'quantity' => $product->pivot->quantity * $quantity
                ]);
            }
        }
        return true;
    }

    /**
     * @param string $sessionID
     * @return JsonResponse
     */
    public function remove(string $sessionID)
    {
      $cart = Cart::findBySessionID($sessionID);
      if ($cart) {
        $cartProduct = CartProduct::ofCart($cart->id)->findOrFail(request('cartProductID'));
        try {
          Cart::removeCartProduct($cartProduct->id);

          $response['cart'] = Cart::findCartById($cart->id);
          $response['message'] = Messages::PRODUCT_REMOVED_FROM_CART;
          if (Helper::empty($response['cart'])) {
            return ApiResponse::cart(422, Messages::CART_CLEARED);
          }
          return ApiResponse::success($response);
        } catch (Exception $e) {
          return ApiResponse::failure($e->getMessage());
        }
      } else {
        return ApiResponse::cart(422);
      }
    }

    /**
     * @param string $sessionID
     * @return JsonResponse
     * @throws Exception
     */
    public function clear(string $sessionID)
    {
      $cart = Cart::findBySessionID($sessionID);

      if ($cart) {
        Cart::clear($cart->id);

        return ApiResponse::success(Messages::CART_CLEARED);
      } else {
        return ApiResponse::cart(422);
      }
    }

  /**
   * @param Request $request
   * @param string $sessionID
   * @return JsonResponse
   * @throws Exception
   */
  public function updateContactInformation(Request $request, string $sessionID)
  {
    $rules = ValidationRule::CartContactInfo();

    $messages = ValidationMessage::messages();

    try {
      $this->validate($request, $rules, $messages);
    } catch (ValidationException $e) {
      return ApiResponse::validation($e->errors());
    }

    $cart = Cart::findBySessionID($sessionID);

    if($cart){
      $cart->user_id = $this->getUserID() == 0 ? null : $this->getUserID();
      $cart->contact_information_first_name = request('firstName');
      $cart->contact_information_last_name = request('lastName');
      $cart->contact_information_email = request('email');
      $cart->contact_information_phone = request('phone');
      $cart->save();
      $response['message'] = Messages::CART_CONTACT_INFO_UPDATED;
      $response['cart'] = Cart::findCartById($cart->id);
      if (Helper::empty($response['cart'])) {
        return ApiResponse::cart(422, Messages::CART_CLEARED);
      }
      return ApiResponse::success($response);
    }else{
      return ApiResponse::cart(422);
    }
  }

  /**
   * @param Request $request
   * @param $sessionID
   * @return JsonResponse
   * @throws Exception
   */
  public function updateShippingBillingInfo(Request $request, $sessionID)
  {
    $cart = Cart::findBySessionID($sessionID);

    if($cart){
      $response['cart'] = Cart::findCartById($cart->id);

      $rules = ValidationRule::CartShippingBillingInfo();
      $messages = ValidationMessage::messages(ValidationMessage::CartBillingShipping());

      try {
        $this->validate($request, $rules, $messages);
      } catch (ValidationException $e) {
        $response['message'] = Helper::resolveValidationError($e->errors());
        return ApiResponse::failure($response, 420);
      }


      $cart->user_id = $this->getUserID() == 0 ? null : $this->getUserID();
      $cart->billing_address_first_name = request('billingFirstName');
      $cart->billing_address_last_name = request('billingLastName');
      $cart->billing_address_address1 = request('billingAddress1');
      $cart->billing_address_address2 = request('billingAddress2');
      $cart->billing_address_state = request('billingState');
      $cart->billing_address_state_type = request('billingStateType');
      $cart->billing_address_city = request('billingCity');
      $cart->billing_address_zip = request('billingZip');
      $cart->billing_address_phone = request('billingPhone');
      $cart->billing_address_email = request('billingEmail');
      $cart->is_different_billing = request('isDifferentBilling', false);
      // echo $cart -> billing_address_state;
      if($cart->is_different_billing) {
        $cart->shipping_address_first_name = request('shippingFirstName');
        $cart->shipping_address_last_name = request('shippingLastName');
        $cart->shipping_address_address1 = request('shippingAddress1');
        $cart->shipping_address_address2 = request('shippingAddress2');
        $cart->shipping_address_state = request('shippingState');
        $cart->shipping_address_state_type = request('shippingStateType');
        $cart->shipping_address_city = request('shippingCity');
        $cart->shipping_address_zip = request('shippingZip');
        $cart->shipping_address_phone = request('shippingPhone');
        $cart->shipping_address_email = request('shippingEmail');
        // echo $cart -> shipping_address_state;
      } else {
        Cart::resetShipping($cart);
      }
      if($cart->is_different_billing){
        //  echo $cart->shipping_address_state;
        switch ($cart->shipping_address_state) {
          case 'AL':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AK':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AZ':
            // $cart->tax = 5.6;
            $tax1 = 5.6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AR':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CA':
            // $cart->tax = 7.25;
            $tax1 = 7.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CO':
            // $cart->tax = 2.9;
            $tax1 = 2.9;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CT':
            // $cart->tax = 6.35;
            $tax1 = 6.35;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'DE':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'DC':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'FL':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'GA':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'HI':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'ID':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IL':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IN':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IA':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'KS':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'KY':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'LA':
            // $cart->tax = 4.45;
            $tax1 = 4.45;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'ME':
            // $cart->tax = 5.5;
            $tax1 = 5.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MD':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MA':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MI':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MN':
            // $cart->tax = 6.88;
            $tax1 = 6.88;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MS':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MO':
            // $cart->tax = 4.23;
            $tax1 = 4.23;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'MT':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NE':
            // $cart->tax = 5.5;
            $tax1 = 5.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NV':
            // $cart->tax = 6.85;
            $tax1 = 6.85;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NH':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NJ':
            // $cart->tax = 6.63;
            $tax1 = 6.63;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NM':
            // $cart->tax = 5.13;
            $tax1 = 5.13;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NY':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NC':
            // $cart->tax = 4.75;
            $tax1 = 4.75;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'ND':
            // $cart->tax = 5;
            $tax1 = 5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OH':
            // $cart->tax = 5.75;
            $tax1 = 5.75;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OK':
            // $cart->tax = 4.5;
            $tax1 = 4.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OR':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'PA':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'RI':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'SC':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'SD':
            // $cart->tax = 4.5;
            $tax1 = 4.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'TN':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'TX':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'UT':
            // $cart->tax = 5.95;
            $tax1 = 5.95;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'VT':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'VA':
            // $cart->tax = 5.3;
            $tax1 = 5.3;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WA':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
          break;
          case 'WV':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WI':
            // $cart->tax = 5;
            $tax1 = 5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WY':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
        }
        // echo $tax1;
        // echo $cart->total_price;
      }
      else{
        // echo $cart->total_price;
        // echo $cart->billing_address_state;
        switch ($cart->billing_address_state) {
          case 'AL':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AK':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AZ':
            // $cart->tax = 5.6;
            $tax1 = 5.6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'AR':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CA':
            // $cart->tax = 7.25;
            $tax1 = 7.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CO':
            // $cart->tax = 2.9;
            $tax1 = 2.9;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'CT':
            // $cart->tax = 6.35;
            $tax1 = 6.35;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'DE':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'DC':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'FL':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'GA':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'HI':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'ID':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IL':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IN':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'IA':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'KS':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'KY':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'LA':
            // $cart->tax = 4.45;
            $tax1 = 4.45;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'ME':
            // $cart->tax = 5.5;
            $tax1 = 5.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MD':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MA':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MI':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MN':
            // $cart->tax = 6.88;
            $tax1 = 6.88;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MS':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'MO':
            // $cart->tax = 4.23;
            $tax1 = 4.23;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'MT':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NE':
            // $cart->tax = 5.5;
            $tax1 = 5.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NV':
            // $cart->tax = 6.85;
            $tax1 = 6.85;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NH':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NJ':
            // $cart->tax = 6.63;
            $tax1 = 6.63;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NM':
            // $cart->tax = 5.13;
            $tax1 = 5.13;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NY':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'NC':
            // $cart->tax = 4.75;
            $tax1 = 4.75;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'ND':
            // $cart->tax = 5;
            $tax1 = 5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OH':
            // $cart->tax = 5.75;
            $tax1 = 5.75;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OK':
            // $cart->tax = 4.5;
            $tax1 = 4.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'OR':
            // $cart->tax = 0;
            $tax1 = 0;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'PA':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;

          case 'RI':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'SC':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'SD':
            // $cart->tax = 4.5;
            $tax1 = 4.5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'TN':
            // $cart->tax = 7;
            $tax1 = 7;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'TX':
            // $cart->tax = 6.25;
            $tax1 = 6.25;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'UT':
            // $cart->tax = 5.95;
            $tax1 = 5.95;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'VT':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'VA':
            // $cart->tax = 5.3;
            $tax1 = 5.3;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WA':
            // $cart->tax = 6.5;
            $tax1 = 6.5;
          break;
          case 'WV':
            // $cart->tax = 6;
            $tax1 = 6;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WI':
            // $cart->tax = 5;
            $tax1 = 5;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
          case 'WY':
            // $cart->tax = 4;
            $tax1 = 4;
            $temp_price = $cart->total_price;
            $total_tax = $temp_price * $tax1/100;
            $cart->tax = $total_tax;
            $response['tax_value'] = $tax1."%";
          break;
        }
      }
      if ($cart->is_different_billing) {
        $state = $cart->shipping_address_state;
      } else {
        $state = $cart->billing_address_state;
      }
      $restrictions = Helper::verifyRestrictions($cart, $state);
      if (count($restrictions['restrictedProducts']) || count($restrictions['restrictedKits'])) {
        return ApiResponse::failure(['restricted_products' => $restrictions['restrictedProducts'], 'restricted_kits' => $restrictions['restrictedKits']], 424);
      }
      
      
      $cart->save();
      $response['cart']    = Cart::findCartById($cart->id);

      $response['message'] = Messages::CART_SHIPPING_BILLING_INFO_UPDATED;
      if (Helper::empty($response['cart'])) {
        return ApiResponse::cart(422, Messages::CART_CLEARED);
      }
      return ApiResponse::success($response);
    }else{
      return ApiResponse::cart(422);
    }
  }
    public function attachPaymentNonce(string $sessionID)
    {
        $cart = Cart::findBySessionID($sessionID);
        if ($cart) {
            $messages =  CartHelper::verifyProductsAvailableQuantity($cart);
            if (count($messages)) {
                return response()->json(['messages' => $messages], 420);
            }
            try {
                $paymentMethod = new \stdClass();
                if (request('type') == 'SavedCard') {
                    $paymentMethod = PaymentMethod::find(request('paymentToken'));
                    if ($cart->user_id != (integer)$paymentMethod->customerId) {
                        $response['message'] = 'Payment Method Not found!';
                        $code                = 402;
                        return response()->json($response, $code);
                    }
                }
                $cart = Cart::find($cart->id);
                if ($this->getUserID()) {
                    $cart->user_id = $this->getUserID();
                }
                $cart->payment_nonce              = request('nonce');
                $cart->card_holder_name           = request('cardHolderName', optional($paymentMethod)->cardholderName);
                $cart->expiration_month           = request('expirationMonth', optional($paymentMethod)->expirationMonth);
                $cart->expiration_year            = request('expirationYear', optional($paymentMethod)->expirationYear);
                $cart->bin                        = request('bin', optional($paymentMethod)->bin);
                $cart->card_type                  = request('cardType', optional($paymentMethod)->cardType);
                $cart->type                       = request('type', null);
                $cart->saved_payment_method_token = request('paymentToken', null);
                $cart->last_four                  = request('lastFour', optional($paymentMethod)->last4);
                $cart->last_two                   = request('lastTwo', null);
                $cart->description                = request('description', null);
                $cart->email                      = request('email', null);
                $cart->first_name                 = request('firstName', null);
                $cart->last_name                  = request('lastName', null);
                $cart->payer_id                   = request('payerId', null);
                $cart->country_code               = request('countryCode', null);
                $cart->save();
                $response['cart'] = ApiHelper::findCartById($cart->id);
                $response['message'] = 'Nonce Linked';
                $code                = 200;
            }catch (\Exception $e) {
                if($e instanceof \Braintree\Exception\NotFound){
                    $response['message'] = 'Payment Method Not found!';
                    $code                = 402;
                } else {
                    $response['message'] = $e->getMessage();
                    $code                = 402;
                }
            }
            return response()->json($response, $code);
        } else {
            return ApiResponse::cart(422);
        }
    }

    /**
     * @param string $sessionID
     * @param string $state
     * @return JsonResponse
     */
    public function verifyShipping(string $sessionID, string $state)
    {
      $cart = Cart::findBySessionID($sessionID);

      if($cart) {
        $restrictedProducts = Helper::verifyRestrictions($cart, $state);
        return ApiResponse::success(['restricted_products' => $restrictedProducts]);
      } else {
        return ApiResponse::cart(422);
      }
    }
}

<?php

namespace App\Http\Controllers\Voyager;

use App\Gx\GrowLog;
use App\Gx\GrowLogDetail;
use App\Gx\GrowLogFeedback;
use App\Gx\Ticket;
use App\Gx\TicketMessage;
use App\Hydro\HydroProduct;
use App\Kit;
use App\Models\UserKit;
use App\Models\UserSubscription;
use App\Role;
use App\User;
use App\Utils\Constants\Constant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use App\Utils\Helpers\Helper;
use Illuminate\Support\Facades\DB;
use TCG\Voyager\Events\BreadDataAdded;
use TCG\Voyager\Events\BreadDataUpdated;
use TCG\Voyager\Facades\Voyager;
use Illuminate\Support\Facades\Auth;

class VoyagerKitController extends VoyagerController
{
    public function edit(Request $request, $id)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        if (strlen($dataType->model_name) != 0) {
            $model = app($dataType->model_name);

            // Use withTrashed() if model uses SoftDeletes and if toggle is selected
            if ($model && in_array(SoftDeletes::class, class_uses_recursive($model))) {
                $model = $model->withTrashed();
            }
            if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope' . ucfirst($dataType->scope))) {
                $model = $model->{$dataType->scope}();
            }

            $dataTypeContent = call_user_func([$model, 'findOrFail'], $id);
        } else {
            // If Model doest exist, get data from table name
            $dataTypeContent = DB::table($dataType->name)->where('id', $id)->first();
        }

        foreach ($dataType->editRows as $key => $row) {
            $dataType->editRows[$key]['col_width'] = isset($row->details->width) ? $row->details->width : 100;
        }

        // If a column has a relationship associated with it, we do not want to show that field
        $this->removeRelationshipField($dataType, 'edit');

        // Check permission
        $this->authorize('edit', $dataTypeContent);
        if ($dataTypeContent instanceof User) {
            Helper::checkUpdateUserPermissions();
        }

        // Check if BREAD is Translatable
        $isModelTranslatable = is_bread_translatable($dataTypeContent);

        // Eagerload Relations
        $this->eagerLoadRelations($dataTypeContent, $dataType, 'edit', $isModelTranslatable);

        $view = 'voyager::kits.edit-add';

        if (view()->exists("voyager::$slug.edit-add")) {
            $view = "voyager::$slug.edit-add";
        }

        $products = collect();
        $kit = Kit::with('products')->find($id);
        if (! is_null($kit)) {
            $products = $kit->products;
        }

        return Voyager::view($view, compact('dataType', 'dataTypeContent', 'isModelTranslatable', 'products'));
    }

    public function update(Request $request, $id)
    {

        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Compatibility with Model binding.
        $id = $id instanceof \Illuminate\Database\Eloquent\Model ? $id->{$id->getKeyName()} : $id;

        $model = app($dataType->model_name);
        if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope' . ucfirst($dataType->scope))) {
            $model = $model->{$dataType->scope}();
        }

        if ($model && in_array(SoftDeletes::class, class_uses_recursive($model))) {
            $data = $model->withTrashed()->findOrFail($id);
        } else {
            $data = $model->findOrFail($id);
        }

        // Check permission
        $this->authorize('edit', $data);

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->editRows, $dataType->name, $id)->validate();
        $this->insertUpdateData($request, $slug, $dataType->editRows, $data);
        $this->saveKitProducts($request, $id, true);

        event(new BreadDataUpdated($dataType, $data));

        if (auth()->user()->can('browse', app($dataType->model_name))) {
            if (request()->has('source')) {
                $redirect = redirect('/admin/' . request('source'));
            } else {
                $redirect = redirect()->route("voyager.{$dataType->slug}.index");
            }
        } else {
            $redirect = redirect()->back();
        }

        return $redirect->with([
            'message' => __('voyager::generic.successfully_updated') . " {$dataType->getTranslatedAttribute('display_name_singular')}",
            'alert-type' => 'success',
        ]);
    }

    public function store(Request $request)
    {
        $slug = $this->getSlug($request);
        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('add', app($dataType->model_name));

        // Validate fields with ajax
        if (app($dataType->model_name) instanceof User) {
            Helper::checkCreateUserPermissions();
        }
        if (request('source') === 'admins') {
            if (in_array(request('role_id'), Role::adminRoles())) {
                $request->merge(['provider' => 'gx']);
            }
        }

        $val = $this->validateBread($request->all(), $dataType->addRows)->validate();
        $data = $this->insertUpdateData($request, $slug, $dataType->addRows, new $dataType->model_name());
        $this->saveKitProducts($request, $data->id);

        event(new BreadDataAdded($dataType, $data));

        if (!$request->has('_tagging')) {
            if (auth()->user()->can('browse', $data)) {
                if (request()->has('source')) {
                    $redirect = redirect('/admin/' . request('source'));
                } else {
                    $redirect = redirect()->route("voyager.{$dataType->slug}.index");
                }
            } else {
                $redirect = redirect()->back();
            }

            return $redirect->with([
                'message' => __('voyager::generic.successfully_added_new') . " {$dataType->getTranslatedAttribute('display_name_singular')}",
                'alert-type' => 'success',
            ]);
        } else {
            return response()->json(['success' => true, 'data' => $data]);
        }
    }

    private function saveKitProducts(Request $request, $id, $update = false) {

        if (count($request->products)) {
            if ($update) {
                DB::table('kit_products')->where('kit_id', $id)->delete();
            }
            $quantity = $request->quantity;
            foreach ($request->products as $key => $product) {
                DB::table('kit_products')->insert([
                    'kit_id' => $id,
                    'hydro_product_id' => $product,
                    'quantity' => $quantity[$key] ?? 0,
                ]);
            }
        }
    }

}
